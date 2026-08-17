<?php

namespace App\Http\Controllers;

use App\Exceptions\SetupFailedException;
use App\Http\Requests\Setup\ImportCredentialsRequest;
use App\Support\Printing\PrinterSettings;
use App\Support\Setup\CredentialsFilePicker;
use App\Support\Sync\ConnectAndHydrate;
use App\Support\Sync\CredentialsDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The setup wizard: connect this device to its business and hydrate it
 * (MULTIBRANCH-BRAINSTORM.md §16).
 *
 * It replaces v1.0.0's create-the-Owner-locally flow outright. The Owner, the
 * business identity and the catalog already exist in Postgres — provisioned by
 * the developer-only console — so a device's job at setup is to prove it holds a
 * working branch credential and to copy the baseline down, not to invent a
 * second business. The only thing still collected here is the printer, because
 * a printer belongs to the device.
 *
 * RequireInitialSetup routes every request here until `instance.hydrated_at` is
 * set, and locks the wizard away once it is.
 *
 * One atomic submit, and nothing survives a failure — see ConnectAndHydrate.
 */
class SetupController extends Controller
{
    public function create(PrinterSettings $printerSettings): Response
    {
        return Inertia::render('setup', [
            'printerDefaults' => $printerSettings->all(),
            'appVersion' => (string) config('nativephp.version'),
            // The native file dialog only exists inside the desktop runtime; in
            // a plain browser the developer types the path instead.
            'desktop' => (bool) config('nativephp-internal.running'),
        ]);
    }

    /**
     * Open the OS file picker and hand the chosen path back to the form.
     *
     * A POST because it has a side effect the user sees (a modal window).
     */
    public function browse(CredentialsFilePicker $picker): RedirectResponse
    {
        try {
            $path = $picker->pick();
        } catch (Throwable $e) {
            return back()->withErrors(['credentials_path' => $e->getMessage()]);
        }

        // Cancelled: not an error, and not something to say anything about.
        return $path === null
            ? back()
            : back()->with('picked_credentials_path', $path);
    }

    public function store(
        ImportCredentialsRequest $request,
        ConnectAndHydrate $setUpDevice,
        PrinterSettings $printerSettings,
    ): RedirectResponse {
        $path = $request->credentialsPath();

        try {
            $document = CredentialsDocument::fromFile($path);

            $summary = DB::transaction(function () use ($setUpDevice, $document, $request, $printerSettings): array {
                $summary = $setUpDevice($document);

                // Inside the same transaction as the hydrate: a device that ends
                // up hydrated without the printer settings the developer just
                // entered would send them looking in the settings screen for a
                // step they know they completed.
                if ($request->boolean('configure_printer')) {
                    $printerSettings->update($request->printerPayload());
                }

                return $summary;
            });
        } catch (SetupFailedException $e) {
            // Every one of these leaves the device untouched, so the message is
            // the whole remedy and it belongs on the field that caused it.
            return back()->withErrors(['credentials_path' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Anything the hydrate itself threw. `Hydrator::copyTable()` writes
            // through the query builder, so a local UNIQUE, FK or NOT NULL
            // violation arrives as a `QueryException` rather than the app's own
            // typed failure — and used to escape to a blank error page. The
            // wizard's own docblock names this: a device that already carries
            // rows, a schema that has drifted, a connection lost mid-copy.
            //
            // Atomicity was never the problem: the outer transaction rolls the
            // whole thing back either way. What was missing is somewhere for the
            // person standing at the till to read what happened, so this stays on
            // the same field with the same shape as every other refusal — and is
            // reported, because unlike the cases above it means something is
            // wrong rather than mis-entered.
            report($e);

            return back()->withErrors([
                'credentials_path' => 'Setting this device up failed partway through, and nothing was saved. '
                    .'Check that this is a fresh install and that the connection held, then try again. ('
                    .$e->getMessage().')',
            ]);
        }

        $removed = $request->boolean('delete_after_import') && $this->removeCredentialsFile($path);

        return redirect()
            ->route('login')
            ->with('toast', [
                'type' => 'success',
                'message' => "This device is now branch {$summary['branch_code']} of {$summary['business']}. "
                    .($removed
                        ? 'The credentials file has been deleted.'
                        : "Delete the credentials file at {$path} — it holds a live database password."),
            ]);
    }

    /**
     * §9 treats the JSON as one-time: it carries a live database password in
     * plaintext, and once it has been imported the device holds the same
     * credential sealed under its own key. Best effort — a file on a read-only
     * stick is not a failed setup, and the toast says so either way.
     */
    private function removeCredentialsFile(string $path): bool
    {
        return is_file($path) && @unlink($path);
    }
}
