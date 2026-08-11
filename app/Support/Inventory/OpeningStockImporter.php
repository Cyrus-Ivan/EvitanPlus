<?php

namespace App\Support\Inventory;

use App\Exceptions\ImportRowException;
use App\Exceptions\NegativeInventoryException;
use App\Models\ImportHistory;
use App\Models\Instance;
use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\RemoteStock;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * OPENING STOCK: the one bulk path into a branch's shelf, open only while that
 * branch has never sold anything ({@see OpeningWindow}).
 *
 * A device arrives from the setup wizard with every variant at zero — for a new
 * business because `business:provision` seeds no catalog at all, for a new
 * branch because `stock_snapshots` hydrates scoped to a branch that has never
 * traded and so has no rows. Getting six hundred SKUs off zero was six hundred
 * lines through a dialog capped at a hundred per batch. This is the file that
 * does it.
 *
 * ## It writes the ledger directly, and that is a deliberate carve-out
 *
 * Every other stock movement in the system since schema 21 becomes a `pending`
 * row that a person at the affected branch confirms, and until now
 * {@see AdjustmentResolver} was the ONLY writer of `type = 'stock_adjustment'`.
 * This is the second, and the reason is in {@see OpeningWindow}: the queue
 * exists so a second voice can check a claim against a shelf that has a past,
 * and a branch that has never sold anything has no past for the claim to
 * contradict. Routing an opening count through the queue would make the same
 * person confirm a file they typed a moment earlier — the ceremony without the
 * second voice — and would run a six-hundred-line file into a hundred-line cap.
 *
 * The window is what keeps that honest. It is not a standing route around
 * schema 21: one sale and this class refuses.
 *
 * ## A quantity is the resulting on-hand, applied as a delta
 *
 * `delta = quantity - current stock`, the `set` semantics of
 * `StockBatchController`. `set` is refused there for any branch but this one
 * because another branch's baseline is missing its unsynced sales; here the
 * target IS this device, so the figure under the subtraction is the reconciled
 * one it sells by.
 *
 * That makes the file **re-uploadable**: run it twice and every delta is zero,
 * so the second run writes nothing. Which is what makes a typo survivable —
 * fix the cell, upload again, and the difference is what moves.
 *
 * ## Rows the file names but does not move
 *
 * A line whose delta is zero writes NO ledger row. That is the rule
 * MULTIBRANCH-ROADMAP.md M7 states after the rig defect that proved it: a zero
 * opening balance is the absence of a movement rather than a movement of
 * nothing, and a snapshot written without the movement behind it makes
 * {@see RemoteStock::recompute()} sum an incomplete ledger, go negative, and
 * `stock_snapshots_non_negative` refuse every cycle the branch ever runs again.
 * Which is also why this writes through {@see StockLedger} and recomputes from
 * Postgres's own ledger rather than copying a number upward.
 *
 * ## Reference type
 *
 * `csv_import` — the value the ledger has carried for a spreadsheet-sourced
 * movement since Phase 7. Nothing wrote it between M13 and this class, but it
 * never left {@see MovementKind::KINDS}, the check-constraint-free column, or
 * `resources/js/lib/stock-movement.ts`, so these rows render correctly on every
 * existing screen — and on older builds at other branches, which is why no
 * version floor moves for this.
 */
class OpeningStockImporter
{
    /** `import_history.mode` for this kind of file. */
    public const MODE = 'opening_stock';

    /** Chunk size for the SKU lookup, under SQLite's bound-variable limit. */
    private const LOOKUP_CHUNK = 500;

    public function __construct(
        private readonly OnlineWrite $online,
        private readonly StockLedger $ledger,
        private readonly RemoteStock $stock,
    ) {}

    /**
     * Apply the file: set every named variant's on-hand to the quantity given.
     *
     * All-or-nothing. Any row error and nothing is written but a `failed`
     * history row. Any other throwable is reported and recorded the same way, so
     * the operator always gets an Upload History entry rather than an error page
     * — the one pattern this shares with {@see CsvInventoryImporter::import()}.
     *
     * @throws ValidationException when the opening window has closed.
     */
    public function import(string $path, ?string $filename, User $user): ImportHistory
    {
        $this->assertWindowOpen();

        $importId = (string) Str::uuid();

        try {
            return $this->run($path, $filename, $user, $importId);
        } catch (Throwable $e) {
            report($e);
        }

        // Outside the try so a failure while recording the failure propagates
        // instead of being swallowed.
        return $this->record($importId, $user, $filename, 'failed', 0, 0, 1, [
            ['row' => null, 'messages' => ['Something went wrong while processing the file. No changes were saved.']],
        ]);
    }

    /**
     * Say what applying this file would do, without applying any of it.
     *
     * Runs exactly the validation {@see import()} runs, so a clean preview is a
     * genuine statement that the file is usable. Writes nothing, records no
     * history row, opens no connection — a previewed file is re-uploaded to be
     * applied and the second parse is the authoritative one.
     *
     * @return array{
     *     total: int,
     *     moves: int,
     *     unchanged: int,
     *     lines: list<array{sku: string, product: string, variant: string|null, current: int, quantity: int, delta: int}>,
     *     errors: list<array{row: int|null, messages: list<string>}>,
     * }
     */
    public function preview(string $path): array
    {
        $parsed = $this->parse($path);

        if ($parsed['errors'] !== []) {
            return [
                'total' => $parsed['total'],
                'moves' => 0,
                'unchanged' => 0,
                'lines' => [],
                'errors' => $parsed['errors'],
            ];
        }

        $lines = $this->resolve($parsed['rows'], $parsed['variants']);
        $moves = 0;

        foreach ($lines as $line) {
            if ($line['delta'] !== 0) {
                $moves++;
            }
        }

        return [
            'total' => $parsed['total'],
            'moves' => $moves,
            'unchanged' => count($lines) - $moves,
            'lines' => array_map(fn (array $line): array => [
                'sku' => $line['sku'],
                'product' => $line['product'],
                'variant' => $line['variant'],
                'current' => $line['current'],
                'quantity' => $line['quantity'],
                'delta' => $line['delta'],
            ], $lines),
            'errors' => [],
        ];
    }

    private function run(string $path, ?string $filename, User $user, string $importId): ImportHistory
    {
        $parsed = $this->parse($path);
        $total = $parsed['total'];

        if ($parsed['errors'] !== []) {
            return $this->record(
                $importId, $user, $filename, 'failed',
                $total, 0, count($parsed['errors']), $parsed['errors'],
            );
        }

        $lines = $this->resolve($parsed['rows'], $parsed['variants']);

        try {
            // An ONLINE WRITE, not a plain local transaction. Stock is shared
            // with the business: written locally only, these movements would be
            // reverted by the next cycle's baseline, and Postgres's snapshot
            // would keep summing a ledger they are missing from. Refused offline
            // by OnlineWrite itself.
            //
            // The history row is written inside it on purpose: if recording it
            // fails, the ledger rows roll back with it, so the fallback "failed
            // — no changes were saved" entry import() then writes is true.
            return $this->online->run(function () use ($lines, $user, $importId, $filename, $total) {
                $moved = $this->apply($lines, $user, $importId);

                $import = $this->record($importId, $user, $filename, 'success', $total, $total, 0, []);

                $this->mirrorImport($import, $importId, $moved);

                return $import;
            }, 'An opening stock import');
        } catch (ImportRowException $e) {
            return $this->record($importId, $user, $filename, 'failed', $total, 0, 1, [
                ['row' => $e->csvLine, 'messages' => [$e->getMessage()]],
            ]);
        }
    }

    /**
     * Read the file and validate every row, stopping short of writing anything.
     *
     * Shared by {@see run()} and {@see preview()} so the two can never disagree
     * about whether a file is usable.
     *
     * @return array{
     *     rows: list<array{line: int, sku: string, quantity: int}>,
     *     errors: list<array{row: int|null, messages: list<string>}>,
     *     variants: Collection<string, Variant>,
     *     total: int,
     * }
     */
    private function parse(string $path): array
    {
        $nothing = fn (array $errors): array => [
            'rows' => [], 'errors' => $errors, 'variants' => collect(), 'total' => 0,
        ];

        // Capped at 5 MB by the request, so reading it whole is fine. Non-UTF-8
        // bytes would otherwise poison the error messages and the JSON errors
        // column, so they are refused up front.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return $nothing([['row' => null, 'messages' => ['The file could not be read.']]]);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return $nothing([['row' => null, 'messages' => [
                'The file must be UTF-8 encoded. Re-save it as "CSV UTF-8" and upload again.',
            ]]]);
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        try {
            [$columnMap, $headerErrors] = $this->readHeaders($handle);

            if ($headerErrors !== []) {
                return $nothing($headerErrors);
            }

            [$rows, $rowErrors] = $this->parseAndValidate($handle, $columnMap);
        } finally {
            fclose($handle);
        }

        $total = count($rows) + count($rowErrors);

        if ($total === 0) {
            return $nothing([['row' => null, 'messages' => ['The file has no data rows.']]]);
        }

        $variants = $this->variantsBySku(array_column($rows, 'sku'));

        // Only once every row is individually well-formed: an unknown SKU on a
        // row that also has a malformed quantity is noise on top of the real
        // problem.
        if ($rowErrors === []) {
            $rowErrors = $this->catalogErrors($rows, $variants);
        }

        return ['rows' => $rows, 'errors' => $rowErrors, 'variants' => $variants, 'total' => $total];
    }

    /**
     * Read the header row, leaving the handle on the first data row.
     *
     * Columns this file does not recognise are skipped rather than refused —
     * see {@see OpeningStockCsvSchema}: it is meant to accept the item
     * spreadsheet with a quantity column added.
     *
     * @param  resource  $handle
     * @return array{0: array<string, int>, 1: list<array{row: null, messages: list<string>}>}
     */
    private function readHeaders($handle): array
    {
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            return [[], [['row' => null, 'messages' => ['The file is empty.']]]];
        }

        $columnMap = [];
        foreach ($header as $index => $cell) {
            $name = OpeningStockCsvSchema::normalizeHeader((string) $cell);
            if (in_array($name, OpeningStockCsvSchema::COLUMNS, true) && ! isset($columnMap[$name])) {
                $columnMap[$name] = $index;
            }
        }

        $missing = array_diff(OpeningStockCsvSchema::REQUIRED, array_keys($columnMap));

        if ($missing !== []) {
            return [$columnMap, [[
                'row' => null,
                'messages' => ['The file is missing required column(s): '.implode(', ', $missing).'.'],
            ]]];
        }

        return [$columnMap, []];
    }

    /**
     * Parse and validate every data row.
     *
     * @param  resource  $handle
     * @param  array<string, int>  $columnMap
     * @return array{0: list<array{line: int, sku: string, quantity: int}>, 1: list<array{row: int|null, messages: list<string>}>}
     */
    private function parseAndValidate($handle, array $columnMap): array
    {
        $rows = [];
        $errors = [];
        $seenSku = [];
        $line = 1; // the header was line 1

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // Trailing newlines and spacer rows.
            if ($this->isBlankRow($cells)) {
                continue;
            }

            $get = fn (string $column): string => isset($columnMap[$column], $cells[$columnMap[$column]])
                ? trim((string) $cells[$columnMap[$column]])
                : '';

            $messages = [];

            // SKUs are canonically uppercase, so normalising here means the
            // lookup, the duplicate check and the stored value all agree.
            $sku = mb_strtoupper($get('sku'));
            $quantityRaw = $get('quantity');

            if ($sku === '') {
                $messages[] = 'SKU is required.';
            } elseif (mb_strlen($sku) > 255) {
                $messages[] = 'SKU must be 255 characters or fewer.';
            }

            $quantity = null;

            if ($quantityRaw === '') {
                $messages[] = 'Quantity is required.';
            } elseif (! $this->isWholeNumber($quantityRaw)) {
                $messages[] = 'Quantity must be a whole number between 0 and '
                    .OpeningStockCsvSchema::MAX_QUANTITY.'.';
            } else {
                $quantity = (int) $quantityRaw;

                if ($quantity > OpeningStockCsvSchema::MAX_QUANTITY) {
                    $messages[] = 'Quantity must be a whole number between 0 and '
                        .OpeningStockCsvSchema::MAX_QUANTITY.'.';
                    $quantity = null;
                }
            }

            // Two lines for one variant are ambiguous rather than additive —
            // the same rule StoreStockBatchRequest applies, and more so here,
            // where each line asserts a resulting total rather than a change.
            if ($sku !== '') {
                if (isset($seenSku[$sku])) {
                    $messages[] = 'SKU "'.$sku.'" appears on more than one row.';
                }
                $seenSku[$sku] = true;
            }

            if ($messages !== []) {
                $errors[] = ['row' => $line, 'messages' => $messages];

                continue;
            }

            $rows[] = ['line' => $line, 'sku' => $sku, 'quantity' => (int) $quantity];
        }

        return [$rows, $errors];
    }

    /**
     * Every variant the file names, archived ones included, keyed by SKU.
     *
     * Archived rows are loaded on purpose: leaving them out would report an
     * archived product as an unknown SKU, and "no product with SKU X" sends
     * somebody looking for a typo that is not there.
     *
     * @param  list<string>  $skus
     * @return Collection<string, Variant>
     */
    private function variantsBySku(array $skus): Collection
    {
        $unique = array_values(array_unique(array_filter($skus)));

        if ($unique === []) {
            return collect();
        }

        /** @var Collection<string, Variant> $found */
        $found = collect();

        foreach (array_chunk($unique, self::LOOKUP_CHUNK) as $chunk) {
            $found = $found->merge(
                Variant::query()
                    ->with('item:id,name,archived_at')
                    ->whereIn('sku', $chunk)
                    ->get()
                    ->keyBy(fn (Variant $variant): string => (string) $variant->sku),
            );
        }

        return $found;
    }

    /**
     * Errors that need the catalog to see: a SKU this business does not sell,
     * or one belonging to something archived.
     *
     * This is what makes the new-branch case "only the stocks of the current
     * catalog" — the file may set a count for anything the branch has hydrated
     * and nothing else. Creating products is the item import's job.
     *
     * @param  list<array{line: int, sku: string, quantity: int}>  $rows
     * @param  Collection<string, Variant>  $variants
     * @return list<array{row: int|null, messages: list<string>}>
     */
    private function catalogErrors(array $rows, Collection $variants): array
    {
        $errors = [];

        foreach ($rows as $row) {
            $variant = $variants->get($row['sku']);

            $message = match (true) {
                $variant === null => 'No product has SKU "'.$row['sku'].'". Import the product first.',
                $variant->archived_at !== null => 'SKU "'.$row['sku'].'" belongs to an archived variant.',
                $variant->item?->archived_at !== null => 'SKU "'.$row['sku'].'" belongs to an archived product.',
                default => null,
            };

            if ($message !== null) {
                $errors[] = ['row' => $row['line'], 'messages' => [$message]];
            }
        }

        return $errors;
    }

    /**
     * Pair every row with its variant and work out the movement it asks for.
     *
     * Only ever called once the file is clean, so every SKU resolves.
     *
     * @param  list<array{line: int, sku: string, quantity: int}>  $rows
     * @param  Collection<string, Variant>  $variants
     * @return list<array{line: int, sku: string, product: string, variant: string|null, model: Variant, current: int, quantity: int, delta: int}>
     */
    private function resolve(array $rows, Collection $variants): array
    {
        $lines = [];

        foreach ($rows as $row) {
            /** @var Variant $variant */
            $variant = $variants->get($row['sku']);
            $current = $this->ledger->currentStock($variant);

            $lines[] = [
                'line' => $row['line'],
                'sku' => $row['sku'],
                // `item_id` is a non-nullable foreign key, so the relation is
                // always there — the eager load in variantsBySku() is what makes
                // this one query rather than one per line.
                'product' => $variant->item->name,
                'variant' => $variant->name,
                'model' => $variant,
                'current' => $current,
                'quantity' => $row['quantity'],
                // The resulting on-hand, expressed as the movement that gets
                // there. Zero when the shelf already says so.
                'delta' => $row['quantity'] - $current,
            ];
        }

        return $lines;
    }

    /**
     * Write one ledger entry per line that actually moves, and return the
     * variant ids that did.
     *
     * @param  list<array{line: int, sku: string, product: string, variant: string|null, model: Variant, current: int, quantity: int, delta: int}>  $lines
     * @return list<string>
     *
     * @throws ImportRowException
     */
    private function apply(array $lines, User $user, string $importId): array
    {
        $moved = [];

        foreach ($lines as $line) {
            if ($line['delta'] === 0) {
                continue;
            }

            try {
                $this->ledger->record(
                    variant: $line['model'],
                    quantity: $line['delta'],
                    type: 'stock_adjustment',
                    saleType: null,
                    referenceType: 'csv_import',
                    // The import_history row, so a movement can still be traced
                    // back to the file that asked for it.
                    referenceId: $importId,
                    performedBy: $user,
                    notes: 'Opening stock import',
                );
            } catch (NegativeInventoryException) {
                // Not reachable through validation — a quantity is >= 0 and the
                // delta lands exactly on it — but StockLedger is the invariant's
                // home and a row-attributed refusal beats a 500 if it ever is.
                throw new ImportRowException(
                    $line['line'],
                    'Setting "'.$line['sku'].'" to '.$line['quantity'].' would drive stock below zero.',
                );
            }

            $moved[] = (string) $line['model']->getKey();
        }

        return $moved;
    }

    /**
     * Send everything this import produced to the business database.
     *
     * Read back from the import's own id rather than threaded through, as the
     * item importer does with its catalog rows.
     *
     * The recompute is not optional and is not a tidy-up. Postgres has no
     * trigger behind `stock_snapshots`; the till maintains it, and
     * {@see RemoteStock::recompute()} rebuilds it by summing Postgres's OWN
     * ledger — so the mirrored rows have to land first, which is what
     * `afterMirroring()` is for. Skip it and the snapshot upstream still reads
     * zero while this device reads the real figure, and the first sale from
     * here sums negative and takes every future cycle down with it.
     *
     * @param  list<string>  $movedVariantIds
     */
    private function mirrorImport(ImportHistory $import, string $importId, array $movedVariantIds): void
    {
        $entries = InventoryLedger::query()->where('reference_id', $importId)->get();

        $this->online->mirror(...$entries->all());
        $this->online->mirror($import);

        if ($movedVariantIds === []) {
            // Nothing moved, so there is nothing to recompute. A recompute over
            // an empty list is a statement that stock changed, and it did not.
            return;
        }

        $branchId = (string) Instance::current()?->branch_id;

        $this->online->afterMirroring(
            fn (Connection $remote) => $this->stock->recompute($remote, $branchId, $movedVariantIds),
        );
    }

    /**
     * @param  list<array{row: int|null, messages: list<string>}>  $errors
     */
    private function record(
        string $id,
        User $user,
        ?string $filename,
        string $status,
        int $totalRows,
        int $successCount,
        int $errorCount,
        array $errors,
    ): ImportHistory {
        // Assigned rather than filled: `id` is guarded, and this has to match
        // the reference_id already written onto the import's ledger rows.
        $import = new ImportHistory([
            'user_id' => $user->getKey(),
            'filename' => $filename,
            'mode' => self::MODE,
            'status' => $status,
            'total_rows' => $totalRows,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors === [] ? null : $errors,
        ]);
        $import->id = $id;
        $import->save();

        if ($status === 'failed') {
            $this->mirrorFailure($import);
        }

        return $import;
    }

    /**
     * Send a FAILED import row up on its own, best-effort.
     *
     * Same shape and the same reason as
     * {@see CsvInventoryImporter::mirrorFailure()}: every failure path records
     * outside the import's online write, where `mirror()` has nothing to attach
     * to; a failure to record a failure must never become the operator's error;
     * and `import_history` is in {@see OnlineWrite::APPEND_ONLY}, so it lands as
     * `on conflict do nothing`.
     */
    private function mirrorFailure(ImportHistory $import): void
    {
        rescue(
            fn () => $this->online->run(
                fn () => $this->online->mirror($import),
                'A failed opening stock import',
            ),
            report: false,
        );
    }

    /**
     * The last line of defence on the window, behind the button and the request.
     *
     * @throws ValidationException
     */
    private function assertWindowOpen(): void
    {
        if (OpeningWindow::isOpen()) {
            return;
        }

        throw ValidationException::withMessages([
            'file' => 'This branch has already made sales, so opening stock can no longer be imported. '
                .'Use a stock batch on the Inventory page — those are confirmed by somebody at the branch '
                .'before stock moves.',
        ]);
    }

    /**
     * @param  list<string>  $cells
     */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Non-negative, at most 6 digits — comfortably inside PHP's int range, so
     * the cast is exact. A leading "+" or "-" is refused rather than parsed: an
     * opening count is a total on a shelf, not a change to one, and "-5" almost
     * certainly means the file was built for the wrong screen.
     */
    private function isWholeNumber(string $value): bool
    {
        return $value !== '' && preg_match('/^\d{1,6}$/', $value) === 1;
    }
}
