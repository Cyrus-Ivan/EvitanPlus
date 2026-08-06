<?php

namespace App\Support\Sales;

use App\Models\Instance;
use App\Models\Transaction;

/**
 * Builds the transaction/receipt number for a sale.
 *
 * Format: the branch code, then a continuous, zero-padded running serial —
 * `MNL-0000000001`, `MNL-0000000002`, … It never resets (no date component; the
 * timestamp lives in `created_at`) and has no gaps across committed sales. This
 * is the shape a BIR-registered CRM/POS document serial must take — unique,
 * consecutive, non-resetting — so this one number serves as both the internal
 * transaction id and the customer-facing receipt number.
 *
 * ## The prefix is load-bearing (MULTIBRANCH-BRAINSTORM.md §12)
 *
 * Each branch generates its own serial locally, because it has to work offline,
 * so two branches both counting from 1 is expected and correct. The branch code
 * is the only thing making receipt numbers unique business-wide — which is why
 * Postgres scopes the unique index to `(branch_id, transaction_number)` and not
 * to the number alone.
 *
 * The prefix is STORED, not added at print time. One number with one spelling
 * everywhere — search, receipt, sales list, consolidated reporting — beats a
 * stored value that differs from the value a customer reads back off a receipt.
 *
 * ## Two ways this fails silently, both handled here
 *
 * **The cast.** `(int) "MNL-0000000009"` is **0**, so a generator that fed the
 * prefixed max straight to `(int)` would return serial 1 for every sale, forever.
 * That does not surface as an error: it collides on the unique index, which the
 * caller's bounded retry absorbs, and presents as checkouts intermittently
 * failing for no stated reason. The prefix is stripped before the cast.
 *
 * **The floor.** A device replacing an existing branch's hardware would restart
 * at 1 against serials Postgres already holds, and since the push is
 * insert-only those sales would be quietly dropped on the way up.
 * `instance.transaction_number_floor` is the highest serial Postgres held for
 * this branch at hydration, and the sequence continues from whichever of the two
 * is higher. Since M11.6 the hydrate copies this branch's `transactions` too, so
 * the local max is no longer empty on a rebuilt till and usually answers on its
 * own — but the floor is read in the same breath and costs nothing, and it stays
 * correct however the copy is scoped later.
 *
 * The sequence continues from a MAX, not a row count: a count would collide
 * forever if a row were ever lost to manual SQL or a partial restore — MAX
 * self-heals. Concurrency safety does not live here: the caller wraps generate +
 * insert in a bounded retry and leans on the `transaction_number` UNIQUE index
 * as the integrity backstop (there is no counter row to lock; SQLite serializes
 * writers anyway). Committed serials stay gapless because a rolled-back sale
 * never claims a number.
 *
 * TODO(compliance): a real BIR Authority-To-Print (ATP) range needs a
 * configurable series prefix and starting serial (per-registration). Not built
 * here — the format is range-ready but starts at 1.
 */
class TransactionNumberGenerator
{
    /** Zero-pad width of the running serial. */
    private const PAD_WIDTH = 10;

    public function next(): string
    {
        $instance = Instance::current();

        // No identity: a device that has never been through the wizard. It has
        // no branch to name, and nothing it writes will ever be pushed.
        $prefix = '';
        $floor = '';

        if ($instance !== null) {
            $prefix = $instance->branch_code.'-';
            $floor = (string) $instance->transaction_number_floor;
        }

        // Fixed-width zero padding makes the lexicographic MAX the numeric MAX,
        // and every candidate carries the same prefix, so comparing the strings
        // is comparing the serials.
        $highest = (string) Transaction::query()->max('transaction_number');

        $serial = max($this->serialOf($highest, $prefix), $this->serialOf($floor, $prefix));

        return $prefix.sprintf('%0'.self::PAD_WIDTH.'d', $serial + 1);
    }

    /**
     * The numeric part of a stored number.
     *
     * Tolerates a value without the expected prefix rather than returning 0 for
     * it: the sales history of a device set up before it had an identity, or one
     * whose branch code changed, must not be read as "no sales yet" — that would
     * hand out a serial that is already taken.
     */
    private function serialOf(string $number, string $prefix): int
    {
        if ($number === '') {
            return 0;
        }

        if ($prefix !== '' && str_starts_with($number, $prefix)) {
            return (int) substr($number, strlen($prefix));
        }

        // Fall back to the trailing digits, which is what an unprefixed serial
        // is entirely.
        return (int) (preg_match('/(\d+)$/', $number, $matches) === 1 ? $matches[1] : '0');
    }
}
