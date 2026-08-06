<?php

namespace App\Support\Inventory;

use App\Exceptions\ImportRowException;
use App\Models\Category;
use App\Models\ImportHistory;
use App\Models\Item;
use App\Models\User;
use App\Models\Variant;
use App\Rules\SpreadsheetSafeName;
use App\Support\Sync\OnlineWrite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bulk inventory CSV import (Phase 7). Parses an uploaded CSV, fully validates
 * every row, and — only when the whole file is clean — applies it inside a
 * single transaction.
 *
 * ## The catalog lands; the stock waits (schema 21)
 *
 * An import writes Categories, Items and Variants immediately, as an online
 * write. It no longer moves stock: each row's delta becomes a PENDING stock
 * adjustment ({@see AdjustmentQueue}) that somebody at the branch confirms, in
 * one go, through Approve all.
 *
 * A new product therefore appears in the catalog at stock 0 until the import is
 * confirmed, which is the honest state — the file says the shelf holds twelve,
 * and nobody has looked yet.
 *
 * All-or-nothing still, for everything the file can get wrong: one bad row
 * aborts the import and nothing is persisted. What it can no longer fail on is
 * stock, because no stock moves here; a subtraction that will not fit is refused
 * when it is confirmed, per product, where it can be partially approved instead.
 *
 * The outcome — counts and per-row errors — is recorded as an ImportHistory row
 * (the "Upload History" feature), which doubles as the reference_id carried
 * through the queue onto whatever ledger entries confirmation produces.
 *
 * Semantics the importer enforces (mirroring the item form):
 *   - SKUs are mandatory and canonically UPPERCASE — lowercase input is
 *     accepted and normalized, so "water-500" matches a stored "WATER-500".
 *   - Item names are immutable: a row whose product cell disagrees with the
 *     item its SKU belongs to is an error, never a silent no-op. Same for a
 *     category cell that would move an existing product.
 *   - Blank optional cells never wipe data: on a matched variant they keep the
 *     current value; only on a brand-new one do they mean "default/absent".
 */
class CsvInventoryImporter
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly OnlineWrite $online,
        private readonly AdjustmentQueue $queue,
    ) {}

    /**
     * Import the CSV at $path. $canViewCost gates whether the cost column is
     * honoured (Owner-only, mirroring the ItemRequest write guard). Returns the
     * persisted ImportHistory record describing the outcome.
     *
     * Never throws for a bad file: any unexpected failure is reported and
     * recorded as a failed import so the user always gets an Upload History
     * entry instead of an error page.
     */
    public function import(string $path, ?string $filename, User $user, bool $canViewCost): ImportHistory
    {
        $importId = (string) Str::uuid();

        try {
            return $this->run($path, $filename, $user, $canViewCost, $importId);
        } catch (Throwable $e) {
            report($e);
        }

        // Outside the try so a failure while recording the failure propagates
        // instead of being swallowed.
        return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, [
            ['row' => null, 'messages' => ['Something went wrong while processing the file. No changes were saved.']],
        ]);
    }

    private const MODE_FALLBACK = InventoryCsvSchema::MODE_ADD;

    /** ImportHistory mode value for a file whose rows use both modes. */
    private const MODE_MIXED = 'mixed';

    /** whereIn/placeholder chunk size, safely below SQLite's variable limit. */
    private const LOOKUP_CHUNK = 500;

    private function run(string $path, ?string $filename, User $user, bool $canViewCost, string $importId): ImportHistory
    {
        // The upload is capped at 5 MB (InventoryImportRequest), so reading it
        // whole is fine. Reject non-UTF-8 files up front: their bytes would
        // otherwise poison error messages and the JSON errors column.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, [
                ['row' => null, 'messages' => ['The file could not be read.']],
            ]);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, [
                ['row' => null, 'messages' => ['The file must be UTF-8 encoded. Re-save it as "CSV UTF-8" and upload again.']],
            ]);
        }

        // One pass over one stream: the header read leaves the handle
        // positioned on the first data row, which the row loop continues from.
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        try {
            [$columnMap, $headerErrors] = $this->readHeaders($handle);

            if ($headerErrors !== []) {
                return $this->record($importId, $user, $filename, self::MODE_FALLBACK, 'failed', 0, 0, 1, $headerErrors);
            }

            [$rows, $rowErrors] = $this->parseAndValidate($handle, $columnMap, $canViewCost);
        } finally {
            fclose($handle);
        }

        $modes = array_values(array_unique(array_column($rows, 'mode')));
        $mode = match (true) {
            $modes === [] => self::MODE_FALLBACK,
            count($modes) === 1 => $modes[0],
            default => self::MODE_MIXED,
        };

        $total = count($rows) + count($rowErrors);

        if ($total === 0) {
            return $this->record($importId, $user, $filename, $mode, 'failed', 0, 0, 1, [
                ['row' => null, 'messages' => ['The file has no data rows.']],
            ]);
        }

        // Once every row is individually valid, validate the resulting catalog
        // shape against the DB (archived matches, immutable names/categories,
        // no-variant vs. named model). The variants the file matches by SKU are
        // loaded once here and reused by apply().
        $variantsBySku = collect();

        if ($rowErrors === []) {
            $variantsBySku = $this->variantsBySku(array_column($rows, 'sku'));
            $rowErrors = $this->validateCatalogShape($rows, $variantsBySku);
        }

        if ($rowErrors !== []) {
            return $this->record($importId, $user, $filename, $mode, 'failed', $total, 0, count($rowErrors), $rowErrors);
        }

        try {
            // The success row is written inside the transaction on purpose: if
            // recording it fails, the catalog changes roll back with it, so the
            // fallback "failed — no changes were saved" entry import() then
            // writes is actually true.
            // An ONLINE WRITE, not a plain local transaction (M6.5). An import
            // creates categories, items and variants — shared catalog every
            // branch reads — and, since schema 21, pending adjustments, which
            // live only in Postgres. Written locally only, none of it would ever
            // reach the business database: §17 gated this screen on connectivity
            // in M6 believing that was enough, and being online is not the same
            // as arriving. See MULTIBRANCH-ROADMAP.md M6.5.
            return $this->online->run(function () use ($rows, $user, $canViewCost, $importId, $filename, $mode, $total, $variantsBySku) {
                $queued = $this->apply($rows, $user, $canViewCost, $importId, $variantsBySku);

                $import = $this->record($importId, $user, $filename, $mode, 'success', $total, $total, 0, []);

                $this->mirrorImport($import, $rows);

                // Registered on this same online write rather than run as one of
                // its own: nesting an OnlineWrite would open a second Postgres
                // connection and re-run the version gate inside this one's open
                // transaction. One multi-row insert for the whole file.
                $this->queue->enqueue($queued, $user);

                return $import;
            }, 'A CSV import');
        } catch (ImportRowException $e) {
            return $this->record($importId, $user, $filename, $mode, 'failed', $total, 0, 1, [
                ['row' => $e->csvLine, 'messages' => [$e->getMessage()]],
            ]);
        }
    }

    /**
     * Send everything this import produced to the business database.
     *
     * Read back rather than threaded through: the import writes catalog rows
     * from six private methods and its ledger rows from a seventh, and passing a
     * collector through all of them would be one more thing to forget in each.
     * Every row is reachable from the import's own id or from the SKUs in the
     * file, so the set is derived rather than accumulated.
     *
     * ## No ledger rows any more, and nothing to recompute
     *
     * This used to read back `inventory_ledger where reference_id = <import id>`
     * and mirror those rows too, then recompute Postgres's `stock_snapshots` from
     * them. Since schema 21 an import writes no ledger rows at all — it asks for
     * pending adjustments instead — so both halves would now find nothing and
     * silently do nothing. Removed rather than left to no-op: a recompute over an
     * empty variant list is a statement that stock moved, and it did not.
     *
     * The SKUs in the file are therefore the only net for the catalog rows, which
     * they already were: a variant whose stock did not change never had a ledger
     * row to be found by.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function mirrorImport(ImportHistory $import, array $rows): void
    {
        $variants = Variant::query()->whereIn('sku', array_column($rows, 'sku'))->get();
        $items = Item::query()->whereIn('id', $variants->pluck('item_id')->unique())->get();
        $categories = Category::query()->whereIn('id', $items->pluck('category_id')->filter()->unique())->get();

        $this->online->mirror(...$categories->all());
        $this->online->mirror(...$items->all());
        $this->online->mirror(...$variants->all());
        $this->online->mirror($import);
    }

    /**
     * Read and validate the header row from the open CSV stream, leaving the
     * handle positioned on the first data row. Returns [columnMap, errors]
     * where columnMap maps a canonical column name to its 0-based position, and
     * a non-empty errors list means the file is unusable (missing columns).
     *
     * @param  resource  $handle
     * @return array{0: array<string, int>, 1: list<array{row: null, messages: list<string>}>}
     */
    private function readHeaders($handle): array
    {
        $header = fgetcsv($handle, escape: '');

        if ($header === false || $header === null) {
            return [[], [['row' => null, 'messages' => ['The file is empty.']]]];
        }

        $columnMap = [];
        foreach ($header as $index => $cell) {
            $name = InventoryCsvSchema::normalizeHeader((string) $cell);
            if (in_array($name, InventoryCsvSchema::COLUMNS, true) && ! isset($columnMap[$name])) {
                $columnMap[$name] = $index;
            }
        }

        $missing = array_diff(InventoryCsvSchema::REQUIRED, array_keys($columnMap));

        if ($missing !== []) {
            return [$columnMap, [[
                'row' => null,
                'messages' => ['The file is missing required column(s): '.implode(', ', $missing).'.'],
            ]]];
        }

        return [$columnMap, []];
    }

    /**
     * Per-field maximum lengths, mirroring the item/category form rules so the
     * import cannot create values the UI would reject.
     */
    private const MAX_LENGTHS = [
        'Product' => 255,
        'Variant' => 255,
        'SKU' => 255,
        'Barcode' => 255,
        'Category' => 255,
        'Unit' => 50,
        'Description' => 1000,
    ];

    /**
     * Parse every data row into a normalised structure and validate it. Expects
     * the handle to sit just past the header (where readHeaders left it).
     * Returns [rows, errors]; rows is only meaningful (and applied) when errors
     * is empty.
     *
     * @param  resource  $handle
     * @param  array<string, int>  $columnMap
     * @return array{0: list<array<string, mixed>>, 1: list<array{row: int|null, messages: list<string>}>}
     */
    private function parseAndValidate($handle, array $columnMap, bool $canViewCost): array
    {
        $rows = [];
        $errorsByLine = [];
        $seenSku = [];
        $seenBarcode = [];
        $barcodeRows = [];
        $line = 1; // header was line 1

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // Skip fully blank rows (trailing newlines, spacer rows).
            if ($this->isBlankRow($cells)) {
                continue;
            }

            $get = fn (string $column): string => isset($columnMap[$column], $cells[$columnMap[$column]])
                ? trim((string) $cells[$columnMap[$column]])
                : '';

            $messages = [];

            $product = $get('product');
            $variantName = $get('variant');
            // SKUs are canonically UPPERCASE; normalizing here means every
            // downstream lookup, duplicate check and save uses the stored form.
            $sku = mb_strtoupper($get('sku'));
            $barcode = $get('barcode');
            $category = $get('category');
            $unit = $get('unit');
            $description = $get('description');
            $sellingPriceRaw = $get('selling_price');
            $costRaw = $get('cost');
            $stockRaw = $get('stock');
            $thresholdRaw = $get('low_stock_threshold');
            $mode = InventoryCsvSchema::normalizeMode($get('mode'));

            foreach (['product' => $product, 'sku' => $sku] as $label => $value) {
                if ($value === '') {
                    $messages[] = ucfirst($label).' is required.';
                }
            }

            foreach ([
                'Product' => $product,
                'Variant' => $variantName,
                'SKU' => $sku,
                'Barcode' => $barcode,
                'Category' => $category,
                'Unit' => $unit,
                'Description' => $description,
            ] as $label => $value) {
                if (mb_strlen($value) > self::MAX_LENGTHS[$label]) {
                    $messages[] = $label.' must be '.self::MAX_LENGTHS[$label].' characters or fewer.';
                }
            }

            // Formula-injection guard (F-16): these cells round-trip through the
            // CSV export, so they must never begin with a spreadsheet formula
            // trigger. Mirrors the SpreadsheetSafeName rule on the forms.
            foreach (['Product' => $product, 'Variant' => $variantName, 'Category' => $category] as $label => $value) {
                if ($value !== '' && ! SpreadsheetSafeName::isSafe($value)) {
                    $messages[] = $label.' may not start with "=", "+", "-" or "@".';
                }
            }

            if (! $this->isValidMoney($sellingPriceRaw)) {
                $messages[] = 'Selling price must be a number between 0 and 9999999999.99 with up to 2 decimals.';
            }

            if ($costRaw !== '' && ! $this->isValidMoney($costRaw)) {
                $messages[] = 'Cost must be a number between 0 and 9999999999.99 with up to 2 decimals.';
            }

            $stock = null;
            if (! $this->isInteger($stockRaw)) {
                $messages[] = 'Stock must be a whole number between -999999999 and 999999999.';
            } else {
                $stock = (int) $stockRaw;
                if ($mode === InventoryCsvSchema::MODE_REPLACE && $stock < 0) {
                    $messages[] = 'Stock cannot be negative when replacing the stock count.';
                }
            }

            $threshold = null;
            if ($thresholdRaw !== '') {
                if (! $this->isInteger($thresholdRaw) || (int) $thresholdRaw < 0) {
                    $messages[] = 'Low stock threshold must be a whole number between 0 and 999999999.';
                } else {
                    $threshold = (int) $thresholdRaw;
                }
            }

            if (! in_array($mode, InventoryCsvSchema::MODES, true)) {
                $messages[] = 'Mode must be "'.InventoryCsvSchema::MODE_REPLACE.'" or "'.InventoryCsvSchema::MODE_ADD.'".';
            }

            // In-file duplicate SKU/barcode: each brand-new colliding row passes a
            // DB check individually, so reject collisions within the file itself.
            if ($sku !== '') {
                if (isset($seenSku[$sku])) {
                    $messages[] = 'SKU "'.$sku.'" appears on more than one row.';
                }
                $seenSku[$sku] = true;
            }

            if ($barcode !== '') {
                // Exact match, like the DB unique index and the item form's
                // unique rule — case-folding here would reject files the form
                // (and the index) accepts.
                if (isset($seenBarcode[$barcode])) {
                    $messages[] = 'Barcode "'.$barcode.'" appears on more than one row.';
                }
                $seenBarcode[$barcode] = true;

                // Collisions with existing variants are checked in one bulk
                // query after the loop (barcodeCollisionErrors).
                if ($sku !== '') {
                    $barcodeRows[$line] = ['sku' => $sku, 'barcode' => $barcode];
                }
            }

            if ($messages !== []) {
                $errorsByLine[$line] = $messages;

                continue;
            }

            $rows[] = [
                'line' => $line,
                'category' => $category === '' ? null : $category,
                'product' => $product,
                // Blank variant name -> null: "keep the current name" for an
                // existing variant, unnamed ("no variant") for a new one.
                'variant' => $variantName === '' ? null : $variantName,
                'sku' => $sku,
                'barcode' => $barcode === '' ? null : $barcode,
                'selling_price' => $sellingPriceRaw,
                'cost' => ($canViewCost && $costRaw !== '') ? $costRaw : null,
                'cost_present' => $canViewCost && $costRaw !== '',
                'stock' => $stock,
                'unit' => $unit === '' ? null : $unit,
                'description' => $description === '' ? null : $description,
                'low_stock_threshold' => $threshold,
                'mode' => $mode,
            ];
        }

        foreach ($this->barcodeCollisionErrors($barcodeRows) as $errorLine => $message) {
            $errorsByLine[$errorLine][] = $message;
        }

        // Rows and errors stay disjoint (run() sums them for the row total), so
        // a row failed only by the barcode check must leave $rows.
        $rows = array_values(array_filter(
            $rows,
            fn (array $row) => ! isset($errorsByLine[$row['line']])
        ));

        ksort($errorsByLine);

        $errors = [];
        foreach ($errorsByLine as $errorLine => $messages) {
            $errors[] = ['row' => $errorLine, 'messages' => $messages];
        }

        return [$rows, $errors];
    }

    /**
     * Barcodes already held by a different existing variant (identified by SKU;
     * uniqueness spans archived variants, and a legacy SKU-less holder always
     * counts as "different"). One chunked query for the whole file instead of
     * one per row. Returns line => message.
     *
     * @param  array<int, array{sku: string, barcode: string}>  $barcodeRows
     * @return array<int, string>
     */
    private function barcodeCollisionErrors(array $barcodeRows): array
    {
        if ($barcodeRows === []) {
            return [];
        }

        $barcodes = array_values(array_unique(array_column($barcodeRows, 'barcode')));

        // barcode => SKU of the variant holding it (unique index: at most one).
        $ownerSkus = collect(array_chunk($barcodes, self::LOOKUP_CHUNK))
            ->flatMap(fn (array $chunk) => Variant::whereIn('barcode', $chunk)->get(['barcode', 'sku']))
            ->keyBy('barcode')
            ->map(fn (Variant $variant) => $variant->sku);

        $errors = [];

        foreach ($barcodeRows as $line => $row) {
            if ($ownerSkus->has($row['barcode']) && $ownerSkus->get($row['barcode']) !== $row['sku']) {
                $errors[$line] = 'Barcode "'.$row['barcode'].'" is already used by another product.';
            }
        }

        return $errors;
    }

    /**
     * DB-backed validation of the catalog shape the file would produce, run only
     * once every row is individually valid. Enforces the same invariants as the
     * individual item form (ItemRequest) so a bulk import can't create states the
     * UI forbids:
     *   - a row must not match an archived variant (by SKU) or an archived item
     *     (by product name) — the user must restore it first;
     *   - item names are immutable and items never move category via import, so
     *     a product or category cell that disagrees with the SKU's item is an
     *     error, never a silent no-op;
     *   - a brand-new item cannot be filed under an archived category;
     *   - rows describing the same product must agree on its category, unit and
     *     description (no silent first-row-wins);
     *   - an item is either a single unnamed "no variant" product or has every
     *     variant named; the unnamed+named (or multiple-unnamed) mix is rejected.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, Variant>  $variantsBySku  from variantsBySku()
     * @return list<array{row: int|null, messages: list<string>}>
     */
    private function validateCatalogShape(array $rows, Collection $variantsBySku): array
    {
        $errors = [];

        // Existing items referenced by product name (case-insensitive), used to
        // resolve new-SKU rows and to reject archived-name matches.
        $itemsByName = $this->itemsByLowerName(array_column($rows, 'product'));

        // Existing categories referenced by name, to reject archived matches
        // for brand-new items. (Category names have no unique index; the
        // importer has always matched them case-insensitively.)
        $categoriesByName = $this->categoriesByLowerName(array_column($rows, 'category'));

        // Group rows by the item they will end up on, so we can check the final
        // variant naming per item. Key: "item:<id>" for an existing item,
        // "new:<lower name>" for one this file would create.
        $groups = [];

        foreach ($rows as $row) {
            $existing = $variantsBySku->get($row['sku']);

            if ($existing !== null) {
                if ($existing->archived_at !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'SKU "'.$row['sku'].'" belongs to an archived variant. Restore it from Inventory before importing.',
                    ]];

                    continue;
                }

                // The variant is active but its parent item may be archived
                // (archiving an item does not cascade to its variants).
                if ($existing->item !== null && $existing->item->archived_at !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'SKU "'.$row['sku'].'" belongs to an archived product. Restore it from Inventory before importing.',
                    ]];

                    continue;
                }

                // Item names are immutable: a product cell that disagrees with
                // the SKU's item is a mistake, not a rename request.
                if ($existing->item !== null && self::nameKey($row['product']) !== self::nameKey($existing->item->name)) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'SKU "'.$row['sku'].'" belongs to "'.$existing->item->name.'", but this row names the product "'
                        .$row['product'].'". The import can\'t rename products — fix the product cell.',
                    ]];

                    continue;
                }

                // SKU, barcode, variant name and the item's unit/description are
                // fixed once the variant exists — an update may only touch
                // price, cost, stock, threshold and category.
                if ($existing->item !== null && ($message = $this->immutableFieldErrors($row, $existing, $existing->item)) !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [$message]];

                    continue;
                }

                if (($message = $this->categoryTargetError($row, $existing->item, $categoriesByName)) !== null) {
                    $errors[] = ['row' => $row['line'], 'messages' => [$message]];

                    continue;
                }

                // Stash the match so the naming check below can substitute the
                // variant's current name for a blank cell.
                $row['existing'] = $existing;

                $groups['item:'.$existing->item_id]['item'] ??= $existing->item;
                $groups['item:'.$existing->item_id]['rows'][] = $row;

                continue;
            }

            // Brand-new SKU: resolve the item it would attach to by name.
            $item = $itemsByName->get(self::nameKey($row['product']));

            if ($item !== null && $item->archived_at !== null) {
                $errors[] = ['row' => $row['line'], 'messages' => [
                    'Product "'.$row['product'].'" matches an archived item. Restore it from Inventory before importing.',
                ]];

                continue;
            }

            // Attaching a new variant to an existing item may not change that
            // item's (shared) unit or description.
            if ($item !== null && ($message = $this->immutableFieldErrors($row, null, $item)) !== null) {
                $errors[] = ['row' => $row['line'], 'messages' => [$message]];

                continue;
            }

            // A non-blank category files a new item, or moves an existing one,
            // into that category — the target must be active.
            if (($message = $this->categoryTargetError($row, $item, $categoriesByName)) !== null) {
                $errors[] = ['row' => $row['line'], 'messages' => [$message]];

                continue;
            }

            $key = $item !== null ? 'item:'.$item->getKey() : 'new:'.self::nameKey($row['product']);
            $groups[$key]['item'] ??= $item; // null for a to-be-created item
            $groups[$key]['rows'][] = $row;
        }

        if ($errors !== []) {
            return $errors; // archived/mismatch rows already reject the whole file
        }

        // Existing active variants of the items the file touches, so an item
        // shared with variants outside the file is judged on its full shape.
        $existingItemIds = [];
        foreach ($groups as $group) {
            if (($group['item'] ?? null) !== null) {
                $existingItemIds[] = $group['item']->getKey();
            }
        }

        $activeByItem = collect(array_chunk($existingItemIds, self::LOOKUP_CHUNK))
            ->flatMap(fn (array $chunk) => Variant::active()
                ->whereIn('item_id', $chunk)
                ->get(['id', 'sku', 'name', 'item_id']))
            ->groupBy('item_id');

        foreach ($groups as $group) {
            $item = $group['item'] ?? null;
            $rowsForItem = $group['rows'];
            $fileSkus = array_column($rowsForItem, 'sku');

            // Rows describing one product must agree on its descriptive fields
            // — a conflict is a mistake, never a silent first-row-wins.
            foreach ($this->conflictingFieldErrors($rowsForItem) as $error) {
                $errors[] = $error;
            }

            // Final variant names = file rows (upsert by SKU; a blank cell keeps
            // the matched variant's current name) + this item's other active
            // variants not present in the file.
            $names = array_map(fn (array $row) => $this->effectiveVariantName($row), $rowsForItem);

            $itemActive = $item !== null ? ($activeByItem[$item->getKey()] ?? collect()) : collect();
            foreach ($itemActive as $variant) {
                if (! in_array($variant->sku, $fileSkus, true)) {
                    $names[] = $variant->name;
                }
            }

            $unnamed = array_values(array_filter(
                $names,
                fn ($name) => $name === null || trim((string) $name) === ''
            ));

            if (count($names) > 1 && $unnamed !== []) {
                $errors[] = $this->variantMixError($group, $rowsForItem);
            }
        }

        return $errors;
    }

    /**
     * The variant name a row would leave in place: the cell value, or — when the
     * cell is blank and the row matched an existing variant — that variant's
     * current name. Blank on a brand-new variant stays null (unnamed).
     *
     * @param  array<string, mixed>  $row
     */
    private function effectiveVariantName(array $row): ?string
    {
        return $row['variant'] ?? ($row['existing'] ?? null)?->name;
    }

    /**
     * Import updates are limited to price, cost, stock, threshold and category.
     * SKU, barcode, every name, and the item's unit/description are fixed once
     * the variant/item exists: a non-blank cell that disagrees with the stored
     * value is a mistake (a blank cell, or one equal to the current value, is
     * fine — so an unedited export re-imports cleanly). Returns the first
     * offending field's message, or null. $existing is null when a brand-new
     * variant merely attaches to an existing $item (only the item-level unit
     * and description are then fixed).
     *
     * @param  array<string, mixed>  $row
     */
    private function immutableFieldErrors(array $row, ?Variant $existing, Item $item): ?string
    {
        $checks = [];

        if ($existing !== null) {
            $checks[] = ['field' => 'barcode', 'cell' => $row['barcode'], 'stored' => $existing->barcode];
            $checks[] = ['field' => 'variant name', 'cell' => $row['variant'], 'stored' => $existing->name];
        }

        $checks[] = ['field' => 'unit', 'cell' => $row['unit'], 'stored' => $item->unit];
        $checks[] = ['field' => 'description', 'cell' => $row['description'], 'stored' => $item->description];

        foreach ($checks as $check) {
            if ($check['cell'] !== null && $check['cell'] !== $check['stored']) {
                return 'This row would change the '.$check['field'].' of existing product "'.$item->name
                    .'", which the import can\'t do. Leave that cell blank or set it to the current value, '
                    .'then change it in Inventory instead.';
            }
        }

        return null;
    }

    /**
     * A non-blank category cell files a new item, or moves an existing one, into
     * that category. Blank keeps the current category, and a value naming the
     * item's current category is a no-op (allowed even if that category has
     * since been archived). Any other target must resolve to an active category.
     * Returns the row error message, or null.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Category>  $categoriesByName
     */
    private function categoryTargetError(array $row, ?Item $item, Collection $categoriesByName): ?string
    {
        if ($row['category'] === null) {
            return null;
        }

        $current = $item?->category?->name;

        if ($current !== null && self::nameKey($row['category']) === self::nameKey($current)) {
            return null;
        }

        $match = $categoriesByName->get(self::nameKey($row['category']));

        if ($match !== null && $match->archived_at !== null) {
            return 'Category "'.$match->name.'" is archived. Restore it from Inventory before importing.';
        }

        return null;
    }

    /**
     * Rows describing the same product must agree on its category, unit and
     * description. Only non-blank cells participate (blank means "no opinion"),
     * and one error per conflicting field is reported.
     *
     * @param  list<array<string, mixed>>  $rowsForItem
     * @return list<array{row: int|null, messages: list<string>}>
     */
    private function conflictingFieldErrors(array $rowsForItem): array
    {
        $errors = [];

        foreach (['category', 'unit', 'description'] as $field) {
            $first = null;

            foreach ($rowsForItem as $row) {
                $value = $row[$field];

                if ($value === null) {
                    continue;
                }

                // Categories match case-insensitively everywhere else, so a
                // case-only difference is not a conflict.
                $comparable = $field === 'category' ? self::nameKey($value) : $value;

                if ($first === null) {
                    $first = ['value' => $value, 'comparable' => $comparable, 'line' => $row['line']];

                    continue;
                }

                if ($comparable !== $first['comparable']) {
                    $errors[] = ['row' => $row['line'], 'messages' => [
                        'Rows '.$first['line'].' and '.$row['line'].' both describe product "'.$rowsForItem[0]['product']
                        .'" but disagree on '.$field.' ("'.$first['value'].'" vs "'.$value.'"). '
                        .'Make them match, or leave all but one blank.',
                    ]];

                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Build the row-attributed error for an item that would end up mixing an
     * unnamed (no-variant) row with named ones. The blank variant can come from
     * either a file row or an existing unnamed variant not present in the file,
     * and the item may be brand-new or already recorded — each needs a different,
     * plain-language explanation, so pick the one that matches the situation.
     *
     * @param  array{item: Item|null, rows: list<array<string, mixed>>}  $group
     * @param  list<array<string, mixed>>  $rowsForItem
     * @return array{row: int|null, messages: list<string>}
     */
    private function variantMixError(array $group, array $rowsForItem): array
    {
        $item = $group['item'] ?? null;
        $product = $rowsForItem[0]['product'];

        // File rows for this product whose effective variant name is blank (a
        // blank cell that keeps an existing variant's name does not count).
        $blankRows = array_values(array_filter(
            $rowsForItem,
            fn (array $row) => $this->effectiveVariantName($row) === null
        ));

        // No blank row in the file means the blank comes from an existing unnamed
        // ("no variant") variant that the file leaves untouched while adding named
        // ones. The fix lives in Inventory, not the file.
        if ($blankRows === []) {
            return ['row' => $rowsForItem[0]['line'], 'messages' => [
                'Product "'.$product.'" already exists as a single product with no variants. '
                .'To give it variants, open it in Inventory, name its existing variant, then re-import these rows.',
            ]];
        }

        $line = $blankRows[0]['line'];

        // Brand-new product (name/SKU not recorded yet) whose only rows are blank:
        // these are the same item repeated, differing solely by SKU. This is the
        // common "duplicate rows" mistake — say so plainly.
        $allBlank = count($blankRows) === count($rowsForItem);
        if ($item === null && $allBlank && count($blankRows) > 1) {
            $lines = implode(', ', array_map(fn (array $row) => $row['line'], $blankRows));

            return ['row' => $line, 'messages' => [
                'Rows '.$lines.' all add a new product named "'.$product.'" with no variant, '
                .'so they describe the same item. Give each row a different variant name to keep '
                .'them as separate variants, or remove the duplicate row.',
            ]];
        }

        // Otherwise a blank row sits alongside named variants (named rows in the
        // file, or existing named variants on the item). Name that specific row.
        $sku = $blankRows[0]['sku'];

        return ['row' => $line, 'messages' => [
            'Product "'.$product.'" has a row with no variant name (SKU "'.$sku.'") together with '
            .'named variant rows. A product either has no variants (one blank row) or names every '
            .'row — give that row a variant name, or remove it.',
        ]];
    }

    /**
     * Case-folded key for item/category name comparisons. ASCII-only
     * strtolower() on purpose: it matches SQLite's NOCASE — the collation on
     * items_name_unique and on every name query in this importer — so PHP and
     * the database can never disagree about which names are "the same".
     * mb_strtolower() folds more (e.g. "É" -> "é"), which would let a lookup
     * miss an item the unique index then happily duplicates.
     */
    private static function nameKey(string $name): string
    {
        return strtolower($name);
    }

    /**
     * Every variant matching the file's SKUs (exact match — both sides are
     * canonically uppercase; archived included so validation can reject them
     * explicitly), with item and category eager-loaded, keyed by SKU. Loaded
     * once per import and shared by validateCatalogShape() and apply(), chunked
     * to stay below SQLite's variable limit.
     *
     * @param  list<string>  $skus
     * @return Collection<string, Variant>
     */
    private function variantsBySku(array $skus): Collection
    {
        $unique = array_values(array_unique($skus));

        return collect(array_chunk($unique, self::LOOKUP_CHUNK))
            ->flatMap(fn (array $chunk) => Variant::with('item.category')
                ->whereIn('sku', $chunk)
                ->get())
            ->keyBy('sku');
    }

    /**
     * Fetch items whose name matches any of the given product names (NOCASE),
     * keyed by nameKey(), chunked to stay below SQLite's variable limit.
     * Empty input yields an empty collection.
     *
     * @param  list<string>  $productNames
     * @return Collection<string, Item>
     */
    private function itemsByLowerName(array $productNames): Collection
    {
        $keys = array_values(array_unique(array_map(
            fn (string $name) => self::nameKey($name),
            $productNames
        )));

        return collect(array_chunk($keys, self::LOOKUP_CHUNK))
            ->flatMap(function (array $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                return Item::query()
                    ->with('category:id,name')
                    ->whereRaw('name COLLATE NOCASE in ('.$placeholders.')', $chunk)
                    // unit/description are compared by immutableFieldErrors when
                    // a new variant attaches to an existing item, so load them.
                    ->get(['id', 'name', 'archived_at', 'category_id', 'unit', 'description']);
            })
            ->keyBy(fn (Item $item) => self::nameKey($item->name));
    }

    /**
     * Fetch categories whose name matches any of the given names (NOCASE),
     * keyed by nameKey(). Null entries (blank cells) are skipped.
     *
     * @param  list<string|null>  $categoryNames
     * @return Collection<string, Category>
     */
    private function categoriesByLowerName(array $categoryNames): Collection
    {
        $keys = array_values(array_unique(array_map(
            fn (string $name) => self::nameKey($name),
            array_filter($categoryNames, fn ($name) => $name !== null)
        )));

        return collect(array_chunk($keys, self::LOOKUP_CHUNK))
            ->flatMap(function (array $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                return Category::query()
                    ->whereRaw('name COLLATE NOCASE in ('.$placeholders.')', $chunk)
                    ->get(['id', 'name', 'archived_at']);
            })
            ->keyBy(fn (Category $category) => self::nameKey($category->name));
    }

    /**
     * Apply validated rows. Runs inside a transaction so a NegativeInventoryException
     * (re-thrown as ImportRowException) rolls the whole batch back. Matched
     * variants come from the collection already loaded for validation instead
     * of a per-row query.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, Variant>  $variantsBySku  from variantsBySku()
     * @return list<array<string, mixed>> the pending stock adjustments this file
     *                                    asks for — the catalog is written here
     *                                    and now, the stock is not
     */
    private function apply(array $rows, User $user, bool $canViewCost, string $importId, Collection $variantsBySku): array
    {
        $queued = [];

        $categoryCache = [];
        $itemCache = [];

        foreach ($rows as $row) {
            $variant = $variantsBySku->get($row['sku']);

            if ($variant === null) {
                $item = $this->resolveItem($row, $itemCache, $categoryCache);
                $variant = new Variant(['item_id' => $item->getKey()]);
                $variant->sku = $row['sku'];
                $variant->name = $row['variant'];
                $variant->barcode = $row['barcode'];
            } else {
                // Update path: SKU, barcode, variant name and the item's
                // unit/description are immutable (validation rejected any
                // change), so nothing beyond price/cost/stock/threshold and the
                // category move below is written for a matched variant.
                $item = $variant->item;
            }

            // Category is the sole item-level field an update may change. Blank
            // keeps the current one; on an item this file just created the
            // target already matches, so this is a no-op there.
            $this->applyCategoryMove($row, $item, $categoryCache);

            // Unit/description are immutable on an existing item (validation
            // rejects any change), but on an item this import is *creating* a
            // later row may still fill a value the first row left blank — they
            // cannot conflict, since conflictingFieldErrors enforces agreement.
            if ($item->wasRecentlyCreated) {
                $itemUpdates = array_filter([
                    'unit' => $row['unit'],
                    'description' => $row['description'],
                ], fn ($value) => $value !== null);

                if ($itemUpdates !== []) {
                    $item->update($itemUpdates);
                }
            }

            $variant->selling_price = $row['selling_price'];

            if ($row['low_stock_threshold'] !== null) {
                $variant->low_stock_threshold = $row['low_stock_threshold'];
            } elseif (! $variant->exists) {
                $variant->low_stock_threshold = 0;
            }

            if ($canViewCost && $row['cost_present']) {
                $variant->cost = $row['cost'];
            }

            $variant->save();

            $pending = $this->applyStock($variant, $row, $importId);

            if ($pending !== null) {
                $queued[] = $pending;
            }
        }

        return $queued;
    }

    /**
     * Translate the row's mode + stock value into a signed delta and describe it
     * as a pending adjustment. Zero-delta rows produce nothing (no-op, not an
     * error).
     *
     * ## An import no longer moves stock, and this is where that changed
     *
     * This wrote a ledger entry per row until schema 21. It now returns a row for
     * {@see AdjustmentQueue}, and the stock moves when somebody at the branch
     * confirms it — in one go, through Approve all, which is the reason that
     * button exists at all: a file of three hundred products is one action, and
     * answering it three hundred times is not a workflow.
     *
     * Two consequences, both deliberate:
     *
     *  - **An import can no longer fail for want of stock.** Whether a
     *    subtraction fits is now asked when it is confirmed, so a file that would
     *    have gone negative imports cleanly and is refused later, per product,
     *    where the operator can approve part of it instead. `ImportRowException`
     *    is still thrown for everything else the file can get wrong.
     *  - **A REPLACE delta can go stale.** `stock - on hand right now` is fixed
     *    here and confirmed later, so sales in between shift it. Same trade as
     *    the manual adjust screen, and the same answer: the approver partially
     *    approves what fits. Modelling it would cost the queue two columns and
     *    make a partial approval meaningless.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function applyStock(Variant $variant, array $row, string $importId): ?array
    {
        $delta = $row['mode'] === InventoryCsvSchema::MODE_REPLACE
            ? $row['stock'] - $this->ledger->currentStock($variant)
            : $row['stock'];

        if ($delta === 0) {
            return null;
        }

        return [
            'branch_id' => $this->queue->thisBranch(),
            'variant_id' => (string) $variant->getKey(),
            'quantity' => $delta,
            'reference_type' => 'csv_import',
            // The import_history row, so an approved movement can still be traced
            // back to the file it came from — carried through the queue and onto
            // the ledger entry by the resolver.
            'reference_id' => $importId,
            'notes' => 'CSV import',
        ];
    }

    /**
     * Move an existing item into the row's category when a non-blank cell names
     * a different one (find-or-create via resolveCategoryId). Blank keeps the
     * current category; a brand-new item is already filed correctly, so the ids
     * match and nothing is written. Validation confirmed the target is active.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $categoryCache
     */
    private function applyCategoryMove(array $row, Item $item, array &$categoryCache): void
    {
        if ($row['category'] === null) {
            return;
        }

        $categoryId = $this->resolveCategoryId($row['category'], $categoryCache);

        if ($item->category_id !== $categoryId) {
            $item->update(['category_id' => $categoryId]);
        }
    }

    /**
     * @param  array<string, string>  $cache  name-lowercase => category id
     */
    private function resolveCategoryId(?string $name, array &$cache): ?string
    {
        if ($name === null) {
            return null;
        }

        $key = self::nameKey($name);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $category = Category::whereRaw('name = ? COLLATE NOCASE', [$name])->first()
            ?? Category::create(['name' => $name]);

        return $cache[$key] = $category->getKey();
    }

    /**
     * Find-or-create the Item a brand-new variant attaches to, matched by name
     * (case-insensitively). Item names are globally unique, so a row whose
     * product name already exists resolves to that item — validation has already
     * confirmed the row's category agrees with it. The row's category is only
     * resolved (and possibly created) when the item itself is created, so an
     * import never leaves an orphan category behind. Cached so several
     * new-variant rows of one product share a single item.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, Item>  $itemCache
     * @param  array<string, string>  $categoryCache
     */
    private function resolveItem(array $row, array &$itemCache, array &$categoryCache): Item
    {
        $key = self::nameKey($row['product']);

        if (isset($itemCache[$key])) {
            return $itemCache[$key];
        }

        $item = Item::active()
            ->whereRaw('name = ? COLLATE NOCASE', [$row['product']])
            ->first()
            ?? Item::create([
                'name' => $row['product'],
                'unit' => $row['unit'] ?? 'pc',
                'category_id' => $this->resolveCategoryId($row['category'], $categoryCache),
                'description' => $row['description'],
            ]);

        return $itemCache[$key] = $item;
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

    private function isValidMoney(string $value): bool
    {
        // Integer part capped at 10 digits: <= 9999999999.99, mirroring the
        // item form's max. Checked before any cast so nothing can saturate.
        return $value !== '' && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) === 1;
    }

    private function isInteger(string $value): bool
    {
        // At most 9 digits (|value| <= 999,999,999): far beyond any real stock
        // count and safely inside PHP's int range, so (int) casts are exact.
        return $value !== '' && preg_match('/^[+-]?\d{1,9}$/', $value) === 1;
    }

    /**
     * @param  list<array{row: int|null, messages: list<string>}>  $errors
     */
    private function record(
        string $id,
        User $user,
        ?string $filename,
        string $mode,
        string $status,
        int $totalRows,
        int $successCount,
        int $errorCount,
        array $errors,
    ): ImportHistory {
        // Set the id directly (it is guarded, so it must not go through mass
        // assignment) so it matches the reference_id already written onto the
        // import's ledger rows. HasUuids leaves a pre-set key untouched.
        $import = new ImportHistory([
            'user_id' => $user->getKey(),
            'filename' => $filename,
            'mode' => $mode,
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
     * Send a FAILED import row up on its own.
     *
     * The success row rides {@see mirrorImport()} inside the import's online
     * write, but every failure path records outside it — by then the write has
     * either not started or already unwound — so {@see OnlineWrite::mirror()}
     * has no write to attach to and would throw. This is a separate one-row
     * write instead.
     *
     * Best-effort on purpose. This screen is already gated on connectivity, so
     * it will nearly always land; but a failure to record a failure must never
     * become the user's error, and the local row — the one Upload History reads
     * — is already saved either way. What it buys is a REPLACEMENT device: since
     * M11.6 hydration is the only recovery path, and a row that never reached
     * Postgres is a row a rebuilt till cannot show.
     *
     * `import_history` is in {@see OnlineWrite::APPEND_ONLY}, so this lands as
     * `on conflict do nothing` and re-running it can never rewrite history.
     */
    private function mirrorFailure(ImportHistory $import): void
    {
        rescue(
            fn () => $this->online->run(
                fn () => $this->online->mirror($import),
                'A failed CSV import',
            ),
            report: false,
        );
    }
}
