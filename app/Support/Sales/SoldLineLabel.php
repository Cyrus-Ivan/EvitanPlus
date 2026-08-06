<?php

namespace App\Support\Sales;

use App\Models\TransactionItem;
use App\Models\Variant;

/**
 * How a sold line names its product.
 *
 * "Item — Variant", or just the item when the variant is the unnamed one every
 * single-product item carries (CLAUDE.md, "Identity & keys"), or "Unknown
 * product" when the variant cannot be resolved at all.
 *
 * Extracted in M12 because there is now a second caller that has no model to ask.
 * {@see TransactionItem::displayLabel()} answers from its own
 * `variant.item` relation, which is the right shape for the local sales detail;
 * a CROSS-BRANCH sale is read as query-builder rows off Postgres and its labels
 * are resolved from the local catalog afterwards, so it holds a `Variant` and
 * nothing else. Both must produce the same string — the receipt printer passes
 * its own separator through the same rule — and two copies of "when is the
 * variant name appended" would drift the first time one of them was touched.
 */
class SoldLineLabel
{
    public static function for(?Variant $variant, string $separator = ' — '): string
    {
        $item = $variant?->item;
        $name = $item === null ? 'Unknown product' : $item->name;
        $variantName = $variant?->name;

        return trim($name.($variantName ? $separator.$variantName : ''));
    }
}
