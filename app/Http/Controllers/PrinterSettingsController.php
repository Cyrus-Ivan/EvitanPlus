<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrinterSettingsUpdateRequest;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SettingsDiff;
use App\Support\Printing\PrinterSettings;
use App\Support\Printing\ReceiptPrinter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Native\Laravel\Facades\System;

/**
 * Receipt-printer configuration (Phase 11). Owner-only via the route's
 * role:owner middleware; its own section of the business-settings area, at
 * `/business-settings/printing`.
 */
class PrinterSettingsController extends Controller
{
    public function __construct(private readonly PrinterSettings $settings) {}

    /**
     * Show the receipt-printer section.
     *
     * No `online` prop, unlike {@see BusinessSettingsController::edit()}: the
     * printer is device hardware, has no upstream row, and is configurable with
     * no connection at all — so this section must not pay for a probe.
     */
    public function edit(): Response
    {
        return Inertia::render('business-settings/printing', [
            'printerSettings' => $this->settings->all(),
        ]);
    }

    /**
     * Persist the receipt-printer settings.
     */
    public function update(PrinterSettingsUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $before = $this->settings->all();
        $this->settings->update($request->settingsPayload());
        $diff = SettingsDiff::changed($before, $this->settings->all());

        if ($diff !== null) {
            $audit->record(
                action: 'settings.updated',
                description: 'Updated printer settings: '.implode(', ', array_keys($diff['new'])).'.',
                old: $diff['old'],
                new: $diff['new'],
            );
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Printer settings saved.',
        ]);
    }

    /**
     * List the OS print queues so the owner can pick one for a `system`
     * connection. Only works inside the NativePHP runtime (the Electron bridge
     * backs {@see System::printers()}); under `artisan serve` there is no bridge,
     * so this returns an empty list and the UI falls back to manual entry.
     */
    public function printers(): JsonResponse
    {
        try {
            $printers = collect(System::printers())
                ->map(fn ($printer): array => [
                    'name' => (string) $printer->name,
                    'label' => (string) ($printer->displayName ?: $printer->name),
                ])
                ->filter(fn (array $p): bool => $p['name'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);
            $printers = [];
        }

        return response()->json(['printers' => $printers]);
    }

    /**
     * Print a test slip to the configured printer so the owner can confirm the
     * hardware (and the cash drawer) works.
     */
    public function test(ReceiptPrinter $printer): RedirectResponse
    {
        try {
            $printed = $printer->test();
        } catch (\Throwable $e) {
            // `\Throwable` for the reason given on `SalesController::print()`: a
            // stalled `system` spooler raises `ProcessTimedOutException`, not the
            // app's own typed exception, and a test print exists precisely to
            // report a printer that is not working. A 500 here is the screen
            // failing at its one job.
            report($e);

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $printed) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'No receipt printer is configured. Set one up above and save first.',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Test receipt sent to the printer.',
        ]);
    }
}
