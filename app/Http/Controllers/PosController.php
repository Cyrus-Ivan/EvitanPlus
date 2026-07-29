<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeInventoryException;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\StockLedger;
use App\Support\Printing\PrinterSettings;
use App\Support\Printing\ReceiptPrinter;
use App\Support\Sales\TransactionNumberGenerator;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Point of Sale (Phase 8). Cashier-level: available to every authenticated user
 * (route `auth` group, never `role:admin`).
 *
 * Item cost is Owner-only, so it never leaves this controller: the grid and the
 * scan lookup map each variant to an explicit whitelist without `cost`, and the
 * checkout snapshots price/cost onto transaction items server-side from the
 * freshly-loaded variant — the client never sends money.
 */
class PosController extends Controller
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly TransactionNumberGenerator $numbers,
        private readonly PrinterSettings $printerSettings,
        private readonly ReceiptPrinter $receiptPrinter,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Paginated, searchable product grid. One tile per active variant of an
     * active item. Search matches product name, SKU or barcode.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', '')) ?: null;

        $products = Variant::query()
            // Qualify the column: the join brings in items.archived_at too, so an
            // unqualified whereNull('archived_at') (as the active() scope emits)
            // would be ambiguous.
            ->whereNull('variants.archived_at')
            ->with(['item:id,name,unit', 'stockSnapshot'])
            ->join('items', 'items.id', '=', 'variants.item_id')
            // The join already brings in items; filter archived items here rather
            // than with a separate whereHas subquery.
            ->whereNull('items.archived_at')
            ->when($search, fn ($query) => $query->whereLikeAny(['variants.sku', 'variants.barcode', 'items.name'], $search))
            ->orderBy('items.name')
            ->orderBy('variants.name')
            ->select('variants.*')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Variant $variant) => $this->presentProduct($variant));

        return Inertia::render('pos', [
            'products' => $products,
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Exact barcode/SKU lookup for a hardware scan (scanners emit Enter). Kept
     * separate from index() so a scan resolves a single product without
     * disturbing the grid's pagination. Returns null on a miss so the client can
     * fall through to a grid search.
     */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));

        if ($code === '') {
            return response()->json(['variant' => null]);
        }

        $variant = Variant::query()
            ->active()
            ->whereHas('item', fn ($query) => $query->active())
            ->with(['item:id,name,unit', 'stockSnapshot'])
            ->where(function ($query) use ($code) {
                $query->where('barcode', $code)
                    ->orWhere('sku', mb_strtoupper($code));
            })
            ->first();

        return response()->json([
            'variant' => $variant ? $this->presentProduct($variant) : null,
        ]);
    }

    /**
     * Persist a completed sale: create the transaction and its items (price and
     * cost snapshotted server-side), then deduct stock through the ledger. The
     * whole thing is one atomic unit — a mid-cart negative-stock failure rolls
     * back the transaction, every item and every prior deduction.
     */
    public function checkout(CheckoutRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Merge duplicate variants (client can add the same product twice) so we
        // write one item row and one ledger deduction per variant.
        $lines = collect($data['lines'])
            ->groupBy('variant_id')
            ->map(fn (Collection $group) => [
                'variant_id' => $group->first()['variant_id'],
                'quantity' => (int) $group->sum('quantity'),
            ])
            ->values();

        $transaction = null;

        // The transaction_number is a continuous serial derived from the highest
        // committed one; under concurrency two sales can collide, which the
        // UNIQUE index turns into a QueryException. Retry a few times with a
        // fresh number.
        for ($attempt = 1; ; $attempt++) {
            try {
                // One push batch for the whole sale (M6): the transaction, its
                // items, its ledger deductions and its audit row share a
                // group_id and reach Postgres inside one Postgres transaction.
                // A partially pushed sale is visible to consolidated reporting
                // as a sale with missing lines — a wrong total, not an obviously
                // absent one.
                $transaction = $this->outbox->group(
                    fn () => DB::transaction(fn () => $this->persistSale($data, $lines, $request)),
                );
                break;
            } catch (QueryException $e) {
                if ($attempt < 3 && str_contains($e->getMessage(), 'transaction_number')) {
                    continue;
                }

                throw $e;
            }
        }

        $number = $transaction->transaction_number;

        // Auto-print (Phase 11). The sale is already committed above; printing is
        // strictly best-effort and must never roll it back. A real device error
        // only downgrades the toast (returning false = no printer configured,
        // which is silent). The sale stays reprintable from Sales either way.
        $printFailed = false;

        if ($this->printerSettings->autoPrint()) {
            try {
                $this->receiptPrinter->print($transaction);
            } catch (\Throwable $e) {
                report($e);
                $printFailed = true;
            }
        }

        if ($printFailed) {
            return back()->with('toast', [
                'type' => 'warning',
                'message' => "Sale {$number} completed, but the receipt failed to print. Reprint it from Sales.",
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Sale {$number} completed.",
        ]);
    }

    /**
     * Create the transaction, its items and the stock deductions. Runs inside
     * the caller's DB::transaction. Returns the persisted transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, array{variant_id: string, quantity: int}>  $lines
     */
    private function persistSale(array $data, Collection $lines, Request $request): Transaction
    {
        // Reload fresh, active variants inside the transaction and key by id, so
        // prices/costs are the current authoritative values at sale time.
        $variants = Variant::query()
            ->active()
            // The variant itself may be active while its parent item is archived
            // (archiving an item does not cascade to variants), so re-check the
            // item here — the grid and lookup already hide these, but a stale
            // client or direct POST must not sell a discontinued product.
            ->whereHas('item', fn ($query) => $query->active())
            ->whereIn('id', $lines->pluck('variant_id'))
            ->get()
            ->keyBy('id');

        // All money in integer centavos to avoid float drift; converted back to
        // decimal strings only on write.
        $subtotal = 0;
        $rows = [];

        foreach ($lines as $line) {
            $variant = $variants->get($line['variant_id']);

            if ($variant === null) {
                // An archived-between-request race; treat as a stock/availability
                // failure rather than a 500.
                throw ValidationException::withMessages([
                    'lines' => 'A product in the cart is no longer available.',
                ]);
            }

            $unit = $this->centavos($variant->selling_price);
            $lineTotal = $unit * $line['quantity'];
            $subtotal += $lineTotal;

            $rows[] = [
                'variant' => $variant,
                'quantity' => $line['quantity'],
                'line_total' => $lineTotal,
            ];
        }

        $discountType = $data['discount_type'] ?? null;
        $discountInput = (float) ($data['discount'] ?? 0);

        $discount = match (true) {
            $discountInput <= 0 => 0,
            $discountType === 'percentage' => (int) round($subtotal * min($discountInput, 100) / 100),
            // Amount discount is capped at the subtotal so the total never goes
            // negative.
            default => min($this->centavos($discountInput), $subtotal),
        };

        $total = $subtotal - $discount;

        // What we persist in `discount`: for a percentage, the percent the
        // operator entered; for an amount, the effective (capped) pesos actually
        // taken off — never the raw over-cap figure, so a receipt never shows a
        // discount larger than the order. Null type + 0 when nothing applied.
        $hasDiscount = $discount > 0;
        $storedDiscountType = $hasDiscount ? $discountType : null;
        $storedDiscount = match (true) {
            ! $hasDiscount => '0.00',
            $discountType === 'percentage' => number_format($discountInput, 2, '.', ''),
            default => $this->pesos($discount),
        };

        $cashReceived = null;
        $change = null;

        if ($data['payment_type'] === 'cash') {
            $cashReceived = $this->centavos($data['cash_received'] ?? 0);

            if ($cashReceived < $total) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Cash received is less than the total due.',
                ]);
            }

            $change = $cashReceived - $total;
        }

        $number = $this->numbers->next();

        $transaction = Transaction::create([
            'transaction_number' => $number,
            'payment_type' => $data['payment_type'],
            'discount' => $storedDiscount,
            'discount_type' => $storedDiscountType,
            'total' => $this->pesos($total),
            'cash_received' => $cashReceived !== null ? $this->pesos($cashReceived) : null,
            'change' => $change !== null ? $this->pesos($change) : null,
            'comment' => $data['comment'] ?? null,
            'user_id' => $request->user()->getKey(),
        ]);

        foreach ($rows as $row) {
            /** @var Variant $variant */
            $variant = $row['variant'];

            TransactionItem::create([
                'transaction_id' => $transaction->getKey(),
                'variant_id' => $variant->getKey(),
                'quantity' => $row['quantity'],
                'selling_price' => $variant->selling_price,
                'cost' => $variant->cost,
                'discount' => '0.00',
                'total' => $this->pesos($row['line_total']),
            ]);

            try {
                $this->ledger->record(
                    variant: $variant,
                    quantity: -$row['quantity'],
                    type: 'sale',
                    saleType: 'sale',
                    referenceType: 'transaction',
                    referenceId: $transaction->getKey(),
                    performedBy: $request->user(),
                );
            } catch (NegativeInventoryException) {
                throw ValidationException::withMessages([
                    'lines' => "Not enough stock for {$variant->item->name}.",
                ]);
            }
        }

        // Audited inside the same transaction as the sale (parity with
        // ProcessSaleReturn): either both commit or neither — a sale can never
        // exist without its audit row, and an audit failure can never 500 a
        // sale that already committed.
        $this->audit->record(
            action: 'pos.sale.created',
            auditable: $transaction,
            description: "Recorded sale {$transaction->transaction_number}.",
            new: [
                'transaction_number' => $transaction->transaction_number,
                'total' => $transaction->total,
            ],
        );

        return $transaction;
    }

    /**
     * The cost-free shape sent to the POS grid and scan lookup.
     *
     * @return array<string, mixed>
     */
    private function presentProduct(Variant $variant): array
    {
        return [
            'id' => $variant->id,
            'label' => trim($variant->item->name.($variant->name ? ' — '.$variant->name : '')),
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'selling_price' => $variant->selling_price,
            'current_stock' => (int) ($variant->stockSnapshot?->quantity ?? 0),
            'unit' => $variant->item->unit,
        ];
    }

    /**
     * Convert a decimal-string/float peso value to integer centavos.
     */
    private function centavos(string|float|int $value): int
    {
        return (int) round((float) $value * 100);
    }

    /**
     * Convert integer centavos back to a 2-decimal peso string for storage.
     */
    private function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
