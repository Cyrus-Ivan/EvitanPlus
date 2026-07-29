<?php

namespace App\Support\Sales;

use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Processes an after-sales event (return / defect) against a completed sale. The
 * whole write — quota validation → header → lines → ledger → snapshot — runs
 * inside one DB transaction so it is atomic, with the per-line quota re-checked
 * inside it to stay race-safe.
 *
 * Money is handled in integer centavos to avoid float drift. Refunds reflect what
 * the customer actually paid (line- and transaction-level discounts apportioned),
 * so a fully-returned line refunds exactly its paid share with no cent drift.
 *
 * See docs/returns-refunds-voids-design.md for the authoritative rules.
 */
class ProcessSaleReturn
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly SaleReturnNumberGenerator $numbers,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  string  $type  'return' | 'defect'
     * @param  list<array{transaction_item_id: string, quantity: int}>  $lines
     */
    public function process(
        Transaction $transaction,
        string $type,
        array $lines,
        ?string $reason,
        ?string $refundPaymentType,
        User $performedBy,
    ): SaleReturn {
        // Defects return no money, so a payment type is meaningless there.
        if ($type === 'defect') {
            $refundPaymentType = null;
        }

        // The return_number carries a daily sequence derived from a count; under
        // concurrency two events can collide, which the UNIQUE index turns into a
        // QueryException. Retry a few times with a fresh number.
        for ($attempt = 1; ; $attempt++) {
            try {
                // One push batch for the whole event (M6): the sale_return, its
                // lines, the restoring ledger entries and the audit row reach
                // Postgres together or not at all. A half-pushed return reads as
                // a return of fewer items, which is a wrong refund total in
                // consolidated reporting rather than an obviously missing row.
                return $this->outbox->group(fn () => DB::transaction(fn () => $this->write(
                    $transaction, $type, $lines, $reason, $refundPaymentType, $performedBy,
                )));
            } catch (QueryException $e) {
                if ($attempt < 3 && str_contains($e->getMessage(), 'return_number')) {
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * The atomic write. Runs inside the caller's DB::transaction.
     *
     * @param  list<array{transaction_item_id: string, quantity: int}>  $lines
     */
    private function write(
        Transaction $transaction,
        string $type,
        array $lines,
        ?string $reason,
        ?string $refundPaymentType,
        User $performedBy,
    ): SaleReturn {
        // Explicit stable order: the last line absorbs the apportionment
        // remainder, so the order must be identical on every later return
        // event — never left to SQLite's incidental row order.
        /** @var Collection<string, TransactionItem> $soldLines */
        $soldLines = $transaction->items()->orderBy('id')->get()->keyBy('id');

        if ($soldLines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'This transaction has no lines to act on.',
            ]);
        }

        // Paid share of the transaction total per sold line (transaction-level
        // discount apportioned), keyed by transaction_item id — all in centavos.
        $linePaid = $this->linePaidShares($transaction, $soldLines);

        // Merge duplicate references to one sold line so the quota is enforced
        // once against the combined quantity.
        $requested = collect($lines)
            ->groupBy('transaction_item_id')
            ->map(fn (Collection $group) => [
                'transaction_item_id' => (string) $group->first()['transaction_item_id'],
                'quantity' => (int) $group->sum('quantity'),
            ])
            ->values();

        // First pass: validate the quota and compute each line's refund, so the
        // header can be written once with its final total (sale_returns is
        // append-only — no post-insert edit).
        $resolved = [];
        $totalRefund = 0;

        foreach ($requested as $line) {
            /** @var TransactionItem|null $sold */
            $sold = $soldLines->get($line['transaction_item_id']);

            if ($sold === null) {
                throw ValidationException::withMessages([
                    'lines' => 'A line does not belong to this transaction.',
                ]);
            }

            $newQty = $line['quantity'];

            // Cumulative quota: returns and defects both consume sold qty.
            $already = (int) SaleReturnItem::query()
                ->where('transaction_item_id', $sold->getKey())
                ->sum('quantity');

            if ($newQty < 1 || $already + $newQty > $sold->quantity) {
                throw ValidationException::withMessages([
                    'lines' => "Return quantity exceeds what remains for {$sold->displayLabel()}.",
                ]);
            }

            $refund = $this->lineRefund($sold, $linePaid[$sold->getKey()], $already, $newQty);
            $totalRefund += $refund;

            $resolved[] = ['sold' => $sold, 'quantity' => $newQty, 'refund' => $refund];
        }

        $saleReturn = SaleReturn::create([
            'return_number' => $this->numbers->next(),
            'transaction_id' => $transaction->getKey(),
            'type' => $type,
            'reason' => $reason,
            'refund_payment_type' => $refundPaymentType,
            // Convenience snapshot only — reports still recompute from the lines.
            'total_refund' => $this->pesos($totalRefund),
            'performed_by' => $performedBy->getKey(),
        ]);

        foreach ($resolved as $line) {
            /** @var TransactionItem $sold */
            $sold = $line['sold'];

            SaleReturnItem::create([
                'sale_return_id' => $saleReturn->getKey(),
                'transaction_item_id' => $sold->getKey(),
                'variant_id' => $sold->variant_id,
                'quantity' => $line['quantity'],
                // Snapshot the captured price/cost — never the current variant values.
                'selling_price' => $sold->selling_price,
                'cost' => $sold->cost,
                'refund_amount' => $this->pesos($line['refund']),
            ]);

            // Returns restore stock; defects write a 0-movement audit row.
            $this->ledger->record(
                variant: $sold->variant,
                quantity: $type === 'defect' ? 0 : $line['quantity'],
                type: 'sale',
                saleType: $type === 'defect' ? 'defect' : 'return',
                referenceType: 'return',
                referenceId: $saleReturn->getKey(),
                performedBy: $performedBy,
            );
        }

        $this->audit->record(
            action: "sale.{$type}.created",
            auditable: $saleReturn,
            description: sprintf(
                'Processed %s %s against sale %s.',
                $type,
                $saleReturn->return_number,
                $transaction->transaction_number,
            ),
            new: [
                'type' => $type,
                'return_number' => $saleReturn->return_number,
                'total_refund' => $saleReturn->total_refund,
            ],
            actor: $performedBy,
        );

        return $saleReturn;
    }

    /**
     * Each sold line's paid share of the transaction total, in centavos, keyed by
     * transaction_item id. The transaction-level discount is apportioned across
     * lines by their share of the pre-discount line-total sum; the rounding
     * remainder lands on the final line so the shares sum to exactly the total.
     *
     * @param  Collection<string, TransactionItem>  $soldLines
     * @return array<string, int>
     */
    private function linePaidShares(Transaction $transaction, Collection $soldLines): array
    {
        $total = $this->centavos($transaction->total);
        $sumLineTotals = $soldLines->sum(fn (TransactionItem $item) => $this->centavos($item->total));

        $shares = [];
        $assigned = 0;
        $last = $soldLines->count() - 1;
        $i = 0;

        foreach ($soldLines as $id => $item) {
            if ($i === $last) {
                // Final line absorbs the rounding remainder so the shares sum to
                // exactly the transaction total.
                $shares[$id] = $total - $assigned;
            } else {
                $share = $sumLineTotals > 0
                    ? (int) round($total * $this->centavos($item->total) / $sumLineTotals)
                    : 0;
                $shares[$id] = $share;
                $assigned += $share;
            }

            $i++;
        }

        return $shares;
    }

    /**
     * Refund (centavos) for returning $newQty units of a sold line whose paid
     * share is $linePaid and which already has $already units returned.
     *
     * When this event returns the line's final outstanding units, the refund is
     * the paid share minus everything already refunded for the line, so cumulative
     * refunds for a fully-returned line equal its paid share exactly (no drift).
     */
    private function lineRefund(TransactionItem $sold, int $linePaid, int $already, int $newQty): int
    {
        if ($already + $newQty === $sold->quantity) {
            $priorRefunds = $this->centavos(
                SaleReturnItem::query()
                    ->where('transaction_item_id', $sold->getKey())
                    ->sum('refund_amount'),
            );

            return $linePaid - $priorRefunds;
        }

        return (int) round($linePaid * $newQty / $sold->quantity);
    }

    private function centavos(string|float|int $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
