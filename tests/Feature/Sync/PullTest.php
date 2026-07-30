<?php

namespace Tests\Feature\Sync;

use App\Models\Category;
use App\Models\Item;
use App\Models\SyncCursor;
use App\Models\SyncOutboxEntry;
use App\Models\User;
use App\Support\Sync\Puller;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sync\Concerns\FakesTheBusinessDatabase;
use Tests\TestCase;

/**
 * The delta pull: what comes down, how far, and what it must not disturb.
 *
 * Every test here is about the CURSOR or about the local write semantics —
 * the algorithm, against the stand-in business database. Postgres being Postgres
 * (timestamptz microseconds, 't'/'f' booleans, the grants) is the round trip,
 * and is proved in tests/Integration/PullTest.php. Splitting it that way is
 * deliberate: the traps in this class are logic traps and reproduce anywhere,
 * while the traps in that one reproduce nowhere but against a real server.
 */
class PullTest extends TestCase
{
    use FakesTheBusinessDatabase;
    use RefreshDatabase;

    private const BRANCH = '00000000-0000-0000-0000-0000000b4a4c';

    private Connection $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->remote = $this->businessDatabase();
    }

    private function pull(): array
    {
        return app(Puller::class)->pull($this->remote, self::BRANCH);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function upstreamCategory(string $name, string $at, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        $this->remote->table('categories')->insert([
            'id' => $id,
            'name' => $name,
            'archived_at' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ] + $overrides);

        return $id;
    }

    public function test_a_first_pull_takes_everything_and_bookmarks_the_newest_row(): void
    {
        $this->upstreamCategory('Drinks', '2026-07-30 09:00:00');
        $this->upstreamCategory('Snacks', '2026-07-30 11:30:00');

        $applied = $this->pull();

        $this->assertSame(2, $applied['categories']);
        $this->assertSame(['Drinks', 'Snacks'], Category::query()->orderBy('name')->pluck('name')->all());

        // The bookmark is the newest row READ, which is what makes the next pull
        // resume from the data rather than from a clock.
        $this->assertSame('2026-07-30 11:30:00', SyncCursor::positionOf('categories'));
    }

    public function test_a_second_pull_reads_the_boundary_and_what_is_new_not_the_whole_table(): void
    {
        foreach (['Drinks' => '09:00:00', 'Snacks' => '10:00:00', 'Household' => '11:00:00'] as $name => $time) {
            $this->upstreamCategory($name, "2026-07-30 {$time}");
        }

        $this->pull();

        $this->upstreamCategory('Frozen', '2026-07-30 14:00:00');

        $applied = $this->pull();

        // TWO, not one and not four — and the two is the honest cost of `>=`.
        // The new row, plus the boundary row re-read because it shares the
        // bookmark's timestamp. That re-read is exactly what stops a row edited
        // in the same instant from being stranded (see the next test); paying
        // for it here is the trade. What matters is that the other two rows are
        // NOT read: a catalog of ten thousand products costs the changed ones
        // every five minutes rather than ten thousand.
        $this->assertSame(2, $applied['categories']);
        $this->assertSame('2026-07-30 14:00:00', SyncCursor::positionOf('categories'));
        $this->assertSame(4, Category::query()->count());
    }

    public function test_rows_sharing_the_boundary_timestamp_are_not_skipped(): void
    {
        // The reason the comparison is `>=` and not `>`. Two rows edited in the
        // same instant carry the same timestamp; with `>` the second one lands
        // behind the bookmark the first one set and is never read again — no
        // error, no gap, nothing to notice. This test is the whole argument.
        $this->upstreamCategory('Drinks', '2026-07-30 09:00:00');
        $this->pull();

        $this->upstreamCategory('Tied', '2026-07-30 09:00:00');

        $this->pull();

        $this->assertTrue(Category::query()->where('name', 'Tied')->exists());
    }

    public function test_a_repeated_row_is_absorbed_rather_than_duplicated(): void
    {
        // The cost of `>=` is re-reading the boundary row every cycle. It has to
        // be free, or the fix for one bug would be a slow leak of another.
        $this->upstreamCategory('Drinks', '2026-07-30 09:00:00');

        $this->pull();
        $this->pull();
        $this->pull();

        $this->assertSame(1, Category::query()->count());
    }

    public function test_an_upstream_edit_overwrites_the_local_copy(): void
    {
        $id = $this->upstreamCategory('Drnks', '2026-07-30 09:00:00');
        $this->pull();

        $this->remote->table('categories')
            ->where('id', $id)
            ->update(['name' => 'Drinks', 'updated_at' => '2026-07-30 10:00:00']);

        $this->pull();

        $this->assertSame('Drinks', Category::query()->find($id)?->name);
        $this->assertSame(1, Category::query()->count());
    }

    public function test_archiving_arrives_as_an_ordinary_update(): void
    {
        // Why a watermark pull is sufficient at all. Nothing is ever deleted —
        // categories, items and variants archive, users soft-delete — so every
        // disappearance is an UPDATE and rides the same cursor. A schema that
        // really deleted rows would need tombstones and this class would not work.
        $id = $this->upstreamCategory('Seasonal', '2026-07-30 09:00:00');
        $this->pull();

        $this->remote->table('categories')
            ->where('id', $id)
            ->update(['archived_at' => '2026-07-30 10:00:00', 'updated_at' => '2026-07-30 10:00:00']);

        $this->pull();

        $this->assertNotNull(DB::table('categories')->where('id', $id)->value('archived_at'));
    }

    public function test_a_soft_deleted_user_arrives_and_stays_deleted(): void
    {
        $id = (string) Str::uuid();

        $this->remote->table('users')->insert([
            'id' => $id,
            'name' => 'Departed',
            'username' => 'departed',
            'password' => 'hashed-already',
            'role' => 'cashier',
            'must_change_password' => 0,
            'created_at' => '2026-07-30 09:00:00',
            'updated_at' => '2026-07-30 10:00:00',
            'deleted_at' => '2026-07-30 10:00:00',
        ]);

        $this->pull();

        // Written through the query builder precisely so the SoftDeletes scope
        // cannot hide the deleted_at update being pulled, and so the `hashed`
        // cast cannot re-hash a hash.
        $this->assertNotNull(User::withTrashed()->find($id)?->deleted_at);
        $this->assertSame('hashed-already', User::withTrashed()->find($id)?->password);
        $this->assertNull(User::query()->find($id));
    }

    public function test_settings_are_restricted_to_the_business_keys_and_leave_the_printer_alone(): void
    {
        DB::table('settings')->insert([
            'id' => (string) Str::uuid(),
            'key' => 'printer_name',
            'value' => 'EPSON-TM-T20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([['business_name', 'Evitan Manila'], ['printer_name', 'SOMEONE-ELSES-PRINTER']] as [$key, $value]) {
            $this->remote->table('settings')->insert([
                'id' => (string) Str::uuid(),
                'key' => $key,
                'value' => $value,
                'created_at' => '2026-07-30 09:00:00',
                'updated_at' => '2026-07-30 09:00:00',
            ]);
        }

        $this->pull();

        // The local settings table is deliberately WIDER than Postgres: it also
        // holds this device's printer, which is hardware. A pull that carried
        // every key would retune the receipt printer of every branch in the
        // business to whatever one of them last saved.
        $this->assertSame('Evitan Manila', DB::table('settings')->where('key', 'business_name')->value('value'));
        $this->assertSame('EPSON-TM-T20', DB::table('settings')->where('key', 'printer_name')->value('value'));
    }

    public function test_settings_are_matched_on_key_not_id(): void
    {
        // A fresh install writes its own defaults with its own ids. Matching on
        // id would insert a second row for the same key rather than update the
        // one that is there, and every read after that is a coin toss.
        DB::table('settings')->insert([
            'id' => (string) Str::uuid(),
            'key' => 'business_name',
            'value' => 'Placeholder',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->remote->table('settings')->insert([
            'id' => (string) Str::uuid(),
            'key' => 'business_name',
            'value' => 'Evitan Manila',
            'created_at' => '2026-07-30 09:00:00',
            'updated_at' => '2026-07-30 09:00:00',
        ]);

        $this->pull();

        $this->assertSame(1, DB::table('settings')->where('key', 'business_name')->count());
        $this->assertSame('Evitan Manila', DB::table('settings')->where('key', 'business_name')->value('value'));
    }

    public function test_only_this_branchs_stock_adjustments_come_down(): void
    {
        [$variant, $user] = $this->catalogFixture();

        foreach ([
            ['branch' => self::BRANCH, 'type' => 'stock_adjustment', 'quantity' => 12],
            ['branch' => self::BRANCH, 'type' => 'sale', 'quantity' => -3],
            ['branch' => 'another-branch-entirely', 'type' => 'stock_adjustment', 'quantity' => 99],
        ] as $row) {
            $this->remote->table('inventory_ledger')->insert([
                'id' => (string) Str::uuid(),
                'branch_id' => $row['branch'],
                'variant_id' => $variant,
                'quantity' => $row['quantity'],
                'resulting_snapshot' => 0,
                'type' => $row['type'],
                'sale_type' => $row['type'] === 'sale' ? 'sale' : null,
                'reference_id' => null,
                'reference_type' => $row['type'] === 'sale' ? 'transaction' : 'manual_adjustment',
                'notes' => null,
                'performed_by' => $user,
                'created_at' => '2026-07-30 09:00:00',
            ]);
        }

        $this->pull();

        // `type = 'sale'` rows are this branch's own work and travel the other
        // way; another branch's stock is not this device's business at all.
        $rows = DB::table('inventory_ledger')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(12, (int) $rows->first()->quantity);
    }

    public function test_a_repeated_ledger_row_does_not_trip_the_immutability_trigger(): void
    {
        // inventory_ledger is append-only locally, guarded by a BEFORE UPDATE
        // trigger. Combined with `>=` re-reading the boundary row, an upsert here
        // would RAISE on the second cycle — so the pull inserts and ignores.
        [$variant, $user] = $this->catalogFixture();

        $this->remote->table('inventory_ledger')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => self::BRANCH,
            'variant_id' => $variant,
            'quantity' => 5,
            'resulting_snapshot' => 5,
            'type' => 'stock_adjustment',
            'sale_type' => null,
            'reference_id' => null,
            'reference_type' => 'manual_adjustment',
            'notes' => null,
            'performed_by' => $user,
            'created_at' => '2026-07-30 09:00:00',
        ]);

        $this->pull();
        $this->pull();

        $this->assertSame(1, DB::table('inventory_ledger')->count());
    }

    public function test_pulled_rows_are_not_pushed_straight_back(): void
    {
        // The reason the pull uses the query builder and never a model: an
        // Eloquent write fires PushedUpward::created, which would enqueue every
        // row the business just sent down and hand it back up on the next cycle.
        [$variant, $user] = $this->catalogFixture();

        $this->remote->table('inventory_ledger')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => self::BRANCH,
            'variant_id' => $variant,
            'quantity' => 5,
            'resulting_snapshot' => 5,
            'type' => 'stock_adjustment',
            'sale_type' => null,
            'reference_id' => null,
            'reference_type' => 'manual_adjustment',
            'notes' => null,
            'performed_by' => $user,
            'created_at' => '2026-07-30 09:00:00',
        ]);

        $before = SyncOutboxEntry::query()->count();

        $this->pull();

        $this->assertSame($before, SyncOutboxEntry::query()->count());
    }

    public function test_a_failure_on_one_table_leaves_the_others_progress(): void
    {
        // Why cursors are per table and not one global watermark. `variants` is
        // dropped from the stand-in, so its read throws after `users`,
        // `categories` and `items` have already been applied and bookmarked.
        $this->upstreamCategory('Drinks', '2026-07-30 09:00:00');

        $this->remote->statement('drop table "variants"');

        try {
            $this->pull();
            $this->fail('The pull should surface the failed table.');
        } catch (\Throwable) {
            // The cycle records it; here we only care what survived.
        }

        $this->assertSame('2026-07-30 09:00:00', SyncCursor::positionOf('categories'));
        $this->assertNull(SyncCursor::positionOf('variants'));
        $this->assertTrue(Category::query()->where('name', 'Drinks')->exists());
    }

    public function test_a_table_with_nothing_new_records_the_run_without_moving_the_bookmark(): void
    {
        $this->upstreamCategory('Drinks', '2026-07-30 09:00:00');
        $this->pull();

        $this->pull();

        // "Pulled and there was nothing" and "never pulled" are different
        // answers, and the console monitor will want to tell them apart.
        $this->assertSame('2026-07-30 09:00:00', SyncCursor::positionOf('categories'));
        $this->assertNotNull(SyncCursor::query()->find('items')?->last_pulled_at);
    }

    /**
     * A local item and variant to hang ledger rows off, plus a user to own them.
     *
     * @return array{0: string, 1: string} variant id, user id
     */
    private function catalogFixture(): array
    {
        $user = User::factory()->create();
        $item = Item::factory()->withVariant()->create();

        return [(string) $item->variants()->firstOrFail()->getKey(), (string) $user->getKey()];
    }
}
