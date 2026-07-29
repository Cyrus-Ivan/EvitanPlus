<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\InventoryImportRequest;
use App\Models\ImportHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\CsvInventoryImporter;
use App\Support\Inventory\InventoryCsvSchema;
use App\Support\Sync\ConnectivityProbe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory CSV import (Phase 7). Admin+ (route middleware). The page shows the
 * Upload History and hosts the template download, the upload form, and a link
 * to the export. Item Cost is Owner-only, so the cost column is only honoured
 * on import (and included in the template) for the Owner.
 *
 * ## Online-only since M6 (MULTIBRANCH-BRAINSTORM.md §17)
 *
 * An import writes `type = 'stock_adjustment'` ledger rows, and those are
 * online-authoritative: they are pulled DOWN, never pushed up, so the outbox
 * does not carry them. A branch importing while offline would therefore create
 * stock movements that could never reach Postgres, and its stock would diverge
 * from the business's permanently and silently — the one failure this whole
 * design exists to prevent.
 *
 * Pushing them instead was considered and rejected: it would give
 * `stock_adjustment` rows two writers, and single-writer-per-table is the reason
 * there is no last-write-wins anywhere in this system. So the operation is
 * refused offline, which also makes the branch role's SELECT-but-no-INSERT on
 * `import_history` correct rather than incidental.
 */
class InventoryImportController extends Controller
{
    public function __construct(private readonly ConnectivityProbe $probe) {}

    public function index(): Response
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
                'error_count' => $entry->error_count,
                'errors' => $entry->errors,
                'created_at' => $entry->created_at,
                'user' => $entry->user?->name ?? $entry->user?->username,
            ]);

        return Inertia::render('inventory/import', [
            'history' => $history,
            'online' => $this->probe->online(),
        ]);
    }

    public function store(InventoryImportRequest $request, CsvInventoryImporter $importer, AuditRecorder $audit): RedirectResponse
    {
        // Probed again rather than trusting the page's answer: the render may
        // have been minutes ago, and this is the moment the unsyncable rows
        // would be written.
        if (! $this->probe->online()) {
            throw ValidationException::withMessages([
                'file' => 'CSV import needs a connection to the business database. '
                    .'Importing offline would create stock movements that could never be synced. '
                    .'Reconnect and try again.',
            ]);
        }

        $file = $request->file('file');

        $result = $importer->import(
            path: $file->getRealPath(),
            filename: $file->getClientOriginalName(),
            user: $request->user(),
            canViewCost: Gate::allows('view-item-cost'),
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
            'message' => 'Import failed — no changes were saved. See the upload history below for the row-by-row errors.',
        ]);
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
        }, 'inventory-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
