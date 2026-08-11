<?php

namespace App\Support\Inventory;

/**
 * Single source of truth for the item CSV shape (Phase 7, narrowed in M13). The
 * template download and the importer read their columns from here so the two
 * stay in lockstep.
 *
 * Column order below is the canonical order for generated files. Cost is only
 * emitted for the Owner (see the controller), but it keeps a fixed slot so the
 * layout is stable whether or not it is present.
 *
 * ## `stock` and `mode` left in M13, and nothing replaced them
 *
 * This file describes PRODUCTS. Stock is per-branch, ledger-derived, and moves
 * only when somebody at the branch confirms it moved — none of which a
 * spreadsheet can assert. The `stock` column was already only ASKING by then
 * (schema 21 turned each row's delta into a pending adjustment rather than a
 * ledger entry), and `mode` existed solely to say what that number meant. With
 * the catalog and the shelf on separate screens, both columns described the
 * wrong screen.
 *
 * A newly imported product appears at zero, and how it gets its first count
 * depends on whether the branch has ever sold anything. Before its first sale,
 * {@see OpeningStockCsvSchema} — a separate two-column file, because that one
 * describes a shelf and this one describes products, and a column whose meaning
 * expired partway through the install's life is the thing M13 removed. After it,
 * the stock batch dialog on the Inventory page, one row per variant and
 * deliberately so: a stock figure on a shelf with a history is a claim somebody
 * has to go and look at.
 *
 * A leftover `stock` column here is still ignored, which is what lets one
 * spreadsheet be uploaded to both screens in turn.
 */
final class InventoryCsvSchema
{
    /** Every recognised column, in canonical order. */
    public const COLUMNS = [
        'category',
        'product',
        'variant',
        'sku',
        'barcode',
        'selling_price',
        'cost',
        'unit',
        'description',
        'low_stock_threshold',
    ];

    /** Columns that must carry a value on every data row. */
    public const REQUIRED = [
        'product',
        'sku',
        'selling_price',
    ];

    /**
     * Header row for a generated file. Cost is dropped for non-Owners so the
     * value never even leaves the server.
     *
     * @return list<string>
     */
    public static function headers(bool $includeCost = true): array
    {
        return array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => $includeCost || $column !== 'cost',
        ));
    }

    /**
     * A single illustrative row for the downloadable template, keyed by column.
     *
     * @return array<string, string>
     */
    public static function exampleRow(): array
    {
        return [
            'category' => 'Beverages',
            'product' => 'Bottled Water',
            'variant' => '500ml',
            'sku' => 'WATER-500',
            'barcode' => '4801234567890',
            'selling_price' => '15.00',
            'cost' => '9.50',
            'unit' => 'pc',
            'description' => 'Purified drinking water',
            'low_stock_threshold' => '12',
        ];
    }

    /**
     * Normalise a raw header cell to its canonical key: trim, lowercase, and
     * fold spaces/hyphens to underscores so "Selling Price", "selling-price"
     * and "selling_price" all match.
     */
    public static function normalizeHeader(string $header): string
    {
        // Excel's "CSV UTF-8" prepends a BOM to the file, which lands on the
        // first header cell; trim() does not remove it, so strip it explicitly
        // or the first column is silently unrecognized.
        $header = (string) preg_replace('/^\x{FEFF}/u', '', $header);
        $header = strtolower(trim($header));

        return (string) preg_replace('/[\s-]+/', '_', $header);
    }
}
