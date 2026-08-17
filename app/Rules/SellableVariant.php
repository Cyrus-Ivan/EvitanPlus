<?php

namespace App\Rules;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * A variant that is still on sale — the `exists` rule every path that MOVES
 * stock should have been using.
 *
 * Archiving is this app's delete (`CLAUDE.md` → "Never delete business
 * records"), so an archived variant is a product withdrawn from sale whose row
 * survives for history. Stock sent to one is stock nobody can sell, on no screen
 * (`/inventory` filters to active items) and still counted in the ledger sum.
 *
 * The rule was stated in three places and missing from four: `CheckoutRequest`
 * scoped its `exists` correctly and both importers refuse archived rows with
 * their own messages, while every path that ISSUES a movement — the manual
 * adjustment, the queued cross-branch adjustment, a transfer leg and every line
 * of a stock batch — used a bare `exists:variants,id`. So the one direction that
 * puts stock ON a shelf was the one that never checked the shelf was still in
 * use. One named rule so the four cannot drift apart again.
 *
 * ## The item's flag counts as much as the variant's
 *
 * A variant of an archived item is not on sale whatever its own `archived_at`
 * says — the same reading {@see Variant::scopeActive()} pairs with an `items`
 * join, and the one the POS grid takes. Both flags are checked here.
 */
final class SellableVariant
{
    /**
     * For a `variant_id`-style field validated by id.
     */
    public static function rule(): Exists
    {
        return Rule::exists('variants', 'id')
            ->whereNull('archived_at')
            // Correlated rather than a subselect list: `whereNotIn` with a
            // closure is not a shape the Exists rule types, and an item table
            // that grows makes the IN list grow with it.
            ->where(fn (Builder $query) => $query->whereNotExists(
                fn (Builder $item) => $item
                    ->selectRaw('1')
                    ->from('items')
                    ->whereColumn('items.id', 'variants.item_id')
                    ->whereNotNull('items.archived_at'),
            ));
    }
}
