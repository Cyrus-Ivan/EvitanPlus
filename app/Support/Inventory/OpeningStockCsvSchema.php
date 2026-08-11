<?php

namespace App\Support\Inventory;

/**
 * Single source of truth for the opening-stock CSV shape, as
 * {@see InventoryCsvSchema} is for the item file. Two columns, both required.
 *
 * ## Why this is a second file and not two more columns on the first
 *
 * The item file describes PRODUCTS and is the same at every branch; this one
 * describes ONE BRANCH'S SHELF. Folding them back together would put a column on
 * the item file whose meaning changed over the life of the install — honoured
 * during {@see OpeningWindow}, ignored after it — which is a file that works
 * once and then silently does nothing, and is close to what M13 removed `stock`
 * and `mode` for.
 *
 * ## Unrecognised columns are ignored, and that is the point
 *
 * The reader takes `sku` and `quantity` and does not care what else is in the
 * file. So one spreadsheet carrying the full item layout PLUS a quantity column
 * can be uploaded twice — once to Items, which ignores `quantity`, and once
 * here, which ignores everything else. Onboarding a new business is then one
 * spreadsheet, and onboarding a new branch is the same spreadsheet with the
 * numbers changed.
 *
 * Header normalisation is {@see InventoryCsvSchema::normalizeHeader()} rather
 * than a copy: both files are the same CSV dialect, and a BOM stripped in one
 * place and not the other is a first column that silently disappears.
 */
final class OpeningStockCsvSchema
{
    /** Every recognised column, in canonical order. */
    public const COLUMNS = [
        'sku',
        'quantity',
    ];

    /** Columns that must be present in the header. Both of them. */
    public const REQUIRED = [
        'sku',
        'quantity',
    ];

    /** Largest opening count a single line may assert. */
    public const MAX_QUANTITY = 999999;

    /**
     * Header row for the downloadable template.
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return self::COLUMNS;
    }

    /**
     * A single illustrative row, keyed by column. The SKU matches the item
     * template's so the two files read as a pair.
     *
     * @return array<string, string>
     */
    public static function exampleRow(): array
    {
        return [
            'sku' => 'WATER-500',
            'quantity' => '24',
        ];
    }

    /**
     * Normalise a raw header cell to its canonical key. Delegated on purpose —
     * see the class docblock.
     */
    public static function normalizeHeader(string $header): string
    {
        return InventoryCsvSchema::normalizeHeader($header);
    }
}
