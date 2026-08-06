<?php

namespace App\Support\Sales;

use App\Models\Instance;
use App\Models\SaleReturn;

/**
 * Builds the number for an after-sales event (return / defect).
 *
 * Format: RET-YYYYMMDD-000001 — a per-day sequence that resets each day, mirroring
 * the human-readable receipt shape. Unlike the never-resetting transaction serial,
 * returns are scoped and counted per calendar day.
 *
 * ## No branch prefix, unlike the transaction serial
 *
 * MULTIBRANCH-BRAINSTORM.md §12 asks for the branch code on the *receipt serial*
 * and says nothing about this number, and Postgres already scopes uniqueness to
 * `(branch_id, return_number)` — two branches issuing `RET-20260729-000001` on
 * the same day is expected and correct. Adding a prefix here would buy nothing
 * and make the number a mouthful.
 *
 * ## The floor exists for exactly one day
 *
 * A device replacing an existing branch's hardware would restart its daily
 * counter at 1 against numbers Postgres already holds for today — and the push
 * is insert-only, so those returns would be silently dropped. (Since M11.6 the
 * hydrate copies this branch's `sale_returns` too, so the local max usually
 * answers on its own; the floor is read in the same breath and costs nothing.)
 * `instance.return_number_floor` is the highest number Postgres held
 * for this branch at hydration; it only applies while it is still the same
 * calendar day, because after midnight the prefix changes and the collision
 * cannot recur.
 *
 * The sequence continues from today's highest issued number (MAX over the
 * number itself, not a row count — a count would collide for the rest of the
 * day if a row were ever lost; MAX self-heals). Concurrency safety does not
 * live here: the caller wraps generate + insert in a bounded retry and leans on
 * the `return_number` UNIQUE index as the integrity backstop (SQLite serializes
 * writers anyway).
 */
class SaleReturnNumberGenerator
{
    /** Zero-pad width of the daily sequence. */
    private const PAD_WIDTH = 6;

    public function next(): string
    {
        $prefix = 'RET-'.today()->format('Ymd').'-';

        // Fixed-width zero padding makes the lexicographic MAX the numeric MAX.
        $highest = (string) SaleReturn::query()
            ->where('return_number', 'like', $prefix.'%')
            ->max('return_number');

        $instance = Instance::current();
        $floor = $instance === null ? '' : (string) $instance->return_number_floor;

        // Yesterday's floor is not a floor: the day prefix has moved on and
        // nothing Postgres holds for today can be higher than nothing.
        if (! str_starts_with($floor, $prefix)) {
            $floor = '';
        }

        $sequence = max($this->sequenceOf($highest), $this->sequenceOf($floor));

        return sprintf('%s%0'.self::PAD_WIDTH.'d', $prefix, $sequence + 1);
    }

    private function sequenceOf(string $number): int
    {
        return $number === '' ? 0 : (int) substr($number, -self::PAD_WIDTH);
    }
}
