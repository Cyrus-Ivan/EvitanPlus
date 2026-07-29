<?php

namespace Tests\Feature\Sync;

use App\Models\Instance;
use App\Models\SaleReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Sales\SaleReturnNumberGenerator;
use App\Support\Sales\TransactionNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The branch-prefixed serial and the serial floor (M6; §12).
 *
 * Both halves fail SILENTLY if wrong, which is why they are pinned separately
 * from the gapless-serial tests in tests/Feature/Pos:
 *
 *  - the cast: `(int) "TST-0000000009"` is 0, so a generator that skipped the
 *    strip would hand out serial 1 forever. That does not raise — it collides on
 *    the unique index, the checkout's bounded retry absorbs it, and the symptom
 *    is sales that intermittently fail for no stated reason.
 *  - the floor: a device replacing existing hardware hydrates with no history,
 *    restarts at 1, and — because the push is insert-only — has those sales
 *    dropped on the way up with a green sync and a zero backlog to say so.
 */
class BranchSerialTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): TransactionNumberGenerator
    {
        return app(TransactionNumberGenerator::class);
    }

    public function test_the_serial_carries_this_branch_code(): void
    {
        $this->assertSame('TST-0000000001', $this->generator()->next());
    }

    public function test_the_prefix_is_stripped_before_the_increment(): void
    {
        Transaction::factory()->create([
            'transaction_number' => 'TST-0000000009',
            'user_id' => User::factory()->cashier()->create()->id,
        ]);

        // The whole point. A generator that fed the prefixed max to (int) would
        // answer TST-0000000001 here and collide, forever.
        $this->assertSame('TST-0000000010', $this->generator()->next());
    }

    public function test_a_replacement_device_continues_from_the_floor(): void
    {
        // Hydrated, no local history — a device swapped in for a branch that has
        // been trading for months.
        Instance::query()->where('id', Instance::ID)->update(['transaction_number_floor' => 'TST-0000004821']);

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('TST-0000004822', $this->generator()->next());
    }

    public function test_local_history_outranks_a_stale_floor(): void
    {
        Instance::query()->where('id', Instance::ID)->update(['transaction_number_floor' => 'TST-0000000005']);

        Transaction::factory()->create([
            'transaction_number' => 'TST-0000000042',
            'user_id' => User::factory()->cashier()->create()->id,
        ]);

        // The floor is a starting point, not a ceiling: once the device has
        // outrun it, it stops mattering.
        $this->assertSame('TST-0000000043', $this->generator()->next());
    }

    public function test_serials_written_before_this_device_had_an_identity_still_count(): void
    {
        // The v1.0.0 shape, on a database that predates the branch prefix.
        Transaction::factory()->create([
            'transaction_number' => '0000000007',
            'user_id' => User::factory()->cashier()->create()->id,
        ]);

        // Reading these as "no sales yet" would hand out a serial that is taken.
        $this->assertSame('TST-0000000008', $this->generator()->next());
    }

    public function test_a_device_with_no_identity_keeps_the_unprefixed_shape(): void
    {
        Instance::query()->delete();

        $this->assertSame('0000000001', $this->generator()->next());
    }

    public function test_the_return_number_takes_the_floor_only_for_today(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 09:00:00'));

        Instance::query()->where('id', Instance::ID)->update(['return_number_floor' => 'RET-20260729-000031']);

        $this->assertSame('RET-20260729-000032', app(SaleReturnNumberGenerator::class)->next());

        // Tomorrow the day prefix moves on and the floor stops applying: nothing
        // Postgres holds for a new day can be ahead of a counter starting at 1.
        $this->travelTo(Carbon::parse('2026-07-30 09:00:00'));

        $this->assertSame('RET-20260730-000001', app(SaleReturnNumberGenerator::class)->next());

        $this->travelBack();
    }

    public function test_the_return_number_carries_no_branch_prefix(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 09:00:00'));

        // Postgres scopes return_number uniqueness to (branch_id, return_number),
        // so two branches issuing the same daily number is expected. §12 asks
        // for the prefix on the receipt serial and nothing else.
        $this->assertSame('RET-20260729-000001', app(SaleReturnNumberGenerator::class)->next());

        $this->travelBack();
    }

    public function test_the_return_sequence_still_continues_from_local_history(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 09:00:00'));

        SaleReturn::factory()->create(['return_number' => 'RET-20260729-000004']);

        $this->assertSame('RET-20260729-000005', app(SaleReturnNumberGenerator::class)->next());

        $this->travelBack();
    }
}
