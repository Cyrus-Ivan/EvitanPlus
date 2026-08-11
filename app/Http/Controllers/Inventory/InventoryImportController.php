<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\InventoryImportRequest;
use App\Models\ImportHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\CsvInventoryImporter;
use App\Support\Inventory\InventoryCsvSchema;
use App\Support\Sync\ConnectivityProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Item CSV import (Phase 7, reworked in M13). Admin+ (route middleware). Item
 * Cost is Owner-only, so the cost column is only honoured on import (and
 * included in the template) for the Owner.
 *
 * ## There is no page any more, and no export
 *
 * This was `/inventory/import`: a screen with an upload form, a template link,
 * an export link and the upload history. The upload half is now a modal on the
 * Items page, so the history came with it — fetched by {@see history()} when the
 * modal's second tab opens rather than shipped as a prop, for the same reason
 * `stock-updates.branches` is a fetch: the Items page must not pay for it on
 * every render.
 *
 * The export is gone entirely. It existed to round-trip stock counts through a
 * spreadsheet, and stock left the catalog in M13.
 *
 * ## Two posts, and the second is the one that writes
 *
 * {@see preview()} parses and validates the file and stops, so the modal can name
 * the existing products the file would change before anything happens.
 * {@see store()} does it for real, and re-parses: the file is uploaded twice
 * rather than parked on the server between the two, which keeps the apply
 * all-or-nothing over the file it is actually applying and leaves no temp-file
 * lifecycle to clean up.
 *
 * The confirmation the modal collects is enforced inside
 * {@see CsvInventoryImporter}, not here — a hand-built request with no
 * `confirm_updates` is refused exactly the same way.
 *
 * ## Online-only since M6 (MULTIBRANCH-BRAINSTORM.md §17)
 *
 * An import writes shared catalog — categories, items and variants every branch
 * sells from — as an ONLINE WRITE. Written locally only, none of it would ever
 * reach the business database, and this device's catalog would diverge from the
 * business's silently until the next pull overwrote it. So the operation is
 * refused offline, which also makes the branch role's SELECT-but-no-INSERT on
 * `import_history` correct rather than incidental.
 */
class InventoryImportController extends Controller
{
    public function __construct(private readonly ConnectivityProbe $probe) {}

    /**
     * What applying this file would do, without applying any of it.
     *
     * Deliberately NOT gated on connectivity: previewing writes nothing and
     * reads only the local catalog mirror, so an operator with a flaky
     * connection can still find out what their file contains. The refusal
     * belongs on {@see store()}, which is where something would be written.
     */
    public function preview(InventoryImportRequest $request, CsvInventoryImporter $importer): JsonResponse
    {
        return response()->json(
            $importer->preview(
                path: $request->file('file')->getRealPath(),
                canViewCost: Gate::allows('view-item-cost'),
            ),
        );
    }

    public function store(InventoryImportRequest $request, CsvInventoryImporter $importer, AuditRecorder $audit): RedirectResponse
    {
        // Probed again rather than trusting the page's answer: the render may
        // have been minutes ago, and this is the moment the catalog rows would
        // be written.
        if (! $this->probe->online()) {
            throw ValidationException::withMessages([
                'file' => 'CSV import needs a connection to the business database. '
                    .'Importing offline would create products that could never be synced. '
                    .'Reconnect and try again.',
            ]);
        }

        $file = $request->file('file');

        $result = $importer->import(
            path: $file->getRealPath(),
            filename: $file->getClientOriginalName(),
            user: $request->user(),
            canViewCost: Gate::allows('view-item-cost'),
            confirmUpdates: $request->boolean('confirm_updates'),
        );

        $audit->record(
            action: 'inventory.imported',
            auditable: $result,
            description: sprintf(
                'CSV import "%s" %s: %d of %d row(s) applied.',
                $result->filename ?? 'upload',
                $result->status,
                $result->success_count,
                $result->total_rows,
            ),
            new: [
                'filename' => $result->filename,
                'mode' => $result->mode,
                'status' => $result->status,
                'total_rows' => $result->total_rows,
                'success_count' => $result->success_count,
                'error_count' => $result->error_count,
            ],
        );

        if ($result->status === 'success') {
            return back()->with('toast', [
                'type' => 'success',
                'message' => 'Imported '.$result->success_count.' row(s).',
            ]);
        }

        return back()->with('toast', [
            'type' => 'error',
            'message' => 'Import failed — no changes were saved. See Recent imports for the row-by-row errors.',
        ]);
    }

    /**
     * Past imports, for the modal's Recent imports tab.
     */
    public function history(): JsonResponse
    {
        $history = ImportHistory::query()
            ->with('user:id,name,username')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (ImportHistory $entry) => [
                'id' => $entry->id,
                'filename' => $entry->filename,
                'mode' => $entry->mode,
                'status' => $entry->status,
                'total_rows' => $entry->total_rows,
                'success_count' => $entry->success_count,
                'errors' => $entry->errors,
                'created_at' => $entry->created_at,
                'user' => $entry->user?->name ?? $entry->user?->username,
            ]);

        return response()->json(['history' => $history]);
    }

    /**
     * Stream the blank CSV template (headers + one example row). Delivered as a
     * file download, so it is reached via a plain link, not an Inertia visit.
     */
    public function template(): StreamedResponse
    {
        $headers = InventoryCsvSchema::headers(Gate::allows('view-item-cost'));
        $example = InventoryCsvSchema::exampleRow();

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, escape: '');
            fputcsv($out, array_map(fn (string $column) => $example[$column] ?? '', $headers), escape: '');
            fclose($out);
        }, 'items-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
