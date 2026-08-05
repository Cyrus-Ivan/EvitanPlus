<?php

namespace App\Support\Inventory;

use App\Models\InventoryLedger;
use Illuminate\Database\Eloquent\Builder;

/**
 * Movement kind — the reading of a ledger row a person recognises
 * (docs/glossary.md §4 "Movement kind").
 *
 * `inventory_ledger` says what happened across THREE independent columns, and no
 * single one of them answers "what kind of movement was this". `type` alone puts
 * a recount, a CSV import and both legs of a transfer under one word, which is
 * every non-sale row there is since schema 21 made {@see AdjustmentResolver} the
 * only writer of `type = 'stock_adjustment'`. So each kind here is a PREDICATE
 * over the three columns rather than a value of any of them.
 *
 * This is a presentation and filter vocabulary, deliberately: nothing about sync
 * may ever key on it. Which way a row travels is decided by `type`
 * ({@see InventoryLedger::shouldPush()}, `Puller`'s filter,
 * `StockReconciler`'s sum), and a kind that looked like a fourth `type` value
 * would be neither pushed nor pulled.
 *
 * `resources/js/lib/stock-movement.ts` is the frontend twin — this class decides
 * which rows come back, that module decides what each one is called. Adding a
 * kind means adding it in both places.
 */
class MovementKind
{
    /**
     * Every kind, with the columns that identify it.
     *
     * Return and defect split on `sale_type` and NOT on `reference_type`: both
     * carry `reference_type = 'return'` (`App\Support\Sales\ProcessSaleReturn`),
     * so that column cannot tell them apart.
     *
     * A transfer is one kind here and two labels on screen — the sign says which
     * end this branch is, per §4's rule that the counterparty is not a direction.
     *
     * @var array<string, array<string, string>>
     */
    public const KINDS = [
        'sale' => ['type' => 'sale', 'sale_type' => 'sale'],
        'return' => ['type' => 'sale', 'sale_type' => 'return'],
        'defect' => ['type' => 'sale', 'sale_type' => 'defect'],
        'manual_adjustment' => ['type' => 'stock_adjustment', 'reference_type' => 'manual_adjustment'],
        'csv_import' => ['type' => 'stock_adjustment', 'reference_type' => 'csv_import'],
        'transfer' => ['type' => 'stock_adjustment', 'reference_type' => 'transfer'],
    ];

    /**
     * The kind named, or null for anything this class does not recognise —
     * including a stale `?type=stock_adjustment` bookmark from before the filter
     * spoke this vocabulary. An unknown filter shows everything, which is the
     * same thing the page does with no filter at all.
     */
    public static function parse(mixed $kind): ?string
    {
        return is_string($kind) && array_key_exists($kind, self::KINDS) ? $kind : null;
    }

    /**
     * Narrow a ledger query to one kind.
     *
     * @param  Builder<InventoryLedger>  $query
     */
    public static function apply(Builder $query, string $kind): void
    {
        foreach (self::conditions($kind) as $column => $value) {
            $query->where($column, $value);
        }
    }

    /**
     * The columns that identify one kind, for a caller that cannot take an
     * Eloquent builder.
     *
     * Stock History reads the ledger from Postgres in its wider branch scopes
     * (M11.8) through a plain query builder, and must filter it by exactly the
     * same predicate the local Eloquent path uses — a second copy of this map is
     * how the two views would come to disagree about what a "transfer" is.
     *
     * @return array<string, string>
     */
    public static function conditions(string $kind): array
    {
        return self::KINDS[$kind] ?? [];
    }

    /**
     * The kinds a filter may offer, in the order they appear on screen.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::KINDS);
    }
}
