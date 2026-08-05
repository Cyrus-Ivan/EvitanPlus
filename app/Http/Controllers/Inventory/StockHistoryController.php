<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Concerns\NormalizesDateFilter;
use App\Http\Controllers\Controller;
use App\Models\InventoryLedger;
use App\Models\Variant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock History (Phase 6). Read-only view over the append-only inventory_ledger.
 * Available at Cashier level: the ledger carries no cost, so it is safe for
 * every authenticated user (Cashiers have view-only inventory).
 *
 * One page serves both entry points — an optional `variant` filter turns the
 * global movements list into a per-variant drill-down.
 */
class StockHistoryController extends Controller
{
    use NormalizesDateFilter;

    /** Allowed inventory_ledger.type values. */
    private const TYPES = ['sale', 'stock_adjustment'];

    public function index(Request $request): Response
    {
        $variantFilter = $request->query('variant') ?: null;
        $typeFilter = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : null;
        $dateFrom = $this->normalizeDate($request->query('date_from'));
        $dateTo = $this->normalizeDate($request->query('date_to'));

        $entries = InventoryLedger::query()
            ->with(['variant:id,item_id,name', 'variant.item:id,name', 'performedBy:id,name,username'])
            ->when($variantFilter, fn ($query) => $query->where('variant_id', $variantFilter))
            ->when($typeFilter, fn ($query) => $query->where('type', $typeFilter))
            ->when($dateFrom, fn ($query) => $query->where('created_at', '>=', $dateFrom->startOfDay()))
            ->when($dateTo, fn ($query) => $query->where('created_at', '<=', $dateTo->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (InventoryLedger $entry) => [
                'id' => $entry->id,
                'quantity' => $entry->quantity,
                'resulting_snapshot' => $entry->resulting_snapshot,
                'type' => $entry->type,
                'sale_type' => $entry->sale_type,
                'reference_type' => $entry->reference_type,
                // The branch that asked, or the other end of a transfer. Stored
                // as a CODE on the row itself precisely so this page keeps
                // working offline: `branches` is Postgres-only and never pulled,
                // so an id could not be turned into a name here.
                'counterparty_branch_code' => $entry->counterparty_branch_code,
                'notes' => $entry->notes,
                'created_at' => $entry->created_at,
                'variant' => $entry->variant ? [
                    'id' => $entry->variant->id,
                    'name' => $entry->variant->name,
                    'item' => $entry->variant->item?->name,
                ] : null,
                'performed_by' => $entry->performedBy?->name,
            ]);

        return Inertia::render('inventory/stock-history', [
            'entries' => $entries,
            // Variants for the filter dropdown; archived included so their history
            // stays filterable. Item name is joined in for a readable label.
            'variants' => Variant::query()
                ->with('item:id,name')
                ->orderBy('name')
                ->get(['id', 'item_id', 'name'])
                ->map(fn (Variant $variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'item' => $variant->item?->name,
                ]),
            'types' => self::TYPES,
            'filters' => [
                'variant' => $variantFilter,
                'type' => $typeFilter,
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
            ],
        ]);
    }
}
