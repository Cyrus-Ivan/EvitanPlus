<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * A STOCK BATCH: many movements asked for as one act (docs/glossary.md §4, M13).
 *
 * What is provable here is the SHAPE — how many rows one submission writes, what
 * they carry, and which submissions are refused. That a batch really lands in
 * Postgres, and that the branch role may insert `batch_id` at all, is
 * tests/Integration/InventoryQueueTest.php against two provisioned branches.
 */
class StockBatchTest extends TestCase
{
    use FakesThePendingQueue, RefreshDatabase;

    /** This device's branch, from the identity Tests\TestCase seeds. */
    private const HERE = '00000000-0000-0000-0000-0000000b4a4c';

    private const THERE = '00000000-0000-0000-0000-00000000e15e';

    private function seedRoster(Connection $remote): void
    {
        $remote->table('branches')->insert([
            ['id' => self::HERE, 'code' => 'TST', 'name' => 'Test Branch', 'address' => null,
                'disabled_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => self::THERE, 'code' => 'FAR', 'name' => 'Far Branch', 'address' => null,
                'disabled_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_an_adjustment_batch_writes_one_row_per_product_sharing_a_batch_id(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $first = Variant::factory()->create();
        $second = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'adjustment',
                'branch_id' => self::HERE,
                'mode' => 'add',
                'lines' => [
                    ['variant_id' => $first->id, 'quantity' => 4],
                    ['variant_id' => $second->id, 'quantity' => 7],
                ],
                'notes' => 'Weekly delivery',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rows = $remote->table('inventory_update_queue')->orderBy('quantity')->get();

        $this->assertCount(2, $rows);
        $this->assertSame([4, 7], $rows->pluck('quantity')->map(fn ($q): int => (int) $q)->all());

        // The point of the whole feature: one act, one name for it.
        $this->assertNotNull($rows[0]->batch_id);
        $this->assertSame($rows[0]->batch_id, $rows[1]->batch_id);

        // One note, on every row, so the branch answering reads it once.
        $this->assertSame(['Weekly delivery', 'Weekly delivery'], $rows->pluck('notes')->all());
        $this->assertSame(['manual_adjustment', 'manual_adjustment'], $rows->pluck('reference_type')->all());

        // Nothing moved. A batch asks, like every other issuing path.
        $this->assertDatabaseCount('inventory_ledger', 0);
    }

    public function test_a_transfer_batch_writes_only_the_sending_legs(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $first = Variant::factory()->create();
        $second = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'transfer',
                'from_branch_id' => self::HERE,
                'to_branch_id' => self::THERE,
                'lines' => [
                    ['variant_id' => $first->id, 'quantity' => 3],
                    ['variant_id' => $second->id, 'quantity' => 5],
                ],
            ])
            ->assertSessionHasNoErrors();

        $rows = $remote->table('inventory_update_queue')->get();

        // Two rows, not four: the receiving branch is not asked about stock that
        // has not left yet.
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([-3, -5], $rows->pluck('quantity')->map(fn ($q): int => (int) $q)->all());
        $this->assertSame([self::HERE, self::HERE], $rows->pluck('branch_id')->all());
        $this->assertSame([self::THERE, self::THERE], $rows->pluck('counterparty_branch_id')->all());
        $this->assertSame([null, null], $rows->pluck('parent_id')->all());
        $this->assertSame($rows[0]->batch_id, $rows[1]->batch_id);
    }

    /**
     * Confirming a transfer batch spawns the receiving legs one at a time, and
     * each keeps the batch — otherwise the sender sees one request for two
     * products and the receiver sees two, which is one delivery described two
     * different ways.
     */
    public function test_the_receiving_legs_of_a_transfer_batch_inherit_the_batch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $first = Variant::factory()->create();
        $second = Variant::factory()->create();

        app(StockLedger::class)->record($first, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);
        app(StockLedger::class)->record($second, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)->post(route('stock-batches.store'), [
            'kind' => 'transfer',
            'from_branch_id' => self::HERE,
            'to_branch_id' => self::THERE,
            'lines' => [
                ['variant_id' => $first->id, 'quantity' => 3],
                ['variant_id' => $second->id, 'quantity' => 5],
            ],
        ])->assertSessionHasNoErrors();

        $batchId = $remote->table('inventory_update_queue')->value('batch_id');

        $this->approveEverythingPending($admin);

        $receiving = $remote->table('inventory_update_queue')
            ->where('branch_id', self::THERE)
            ->get();

        $this->assertCount(2, $receiving);
        $this->assertSame([$batchId, $batchId], $receiving->pluck('batch_id')->all());
        $this->assertEqualsCanonicalizing([3, 5], $receiving->pluck('quantity')->map(fn ($q): int => (int) $q)->all());
    }

    /**
     * Approving a batch writes the batch onto every ledger row it produces, so
     * Stock History can say "these came off the shelf together" on a till with no
     * connection.
     */
    public function test_approving_a_batch_carries_it_onto_the_ledger(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $first = Variant::factory()->create();
        $second = Variant::factory()->create();

        $this->actingAs($admin)->post(route('stock-batches.store'), [
            'kind' => 'adjustment',
            'branch_id' => self::HERE,
            'mode' => 'add',
            'lines' => [
                ['variant_id' => $first->id, 'quantity' => 4],
                ['variant_id' => $second->id, 'quantity' => 7],
            ],
        ])->assertSessionHasNoErrors();

        $batchId = $remote->table('inventory_update_queue')->value('batch_id');

        $this->approveEverythingPending($admin);

        $this->assertDatabaseCount('inventory_ledger', 2);
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $first->id, 'quantity' => 4, 'batch_id' => $batchId]);
        $this->assertDatabaseHas('inventory_ledger', ['variant_id' => $second->id, 'quantity' => 7, 'batch_id' => $batchId]);
    }

    /**
     * The batch's other name: the one a person reads (schema 24).
     *
     * `TST` is this device's branch code, and the prefix names the branch that
     * ASKED — every row of the batch carries the same reference, and the serial
     * counts up from the ones already in the queue.
     */
    public function test_a_batch_is_named_for_the_branch_that_asked_for_it(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $first = Variant::factory()->create();
        $second = Variant::factory()->create();

        $submit = fn (Variant $variant, int $quantity) => $this->actingAs($admin)
            ->post(route('stock-batches.store'), [
                'kind' => 'adjustment',
                'branch_id' => self::HERE,
                'mode' => 'add',
                'lines' => [['variant_id' => $variant->id, 'quantity' => $quantity]],
            ])
            ->assertSessionHasNoErrors();

        $submit($first, 4);
        $submit($second, 7);

        $references = $remote->table('inventory_update_queue')->orderBy('quantity')->pluck('batch_reference');

        $this->assertSame(['TST-B-000001', 'TST-B-000002'], $references->all());
    }

    public function test_every_line_of_one_batch_carries_the_same_name(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'adjustment',
                'branch_id' => self::HERE,
                'mode' => 'add',
                'lines' => [
                    ['variant_id' => Variant::factory()->create()->id, 'quantity' => 4],
                    ['variant_id' => Variant::factory()->create()->id, 'quantity' => 7],
                ],
            ])
            ->assertSessionHasNoErrors();

        $rows = $remote->table('inventory_update_queue')->get();

        $this->assertSame(['TST-B-000001', 'TST-B-000001'], $rows->pluck('batch_reference')->all());
    }

    /**
     * A movement asked for on its own is not a batch and has no name — the same
     * rule `batch_id` has followed since schema 23.
     */
    public function test_a_single_adjustment_is_not_named(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-updates.store'), [
                'branch_id' => self::HERE,
                'variant_id' => Variant::factory()->create()->id,
                'mode' => 'add',
                'quantity' => 4,
            ])
            ->assertSessionHasNoErrors();

        $row = $remote->table('inventory_update_queue')->first();

        $this->assertNull($row->batch_id);
        $this->assertNull($row->batch_reference);
    }

    /**
     * Both names travel to the receiving branch, so the two ends of a transfer
     * say the same word about the same delivery.
     */
    public function test_the_receiving_legs_inherit_the_name_too(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $variant = Variant::factory()->create();

        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)->post(route('stock-batches.store'), [
            'kind' => 'transfer',
            'from_branch_id' => self::HERE,
            'to_branch_id' => self::THERE,
            'lines' => [['variant_id' => $variant->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $this->approveEverythingPending($admin);

        $this->assertSame(
            'TST-B-000001',
            $remote->table('inventory_update_queue')->where('branch_id', self::THERE)->value('batch_reference'),
        );
    }

    /**
     * The name has to reach the ledger, because that is the only copy a till
     * with no connection can read.
     */
    public function test_approving_a_batch_carries_its_name_onto_the_ledger(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $variant = Variant::factory()->create();

        $this->actingAs($admin)->post(route('stock-batches.store'), [
            'kind' => 'adjustment',
            'branch_id' => self::HERE,
            'mode' => 'add',
            'lines' => [['variant_id' => $variant->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->approveEverythingPending($admin);

        $this->assertDatabaseHas('inventory_ledger', [
            'variant_id' => $variant->id,
            'batch_reference' => 'TST-B-000001',
        ]);
    }

    /**
     * "Set to count" needs an on-hand figure this device can stand behind, and
     * the only one it has is its own.
     */
    public function test_set_is_refused_for_another_branch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'adjustment',
                'branch_id' => self::THERE,
                'mode' => 'set',
                'lines' => [['variant_id' => $variant->id, 'quantity' => 12]],
                'notes' => 'recount',
            ])
            ->assertSessionHasErrors('mode');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    public function test_set_becomes_a_delta_against_this_branchs_own_shelf(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $variant = Variant::factory()->create();

        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)->post(route('stock-batches.store'), [
            'kind' => 'adjustment',
            'branch_id' => self::HERE,
            'mode' => 'set',
            'lines' => [['variant_id' => $variant->id, 'quantity' => 12]],
            'notes' => 'physical recount',
        ])->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $remote->table('inventory_update_queue')->value('quantity'));
    }

    /**
     * A line that would move nothing is refused rather than dropped: silently
     * skipping it leaves somebody looking for a row that is not there.
     */
    public function test_a_line_that_would_change_nothing_is_refused(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $variant = Variant::factory()->create();

        app(StockLedger::class)->record($variant, 5, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)->post(route('stock-batches.store'), [
            'kind' => 'adjustment',
            'branch_id' => self::HERE,
            'mode' => 'set',
            'lines' => [['variant_id' => $variant->id, 'quantity' => 5]],
            'notes' => 'physical recount',
        ])->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    public function test_the_same_product_twice_is_refused(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'adjustment',
                'branch_id' => self::HERE,
                'mode' => 'add',
                'lines' => [
                    ['variant_id' => $variant->id, 'quantity' => 2],
                    ['variant_id' => $variant->id, 'quantity' => 3],
                ],
            ])
            ->assertSessionHasErrors('lines.1.variant_id');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    public function test_a_removal_needs_a_reason(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'adjustment',
                'branch_id' => self::HERE,
                'mode' => 'remove',
                'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('notes');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    public function test_a_transfer_to_the_same_branch_is_refused(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'transfer',
                'from_branch_id' => self::HERE,
                'to_branch_id' => self::HERE,
                'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            ])
            ->assertSessionHasErrors('to_branch_id');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    /**
     * Issuing is Admin+, like every other way of asking. ANSWERING one is
     * Cashier-level and comes through `stock-updates.resolve` unchanged.
     */
    public function test_a_cashier_cannot_issue_a_batch(): void
    {
        $this->fakeBusinessQueue();
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('stock-batches.store'), [
                'kind' => 'adjustment',
                'branch_id' => self::HERE,
                'mode' => 'add',
                'lines' => [['variant_id' => $variant->id, 'quantity' => 2]],
            ])
            ->assertForbidden();
    }

    public function test_the_picker_lists_products_with_this_branchs_stock(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $admin = User::factory()->admin()->create();
        $variant = Variant::factory()->create(['sku' => 'FIZZ-1']);

        app(StockLedger::class)->record($variant, 6, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)
            ->get(route('stock-batches.variants', ['search' => 'FIZZ', 'branch' => self::HERE]))
            ->assertOk()
            ->assertJsonCount(1, 'variants')
            ->assertJsonPath('variants.0.id', $variant->id)
            ->assertJsonPath('variants.0.sku', 'FIZZ-1')
            // This branch's own figure is the reconciled one, not a baseline —
            // the dialog tells the two apart from its own roster, so the feed
            // carries the number and not a flag about it.
            ->assertJsonPath('variants.0.stock', 6);
    }
}
