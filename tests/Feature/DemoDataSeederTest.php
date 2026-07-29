<?php

namespace Tests\Feature;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The demo seeder must not write history into the future.
 *
 * It backdates ~6 weeks with `Carbon::setTestNow`, and TODAY is the day that
 * can go wrong: the fixed clock times it uses (sales in an 08:00–10:59 window,
 * a 07:5x cashier login) are ahead of the wall clock when it runs early. A row
 * stamped later than now outranks a sale the operator has just made in every
 * `created_at desc` list — which is how `commerce.spec.ts` came to open the
 * wrong receipt, but only when run before 11:00.
 *
 * ## Why each test seeds at the hour it does
 *
 * The seeder's times are random within their windows, so a seeding hour has to
 * be chosen to make the *unfixed* behaviour fail rather than merely be likely
 * to. 08:00 collapses today's sale window to a single instant, so an unclamped
 * draw over 08:00–10:59 essentially never lands inside it. 07:00 is before the
 * one login the seeder writes unconditionally every day, so an unguarded run
 * always leaves a login in the future. Both were confirmed by reverting each
 * half of the fix separately.
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Append-only history, and the column whose value must never lead the clock. */
    private const HISTORY = [
        'transactions' => 'created_at',
        'login_history' => 'occurred_at',
        'audit_logs' => 'created_at',
        'inventory_ledger' => 'created_at',
        'sale_returns' => 'created_at',
    ];

    public function test_no_history_is_stamped_ahead_of_the_clock(): void
    {
        // Opening time: today's window is [08:00, 08:00], so every seeded sale
        // must land on that exact second. Unclamped it is drawn from three
        // hours, and lands past it.
        $now = $this->seedAt('2026-07-29 08:00:00');

        foreach (self::HISTORY as $table => $column) {
            $latest = DB::table($table)->max($column);

            $this->assertNotNull($latest, "[{$table}] seeded nothing at all.");
            $this->assertLessThanOrEqual(
                $now->toDateTimeString(),
                $latest,
                "[{$table}] holds a row stamped after the seeder ran.",
            );
        }

        // The clamp must bound today's sales, not delete them.
        $this->assertSame(2, $this->salesOn($now), 'The morning trickle is missing.');
    }

    public function test_login_history_is_never_written_ahead_of_the_clock(): void
    {
        // Before the 07:50–07:59 cashier login, which is the one auth event
        // written for every day including today, with no probability attached.
        $now = $this->seedAt('2026-07-29 07:00:00');

        $this->assertLessThanOrEqual(
            $now->toDateTimeString(),
            DB::table('login_history')->max('occurred_at'),
            'A login was recorded for a moment that has not happened yet.',
        );

        // Before the shop opens there is no window to place a sale in, and
        // inventing one would put it in the future.
        $this->assertSame(0, $this->salesOn($now), 'Sales were seeded before opening time.');
    }

    public function test_today_still_gets_its_trickle_once_the_window_has_closed(): void
    {
        $now = $this->seedAt('2026-07-29 14:00:00');

        // Seeded after 11:00 the whole 08:00–10:59 window is in the past, so
        // the clamp does nothing and the seeder behaves as it always did.
        $this->assertSame(2, $this->salesOn($now), 'The morning trickle is missing.');
        $this->assertLessThanOrEqual(
            $now->toDateTimeString(),
            DB::table('transactions')->max('created_at'),
            'A sale was seeded after the seeder ran.',
        );
    }

    /**
     * Run the seeder as if it were `$at`. It clears `setTestNow` in its own
     * `finally`, so the boundary is returned rather than read back afterwards.
     */
    private function seedAt(string $at): Carbon
    {
        $now = Carbon::parse($at);

        Carbon::setTestNow($now);
        $this->seed(DemoDataSeeder::class);
        Carbon::setTestNow();

        return $now;
    }

    private function salesOn(Carbon $day): int
    {
        return DB::table('transactions')
            ->whereBetween('created_at', [
                $day->copy()->startOfDay()->toDateTimeString(),
                $day->copy()->endOfDay()->toDateTimeString(),
            ])
            ->count();
    }
}
