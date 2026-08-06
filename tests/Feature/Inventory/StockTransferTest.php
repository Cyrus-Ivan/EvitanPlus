<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * Moving stock between branches — one request, two answers.
 *
 * A transfer is deliberately not one movement. Stock leaving one shop and
 * arriving at another are two physical claims made by two different people, and
 * either can be false on its own: the van left with three, and two came off it
 * intact. So the sending branch confirms what went out, and confirming it is what
 * asks the receiving branch what came in.
 *
 * The arithmetic across two branches can only really be proved with two really
 * provisioned ones, which is tests/Integration/StockTransferTest.php. What is
 * provable here is the SHAPE: which row each answer creates, what it carries, and
 * what happens to a unit that never arrives.
 */
class StockTransferTest extends TestCase
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

    public function test_a_transfer_starts_as_one_pending_row_at_the_sending_branch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-transfers.store'), [
                'from_branch_id' => self::HERE,
                'to_branch_id' => self::THERE,
                'variant_id' => $variant->id,
                'quantity' => 3,
                'notes' => 'Weekly top-up',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rows = $remote->table('inventory_update_queue')->get();

        // ONE row, not two. The receiving branch is not asked about stock that
        // has not left yet.
        $this->assertCount(1, $rows);
        $this->assertSame(self::HERE, $rows[0]->branch_id);
        $this->assertSame(self::THERE, $rows[0]->counterparty_branch_id);
        // Negative, and the operator never typed a sign: they said how many are
        // moving, and which way is the system's business.
        $this->assertSame(-3, (int) $rows[0]->quantity);
        $this->assertSame('transfer', $rows[0]->reference_type);
        $this->assertNull($rows[0]->parent_id);
    }

    public function test_confirming_the_sending_leg_asks_the_receiving_branch(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => self::HERE,
            'to_branch_id' => self::THERE,
            'variant_id' => $variant->id,
            'quantity' => 3,
        ])->assertSessionHasNoErrors();

        $this->approveEverythingPending($admin);

        // The stock has left this branch.
        $this->assertSame(7, app(StockLedger::class)->currentStock($variant));

        $entry = InventoryLedger::query()->where('reference_type', 'transfer')->sole();
        $this->assertSame(-3, $entry->quantity);
        // By code, so this branch's Stock History can name where it went with no
        // connection — `branches` is never pulled to a device.
        $this->assertSame('FAR', $entry->counterparty_branch_code);

        // And the second leg now exists, aimed the other way.
        $incoming = $remote->table('inventory_update_queue')->whereNotNull('parent_id')->sole();

        $this->assertSame(self::THERE, $incoming->branch_id);
        $this->assertSame(self::HERE, $incoming->counterparty_branch_id);
        $this->assertSame(3, (int) $incoming->quantity);
        $this->assertSame('pending', $incoming->status);
        $this->assertSame('transfer', $incoming->reference_type);
    }

    public function test_the_receiving_branch_is_asked_about_what_was_actually_sent(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => self::HERE,
            'to_branch_id' => self::THERE,
            'variant_id' => $variant->id,
            'quantity' => 5,
        ])->assertSessionHasNoErrors();

        $sending = $remote->table('inventory_update_queue')->sole();

        // Only three actually went into the van.
        $this->actingAs($admin)->post(route('stock-updates.resolve'), ['adjustments' => [[
            'id' => $sending->id,
            'outcome' => 'partial',
            'quantity' => -3,
            'reason' => 'Only three were on the shelf.',
        ]]])->assertSessionHasNoErrors();

        $this->assertSame(7, app(StockLedger::class)->currentStock($variant));

        // The far branch is asked about THREE, not the five somebody first
        // typed. Asking about five would invite it to agree to stock that was
        // never sent.
        $incoming = $remote->table('inventory_update_queue')->whereNotNull('parent_id')->sole();
        $this->assertSame(3, (int) $incoming->quantity);
        $this->assertSame($sending->id, $incoming->parent_id);
    }

    public function test_a_shortfall_on_arrival_is_written_off_and_bounces_nothing_back(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        // Standing at the RECEIVING branch: a leg-2 row aimed here, three sent.
        $incoming = (string) Str::uuid();
        $remote->table('inventory_update_queue')->insert([
            'id' => $incoming,
            'parent_id' => (string) Str::uuid(),
            'branch_id' => self::HERE,
            'counterparty_branch_id' => self::THERE,
            'variant_id' => $variant->id,
            'quantity' => 3,
            'reference_type' => 'transfer',
            'status' => 'pending',
            'issued_by' => $admin->id,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->actingAs($admin)->post(route('stock-updates.resolve'), ['adjustments' => [[
            'id' => $incoming,
            'outcome' => 'partial',
            'quantity' => 2,
            'reason' => 'One was crushed in transit.',
        ]]])->assertSessionHasNoErrors();

        // Two arrived, so two are on the shelf. The sender is down three and this
        // branch is up two: the business is genuinely one unit short, because one
        // unit no longer exists. Nothing is sent back — inventing a compensating
        // row at the sender would put stock on a shelf that does not hold it.
        $this->assertSame(2, app(StockLedger::class)->currentStock($variant));

        $entry = InventoryLedger::query()->findOrFail($incoming);
        $this->assertSame(2, $entry->quantity);
        // The branch it came FROM. On the sending leg the same column holds the
        // branch it went TO — one column, and the sign says which.
        $this->assertSame('FAR', $entry->counterparty_branch_code);
        $this->assertStringContainsString('crushed in transit', (string) $entry->notes);
        $this->assertStringContainsString('+2 of +3', (string) $entry->notes);

        // No third leg: a receiving leg already has a parent, so approving it
        // spawns nothing.
        $this->assertSame(1, $remote->table('inventory_update_queue')->count());
    }

    /**
     * A receiving leg for stock that left short, with its sending leg behind it.
     *
     * Alice at FAR asked for 3; Bob at FAR confirmed only 2 went out of the door
     * and said why; 2 are now arriving at this branch. Returns the receiving
     * leg's id.
     */
    private function shortSendArriving(Connection $remote, Variant $variant, User $requester, User $sender): string
    {
        $parent = (string) Str::uuid();
        $incoming = (string) Str::uuid();

        // One statement each: the two rows do not carry the same columns, and
        // SQLite refuses a multi-row insert whose tuples differ in width.
        $remote->table('inventory_update_queue')->insert([
            'id' => $parent,
            'branch_id' => self::THERE,
            'counterparty_branch_id' => self::HERE,
            'variant_id' => $variant->id,
            'quantity' => -3,
            'reference_type' => 'transfer',
            'status' => 'accepted',
            'approved_quantity' => -2,
            'resolution_reason' => 'One was damaged loading.',
            'resolved_by' => $sender->id,
            'resolved_at' => now()->toDateTimeString(),
            'notes' => 'Restock for the weekend rush.',
            'issued_by' => $requester->id,
            'issued_by_branch_id' => self::THERE,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $remote->table('inventory_update_queue')->insert([
            'id' => $incoming,
            'parent_id' => $parent,
            'branch_id' => self::HERE,
            'counterparty_branch_id' => self::THERE,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'reference_type' => 'transfer',
            'status' => 'pending',
            'notes' => 'Restock for the weekend rush.',
            // As AdjustmentResolver::secondLeg() writes it: the issuer is the
            // person who put the stock in the van, and no issuing branch.
            'issued_by' => $sender->id,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $incoming;
    }

    public function test_the_senders_account_reaches_the_receiving_branchs_ledger_row(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();
        $receiver = User::factory()->admin()->create();

        $incoming = $this->shortSendArriving(
            $remote,
            $variant,
            User::factory()->admin()->create(),
            User::factory()->admin()->create(),
        );

        $this->actingAs($receiver)
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $incoming, 'outcome' => 'approve']]])
            ->assertSessionHasNoErrors();

        // Why only two of the three arrived is a claim made by somebody at the
        // OTHER branch, on a row this device never pulls — `Puller` filters the
        // ledger to one branch. Without this line the receiving branch's
        // permanent record cannot say it at all once the request leaves the
        // Pending Stocks page, and this string is the whole account offline.
        $this->assertSame(
            ['Requested: Restock for the weekend rush.', 'Sent: 2 of the 3 asked for. One was damaged loading.'],
            explode("\n", (string) InventoryLedger::query()->findOrFail($incoming)->notes),
        );
    }

    public function test_the_request_behind_an_arrival_names_all_three_people(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        $requester = User::factory()->admin()->create(['name' => 'Alice']);
        $sender = User::factory()->admin()->create(['name' => 'Bob']);
        $receiver = User::factory()->cashier()->create(['name' => 'Carla']);

        $incoming = $this->shortSendArriving($remote, $variant, $requester, $sender);

        $this->actingAs($receiver)
            ->post(route('stock-updates.resolve'), ['adjustments' => [['id' => $incoming, 'outcome' => 'approve']]])
            ->assertSessionHasNoErrors();

        $request = $this->actingAs($receiver)
            ->getJson(route('stock-history.request', $incoming))
            ->assertOk()
            ->json('request');

        // Carla answered THIS leg, and the flat fields are hers.
        $this->assertSame('Carla', $request['resolved_by']['name']);
        $this->assertSame(2, $request['requested']);
        $this->assertSame('TST', $request['branch']['code']);
        // The requester's own words, apart from any answerer's.
        $this->assertSame('Restock for the weekend rush.', $request['notes']);

        // Alice asked, and she can only be read off the PARENT: this leg's own
        // issuer is Bob, who confirmed the stock left, and it carries no issuing
        // branch at all. Taken from the row itself the dialog would name the
        // wrong person, from nowhere.
        $this->assertSame('Bob', $request['issued_by']['name']);
        $this->assertNull($request['issued_by']['branch']);

        $this->assertSame('Alice', $request['parent']['issued_by']['name']);
        $this->assertSame('FAR', $request['parent']['issued_by']['branch']['code']);
        $this->assertSame(-3, $request['parent']['requested']);

        // And Bob, who is the only one who knows why a third of it is missing.
        $this->assertSame('Bob', $request['parent']['resolved_by']['name']);
        $this->assertSame(-2, $request['parent']['approved']);
        $this->assertSame('One was damaged loading.', $request['parent']['resolution_reason']);
        $this->assertSame('FAR', $request['parent']['branch']['code']);
    }

    public function test_turning_down_the_sending_leg_asks_nobody_anything(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        app(StockLedger::class)->record($variant, 10, 'stock_adjustment', null, 'manual_adjustment', null, $admin);

        $this->actingAs($admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => self::HERE,
            'to_branch_id' => self::THERE,
            'variant_id' => $variant->id,
            'quantity' => 4,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('stock-updates.resolve'), ['adjustments' => [[
            'id' => $remote->table('inventory_update_queue')->value('id'),
            'outcome' => 'reject',
            'reason' => 'The van never came.',
        ]]])->assertSessionHasNoErrors();

        // Nothing left, so nothing arrives: no second leg, and the far branch is
        // never asked about stock that did not move.
        $this->assertSame(10, app(StockLedger::class)->currentStock($variant));
        $this->assertSame(1, $remote->table('inventory_update_queue')->count());
        $this->assertSame(0, $remote->table('inventory_update_queue')->whereNotNull('parent_id')->count());
    }

    public function test_a_transfer_to_the_same_branch_is_refused(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        // It would be a recount with extra steps, and worse: approving it would
        // spawn a second row aimed straight back at the branch that just
        // answered the first.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('stock-transfers.store'), [
                'from_branch_id' => self::HERE,
                'to_branch_id' => self::HERE,
                'variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertSessionHasErrors('to_branch_id');

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    /**
     * The rule was here from the start and nothing could reach it.
     *
     * `IssueTransferRequest` has always said `min:1`, but the dialog shared its
     * number field with the stock adjustment — where a sign is meaningful — and
     * absoluted whatever was typed on the way out. Somebody entering -3 to mean
     * "send three away" got a three-unit transfer and no sign that their minus had
     * been dropped. The field is its own dialog now and refuses a negative itself,
     * so this is the rule it defers to.
     *
     * It is load-bearing rather than cosmetic. `InventoryQueueController::transfer()`
     * negates the quantity to write the sending leg, and `AdjustmentResolver::secondLeg()`
     * takes `abs()` of it to write the receiving one — so a positive sending leg
     * would make BOTH legs positive and the transfer would create stock rather
     * than move it.
     */
    public function test_a_transfer_of_nothing_or_less_is_refused(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();
        $admin = User::factory()->admin()->create();

        foreach ([-3, 0] as $quantity) {
            $this->actingAs($admin)
                ->post(route('stock-transfers.store'), [
                    'from_branch_id' => self::HERE,
                    'to_branch_id' => self::THERE,
                    'variant_id' => $variant->id,
                    'quantity' => $quantity,
                ])
                ->assertSessionHasErrors('quantity');
        }

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }

    public function test_a_cashier_cannot_start_a_transfer(): void
    {
        $remote = $this->fakeBusinessQueue();
        $this->seedRoster($remote);
        $variant = Variant::factory()->create();

        // Answering one is theirs; deciding that stock should move between
        // branches is not.
        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('stock-transfers.store'), [
                'from_branch_id' => self::HERE,
                'to_branch_id' => self::THERE,
                'variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertForbidden();

        $this->assertSame(0, $remote->table('inventory_update_queue')->count());
    }
}
