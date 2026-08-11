<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\OpeningStockImportRequest;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\OpeningStockCsvSchema;
use App\Support\Inventory\OpeningStockImporter;
use App\Support\Inventory\OpeningWindow;
use App\Support\Sync\ConnectivityProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opening stock import — the bulk path onto a shelf that has never been sold
 * from ({@see OpeningWindow}).
 *
 * Deliberately a sibling of {@see InventoryImportController} rather than another
 * action on it: that one writes the CATALOG, which is the same at every branch
 * and always writable, and this one writes THIS BRANCH'S STOCK, which is not and
 * is only writable for a while. Same two-post shape, same connectivity refusal,
 * same reasons — see that class for both.
 *
 * The history the two produce is shared: an opening-stock import writes an
 * `import_history` row like any other, with `mode = 'opening_stock'`, and the
 * Recent imports tab lists them together. What happened to this device's data,
 * in one list, is more useful than two lists split by which file did it.
 */
class OpeningStockController extends Controller
{
    public function __construct(private readonly ConnectivityProbe $probe) {}

    /**
     * What applying this file would do, without applying any of it.
     *
     * Not gated on connectivity, for the same reason the item preview is not:
     * it writes nothing and reads only the local catalog and snapshots, so an
     * operator on a flaky connection can still find out what their file says.
     */
    public function preview(OpeningStockImportRequest $request, OpeningStockImporter $importer): JsonResponse
    {
        return response()->json(
            $importer->preview($request->file('file')->getRealPath()),
        );
    }

    public function store(OpeningStockImportRequest $request, OpeningStockImporter $importer, AuditRecorder $audit): RedirectResponse
    {
        // Probed again rather than trusting the page's answer: the render may
        // have been minutes ago, and this is the moment stock would move.
        if (! $this->probe->online()) {
            throw ValidationException::withMessages([
                'file' => 'Importing opening stock needs a connection to the business database. '
                    .'Stock written offline would be reverted by the next sync. Reconnect and try again.',
            ]);
        }

        $file = $request->file('file');

        $result = $importer->import(
            path: $file->getRealPath(),
            filename: $file->getClientOriginalName(),
            user: $request->user(),
        );

        $audit->record(
            action: 'inventory.opening_stock.imported',
            auditable: $result,
            description: sprintf(
                'Opening stock import "%s" %s: %d of %d row(s) applied.',
                $result->filename ?? 'upload',
                $result->status,
                $result->success_count,
                $result->total_rows,
            ),
            new: [
                'import_id' => $result->id,
                'filename' => $result->filename,
                'status' => $result->status,
                'total_rows' => $result->total_rows,
                'success_count' => $result->success_count,
                'error_count' => $result->error_count,
            ],
        );

        if ($result->status === 'success') {
            return back()->with('toast', [
                'type' => 'success',
                'message' => 'Opening stock set from '.$result->success_count.' row(s).',
            ]);
        }

        return back()->with('toast', [
            'type' => 'error',
            'message' => 'Import failed — no stock was changed. See Recent imports for the row-by-row errors.',
        ]);
    }

    /**
     * Stream the blank template (headers + one example row). A plain download,
     * so it is reached by a link rather than an Inertia visit.
     */
    public function template(): StreamedResponse
    {
        $headers = OpeningStockCsvSchema::headers();
        $example = OpeningStockCsvSchema::exampleRow();

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, escape: '');
            fputcsv($out, array_map(fn (string $column) => $example[$column] ?? '', $headers), escape: '');
            fclose($out);
        }, 'opening-stock-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
