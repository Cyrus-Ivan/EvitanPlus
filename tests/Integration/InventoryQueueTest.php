<?php

namespace Tests\Integration;

use App\Models\Category;
use App\Models\Instance;
use App\Models\InventoryLedger;
use App\Models\SyncStatus;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\SyncCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ApprovesEveryPendingRow;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;
use Throwable;

/**
 * Pending stock adjustments against two real provisioned branches: **asked for
 * at one branch, answered by a person at the other.**
 *
 * ## Why this cannot be proved anywhere else
 *
 * tests/Feature/Inventory/AdjustmentResolutionTest.php covers the decision
 * against a SQLite stand-in, in milliseconds. Three things it structurally
 * cannot reach, and each of them fails silently:
 *
 *  - **The INSERT grant.** Without `inventory_update_queue` in
 *    `BranchGrants::INSERT`, every request is `permission denied` — and the
 *    branch role is the only credential the shipped app ever holds, because the
 *    console never ships.
 *  - **The SIX-column UPDATE grant.** The grant is column-level, so a resolution
 *    that touched a seventh column is refused. Schema 21 widened it from four to
 *    six for `approved_quantity` and `resolved_by`; a stand-in with no grants at
 *    all would pass either way, including with the old four.
 *  - **The check constraints.** `(status = 'pending') = (resolved_at is null)`
 *    and `resulting_snapshot >= 0` are real Postgres constraints; the stand-in
 *    carries none, on purpose.
 */
#[Group('supabase')]
class InventoryQueueTest extends TestCase
{
    use ApprovesEveryPendingRow, ProvisionsABusiness, RefreshDatabase;

    /** Each test sets its devices up for itself, through the real wizard. */
    protected bool $hydrated = false;

    /** The real probe, the real cycle, the real grants. */
    protected ?bool $connected = null;

    private const OWNER_USERNAME = 'queue-owner';

    private const BUSINESS_NAME = 'Queue Store';

    /** The branch that ISSUES. */
    private const BRANCH_A = 'QAA';

    /** The branch that is TARGETED, and therefore the one that decides. */
    private const BRANCH_B = 'QBB';

    /** @var array<string, mixed>|null */
    private static ?array $documentA = null;

    /** @var array<string, mixed>|null */
    private static ?array $documentB = null;

    private static ?string $schema = null;

    private static ?string $roleA = null;

    private static ?string $roleB = null;

    private static ?PDO $admin = null;

    public static function tearDownAfterClass(): void
    {
        self::destroyBusiness(self::$admin, self::$schema, self::$roleA);

        if (self::$admin !== null && self::$roleB !== null) {
            try {
                self::$admin->exec('drop role if exists "'.self::$roleB.'"');
            } catch (Throwable) {
                //
            }
        }

        self::$admin = null;
        self::$documentA = null;
        self::$documentB = null;
        self::$schema = null;
        self::$roleA = null;
        self::$roleB = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $env = $this->consoleEnv();

        if (($env['CONSOLE_DB_PASSWORD'] ?? '') === '') {
            $this->markTestSkipped('No console admin credentials in console/.env — this test provisions a real business.');
        }

        $this->directory = storage_path('framework/testing/queue');
        File::ensureDirectoryExists($this->directory);

        if (self::$documentA === null) {
            self::$admin = $this->adminConnection($env);
            self::$documentA = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_A, 'queue-test');
            self::$schema = self::$documentA['business']['schema'];
            self::$roleA = self::$documentA['role']['name'];

            self::$documentB = $this->provisionSecondBranch();
            self::$roleB = self::$documentB['role']['name'];
        }
    }

    protected function tearDown(): void
    {
        $this->resetSharedData();

        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_branch_can_turn_down_an_adjustment_and_the_reason_reaches_the_issuer(): void
    {
        // Branch B has ten on its shelf, and that figure reaches Postgres — a
        // manual adjustment is an online write.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE1', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE1', 10);

        // Someone at branch A writes off twenty-five. B is not running and is
        // not consulted: the row is only a request.
        $this->hydrateAs(self::$documentA);
        $this->issue(self::$documentB, 'QUEUE1', -25, 'Written off at QBB');

        $queued = $this->queued('Written off at QBB');
        $this->assertSame('pending', $queued['status']);
        $this->assertNull($queued['resolved_at']);

        // B comes back. A full cycle now changes NOTHING about the queue — the
        // step that used to answer these is gone, which is why schema 21 raised
        // the app floor: a build still running it would auto-approve this.
        $this->hydrateAs(self::$documentB);
        $result = app(SyncCycle::class)->run();

        $this->assertSame(SyncStatus::ONLINE, $result['state'], (string) $result['error']);
        $this->assertSame('pending', $this->queued('Written off at QBB')['status']);

        // A person at B answers it. This is the statement of record: the stock
        // was never written off here.
        $this->resolve($queued['id'], 'reject', reason: 'Nothing was ever taken off the shelf here.');

        $answered = $this->queued('Written off at QBB');

        $this->assertSame('rejected', $answered['status']);
        $this->assertNotNull($answered['resolved_at']);
        $this->assertSame('Nothing was ever taken off the shelf here.', $answered['resolution_reason']);
        $this->assertSame($this->owner()->getKey(), $answered['resolved_by']);
        // Null: `approved_quantity` means "what was agreed", and nothing was.
        $this->assertNull($answered['approved_quantity']);
        // Set (M11.8), because a refusal now writes a ledger row of its own.
        $this->assertSame($answered['id'], $answered['ledger_entry_id']);

        // Stock is untouched on both sides, and never went negative anywhere.
        $this->assertSame(10, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ?',
            [self::$documentB['branch']['id']],
        )[0]['quantity']);

        $this->assertSame(10, app(StockLedger::class)->currentStock(Variant::query()->where('sku', 'QUEUE1')->sole()));

        // A ledger entry of ZERO was written for the refusal (M11.8), locally
        // and upstream. It moves nothing — the two snapshot assertions above are
        // what says so — and it exists purely so the branch that ASKED can read
        // the outcome: the pull filters this table to one branch, so nothing
        // else could ever carry it to them.
        $refusal = $this->remote(
            'select quantity, branch_id, notes from inventory_ledger where id = ?',
            [$answered['id']],
        );

        $this->assertCount(1, $refusal);
        $this->assertSame(0, (int) $refusal[0]['quantity']);
        $this->assertSame(self::$documentB['branch']['id'], $refusal[0]['branch_id']);
        $this->assertStringContainsString('Turned down', (string) $refusal[0]['notes']);
        $this->assertStringContainsString('Nothing was ever taken off the shelf here.', (string) $refusal[0]['notes']);

        $this->assertSame(0, InventoryLedger::query()->findOrFail($answered['id'])->quantity);
    }

    public function test_a_refusal_at_one_branch_is_readable_from_the_branch_that_asked(): void
    {
        // The whole reason M11.8 moved the record: A asks B for something, B says
        // no, and before this A had nowhere at all to find that out. `Puller`
        // filters `inventory_ledger` to one branch, so the refusal B wrote will
        // never reach A's own SQLite — A has to read B's rows upstream, which is
        // what branch scope on Stock History is for.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE9', '12.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE9', 4);

        $this->hydrateAs(self::$documentA);
        $this->issue(self::$documentB, 'QUEUE9', -4, 'Spoiled at QBB');

        $this->hydrateAs(self::$documentB);
        $queued = $this->queued('Spoiled at QBB');
        $this->resolve($queued['id'], 'reject', reason: 'They are all still on the shelf.');

        $this->hydrateAs(self::$documentA);

        // A's own history does not have it, and must not: the row is B's.
        $this->assertNull(InventoryLedger::query()->find($queued['id']));

        // Scoped to B, read live, it is there — as a movement of nothing.
        $props = $this->actingAs($this->owner())
            ->get(route('stock-history.index', ['scope' => self::$documentB['branch']['id']]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue($props['consolidatedOnline']);

        $refusal = collect($props['entries']['data'])->firstWhere('id', $queued['id']);

        $this->assertNotNull($refusal);
        $this->assertSame(0, $refusal['quantity']);
        $this->assertSame(self::BRANCH_B, $refusal['branch']);
        $this->assertStringContainsString('They are all still on the shelf.', (string) $refusal['notes']);

        // And the structured account behind it, which is what the row's dialog
        // fetches: the ledger id IS the queue row's id, so this is a lookup.
        $request = $this->actingAs($this->owner())
            ->getJson(route('stock-history.request', $queued['id']))
            ->assertOk()
            ->json('request');

        $this->assertSame('rejected', $request['status']);
        $this->assertSame(-4, $request['requested']);
        $this->assertNull($request['approved']);
        $this->assertSame('They are all still on the shelf.', $request['resolution_reason']);
        $this->assertSame(self::BRANCH_B, $request['branch']['code']);
        // Schema 22: where the person who asked was standing.
        $this->assertSame(self::BRANCH_A, $request['issued_by']['branch']['code']);
        // The requester's own words, returned separately from the answerer's so
        // the dialog can attribute each to the person who wrote it.
        $this->assertSame('Spoiled at QBB', $request['notes']);
        // A plain adjustment has no sending leg to report.
        $this->assertNull($request['parent']);
    }

    public function test_an_approved_adjustment_becomes_a_ledger_entry_on_both_sides(): void
    {
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE2', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE2', 10);

        $this->hydrateAs(self::$documentA);
        $this->issue(self::$documentB, 'QUEUE2', -4, 'Damaged at QBB');

        $this->hydrateAs(self::$documentB);
        $queued = $this->queued('Damaged at QBB');

        $this->resolve($queued['id'], 'approve');

        $answered = $this->queued('Damaged at QBB');

        $this->assertSame('accepted', $answered['status']);
        $this->assertSame(-4, (int) $answered['approved_quantity']);
        $this->assertSame($this->owner()->getKey(), $answered['resolved_by']);
        // The entry's id IS the queue row's id, which is what makes a retry
        // after a lost connection collide with itself instead of double-applying.
        $this->assertSame($answered['id'], $answered['ledger_entry_id']);

        $entry = $this->remote(
            'select * from inventory_ledger where id = ?',
            [$answered['ledger_entry_id']],
        )[0];

        $this->assertSame(-4, (int) $entry['quantity']);
        $this->assertSame('stock_adjustment', $entry['type']);
        $this->assertSame('manual_adjustment', $entry['reference_type']);
        $this->assertSame(self::$documentB['branch']['id'], $entry['branch_id']);
        // The branch that ASKED, carried onto the entry by code so branch B's
        // Stock History can name it with no connection.
        $this->assertSame(self::BRANCH_A, $entry['counterparty_branch_code']);

        // Upstream stock is recomputed from Postgres's own ledger, so the next
        // baseline contains the adjustment rather than reverting it.
        $this->assertSame(6, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ?',
            [self::$documentB['branch']['id']],
        )[0]['quantity']);

        // Local too, and immediately: approving is an ordinary online write, so
        // the stock is on the POS grid the instant the response lands rather
        // than up to five minutes later when a cycle next pulls.
        $variant = Variant::query()->where('sku', 'QUEUE2')->sole();

        $this->assertSame(6, app(StockLedger::class)->currentStock($variant));
        $this->assertDatabaseHas('inventory_ledger', [
            'id' => $answered['ledger_entry_id'],
            'quantity' => -4,
            'type' => 'stock_adjustment',
        ]);
    }

    public function test_an_approval_is_refused_when_the_branchs_own_sales_have_spent_the_stock(): void
    {
        // The case the queue exists for, and the one an issuer cannot see. The
        // upstream figure says ten; B has sold eight of them and has not pushed.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE3', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE3', 10);

        $variant = Variant::query()->where('sku', 'QUEUE3')->sole();

        for ($i = 0; $i < 8; $i++) {
            app(StockLedger::class)->record(
                variant: $variant, quantity: -1, type: 'sale', saleType: 'sale',
                referenceType: 'transaction', referenceId: null, performedBy: $this->owner(),
            );
        }

        // Issued from A against the ten it can see. Note B is NOT re-hydrated
        // afterwards — its own unsynced sales are half of what makes this fail.
        $this->issueAsBranchA(self::$documentB, 'QUEUE3', -5, 'Shrinkage at QBB');

        $queued = $this->queued('Shrinkage at QBB');

        // Somebody at B tries to agree to it, and cannot: their own till says 2.
        // The local snapshot ALREADY contains the unsynced sales, which is why
        // schema 21 could delete the old two-term formula rather than port it —
        // the branch reading its own ledger is the stricter of the two figures.
        $this->actingAs($this->owner())
            ->post(route('stock-updates.resolve'), ['adjustments' => [[
                'id' => $queued['id'], 'outcome' => 'approve',
            ]]])
            ->assertSessionHasErrors('adjustments');

        $this->assertSame('pending', $this->queued('Shrinkage at QBB')['status']);
        $this->assertSame(2, app(StockLedger::class)->currentStock($variant));

        // Partially approving what DOES fit is the way out, and the reason the
        // refusal names the product rather than the batch.
        $this->resolve($queued['id'], 'partial', quantity: -2, reason: 'Only two were left to write off.');

        $answered = $this->queued('Shrinkage at QBB');

        $this->assertSame('accepted', $answered['status']);
        $this->assertSame(-2, (int) $answered['approved_quantity']);
        $this->assertSame(0, app(StockLedger::class)->currentStock($variant));

        // And Postgres never saw a negative, on either the ledger's own
        // `resulting_snapshot >= 0` or `stock_snapshots_non_negative`.
        app(SyncCycle::class)->run();

        $this->assertSame(0, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ?',
            [self::$documentB['branch']['id']],
        )[0]['quantity']);
    }

    public function test_a_resolved_row_leaves_the_pending_page_at_the_branch_that_issued_it(): void
    {
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE4', '20.00');

        $this->hydrateAs(self::$documentB);
        $this->stockUp('QUEUE4', 3);

        $this->hydrateAs(self::$documentA);
        $this->issue(self::$documentB, 'QUEUE4', -9, 'Too many');

        $this->hydrateAs(self::$documentB);
        $this->resolve($this->queued('Too many')['id'], 'reject', reason: 'We never had nine of these.');

        // Back at the branch that issued it: the in-app admin, reading the queue
        // live out of the business database.
        $this->hydrateAs(self::$documentA);

        $response = $this->actingAs($this->owner())
            ->get(route('stock-updates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('inventory/stock-updates', false)->where('online', true));

        // The props themselves, rather than a chain of `where()`s: what is being
        // asserted is one row out of a list whose length this test does not fix.
        $props = $response->viewData('page')['props'];

        // The turned-down row is NOT on this page any more (M11.8): it is
        // finished, and a finished request leaves. Where it lives now is the
        // refusal's own ledger row, proved by
        // test_a_refusal_at_one_branch_is_readable_from_the_branch_that_asked.
        $this->assertNull(
            collect($props['requests'])->first(fn (array $request): bool => $request['notes'] === 'Too many'),
        );

        // And it left because it was ANSWERED, not because it vanished: the
        // rejection stands upstream as the only record of what happened, and
        // asking for the stock again means issuing a new request.
        $rows = $this->remote(
            "select * from inventory_update_queue where notes = 'Too many'",
        );

        $this->assertCount(1, $rows);
        $this->assertSame('rejected', $rows[0]['status']);
    }

    public function test_a_transfer_is_answered_at_both_ends_and_the_shortfall_is_written_off(): void
    {
        // Added here rather than in a file of its own: a transfer needs two
        // really provisioned branches, and this class already has them. A second
        // copy of that scaffold would be a second thing to keep in step.
        $this->hydrateAs(self::$documentA);
        $this->createProduct('QUEUE5', '20.00');
        $this->stockUp('QUEUE5', 8);

        // A is sending three of its eight to B.
        $this->actingAs($this->owner())
            ->post(route('stock-transfers.store'), [
                'from_branch_id' => self::$documentA['branch']['id'],
                'to_branch_id' => self::$documentB['branch']['id'],
                'variant_id' => Variant::query()->where('sku', 'QUEUE5')->sole()->getKey(),
                'quantity' => 3,
                'notes' => 'Weekly top-up',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sending = $this->queued('Weekly top-up');

        $this->assertSame(self::$documentA['branch']['id'], $sending['branch_id']);
        $this->assertSame(self::$documentB['branch']['id'], $sending['counterparty_branch_id']);
        $this->assertSame(-3, (int) $sending['quantity']);
        $this->assertNull($sending['parent_id']);

        // Somebody at A confirms all three went into the van.
        $this->resolve($sending['id'], 'approve');

        $this->assertSame(5, app(StockLedger::class)->currentStock(Variant::query()->where('sku', 'QUEUE5')->sole()));

        $outgoing = $this->remote('select * from inventory_ledger where id = ?', [$sending['id']])[0];
        $this->assertSame(-3, (int) $outgoing['quantity']);
        $this->assertSame('transfer', $outgoing['reference_type']);
        // Where it went, by code, so A's Stock History names it offline.
        $this->assertSame(self::BRANCH_B, $outgoing['counterparty_branch_code']);

        // Confirming the send is what asks B, and only then.
        $incoming = $this->remote('select * from inventory_update_queue where parent_id = ?', [$sending['id']])[0];

        $this->assertSame(self::$documentB['branch']['id'], $incoming['branch_id']);
        $this->assertSame(3, (int) $incoming['quantity']);
        $this->assertSame('pending', $incoming['status']);

        // At B: only two came off the van intact.
        $this->hydrateAs(self::$documentB);
        $this->resolve($incoming['id'], 'partial', quantity: 2, reason: 'One was crushed in transit.');

        $this->assertSame(2, app(StockLedger::class)->currentStock(Variant::query()->where('sku', 'QUEUE5')->sole()));

        $arrived = $this->remote('select * from inventory_ledger where id = ?', [$incoming['id']])[0];
        $this->assertSame(2, (int) $arrived['quantity']);
        $this->assertSame(self::BRANCH_A, $arrived['counterparty_branch_code']);
        $this->assertStringContainsString('crushed in transit', (string) $arrived['notes']);

        // The arithmetic the whole design rests on: A is down three, B is up
        // two, and the business is one unit short — because one unit no longer
        // exists. Nothing bounced back to A, and no third row was created.
        $this->assertSame(5, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ? and variant_id = ?',
            [self::$documentA['branch']['id'], $sending['variant_id']],
        )[0]['quantity']);

        $this->assertSame(2, (int) $this->remote(
            'select quantity from stock_snapshots where branch_id = ? and variant_id = ?',
            [self::$documentB['branch']['id'], $sending['variant_id']],
        )[0]['quantity']);

        $this->assertSame(0, (int) $this->remote(
            'select count(*) as c from inventory_update_queue where parent_id = ?',
            [$incoming['id']],
        )[0]['c']);
    }

    // ---------------------------------------------------------------- helpers

    private function owner(): User
    {
        return User::query()->where('username', self::OWNER_USERNAME)->sole();
    }

    /**
     * Issue an adjustment from whichever device is currently hydrated.
     *
     * $quantity is SIGNED here, because that is what the queue row holds and what
     * every assertion below reads back. The endpoint takes a positive number and a
     * direction, so this is where the two are separated — the mirror image of what
     * `InventoryQueueController::store()` does on the way in.
     */
    private function issue(array $target, string $sku, int $quantity, string $notes): void
    {
        $this->actingAs($this->owner())
            ->post(route('stock-updates.store'), [
                'branch_id' => $target['branch']['id'],
                'variant_id' => Variant::query()->where('sku', $sku)->sole()->getKey(),
                'mode' => $quantity < 0 ? 'remove' : 'add',
                'quantity' => abs($quantity),
                'notes' => $notes,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * Issue from branch A WITHOUT re-hydrating the current device.
     *
     * The device stays branch B — outbox, local sales and all — while the row is
     * written with A's credential. Re-hydrating would empty exactly the unsynced
     * set the test is about.
     */
    private function issueAsBranchA(array $target, string $sku, int $quantity, string $notes): void
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        $variantId = Variant::query()->where('sku', $sku)->sole()->getKey();
        $ownerId = $this->owner()->getKey();

        self::$admin->exec(
            "insert into inventory_update_queue
               (id, branch_id, variant_id, quantity, reference_type, notes, status, issued_by, created_at, updated_at)
             values ('{$this->uuid()}', '{$target['branch']['id']}', '{$variantId}', {$quantity},
                     'manual_adjustment', '{$notes}', 'pending', '{$ownerId}', now(), now())"
        );
    }

    /**
     * The queue row a test is actually about, by its notes.
     *
     * Opening stock is itself a pending adjustment now, so every test has at
     * least two rows in this table and "the first one" is whichever Postgres
     * felt like returning.
     *
     * @return array<string, mixed>
     */
    private function queued(string $notes): array
    {
        return $this->remote('select * from inventory_update_queue where notes = ?', [$notes])[0];
    }

    /**
     * Answer a pending row as a person at the currently hydrated branch.
     *
     * Through the HTTP route rather than the resolver, so the branch filter, the
     * FormRequest and the six-column grant are all really exercised.
     */
    private function resolve(string $id, string $outcome, ?int $quantity = null, ?string $reason = null): void
    {
        $this->actingAs($this->owner())
            ->post(route('stock-updates.resolve'), ['adjustments' => [array_filter([
                'id' => $id,
                'outcome' => $outcome,
                'quantity' => $quantity,
                'reason' => $reason,
            ], fn ($value) => $value !== null)]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * Opening stock at the currently hydrated branch.
     *
     * Two steps since schema 21: the adjustment is asked for, then confirmed by
     * somebody standing there. Both are online writes.
     */
    private function stockUp(string $sku, int $quantity): void
    {
        $this->actingAs($this->owner())
            ->post(route('stock.adjust', Variant::query()->where('sku', $sku)->sole()->getKey()), [
                'mode' => 'add',
                'value' => $quantity,
                'notes' => 'Opening',
            ])
            ->assertSessionHasNoErrors();

        $this->approveEverythingPending($this->owner());
    }

    private function createProduct(string $sku, string $price): void
    {
        $owner = $this->owner();

        if (Category::query()->where('name', 'Queued')->doesntExist()) {
            $this->actingAs($owner)->post(route('categories.store'), ['name' => 'Queued'])->assertSessionHasNoErrors();
        }

        $this->actingAs($owner)->post(route('items.store'), [
            'name' => 'Product '.$sku,
            'unit' => 'pc',
            'category_id' => Category::query()->where('name', 'Queued')->value('id'),
            'variants' => [[
                'name' => null, 'sku' => $sku, 'barcode' => null,
                'selling_price' => $price, 'cost' => '1.00', 'low_stock_threshold' => 0,
            ]],
        ])->assertSessionHasNoErrors();
    }

    /**
     * Run the real wizard for $document on a device that knows nothing.
     *
     * Emptied row by row because `migrate:fresh` VACUUMs, and VACUUM is illegal
     * inside the transaction RefreshDatabase puts every test in.
     */
    private function hydrateAs(array $document): void
    {
        foreach (['audit_logs', 'login_history', 'transactions', 'transaction_items', 'inventory_ledger', 'sale_returns', 'sale_return_items'] as $table) {
            DB::unprepared("drop trigger if exists {$table}_immutable_delete");
        }

        DB::unprepared('drop trigger if exists instance_singleton');
        DB::unprepared('drop trigger if exists instance_secret_singleton');

        Schema::disableForeignKeyConstraints();

        foreach ([
            'sync_outbox', 'sync_status', 'sync_cursor', 'audit_logs', 'login_history',
            'sale_return_items', 'sale_returns', 'transaction_items', 'transactions',
            'inventory_ledger', 'stock_snapshots', 'import_history',
            'variants', 'items', 'categories', 'settings', 'users',
            'instance_secret', 'instance',
        ] as $table) {
            DB::table($table)->delete();
        }

        Schema::enableForeignKeyConstraints();

        $this->post(route('setup.store'), [
            'credentials_path' => $this->writeCredentialsFile($document, 'device-'.$document['branch']['code'].'.json'),
            'delete_after_import' => '0',
            'configure_printer' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Instance::current());
    }

    /** @return array<string, mixed> */
    private function provisionSecondBranch(): array
    {
        $out = $this->directory.'/branch-b.json';

        $command = $this->consoleCommand([
            'branch:provision',
            '--schema='.self::$schema,
            '--branch-name=Second Queue Branch',
            '--branch-code='.self::BRANCH_B,
            '--branch-address=2 Test Street',
            '--out='.$out,
            '--actor=queue-test',
            '--force',
            '--no-interaction',
        ]);

        exec($command.' 2>&1', $output, $status);

        $this->assertSame(0, $status, "branch:provision failed:\n".implode("\n", $output));

        return json_decode((string) file_get_contents($out), true);
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function remote(string $sql, array $bindings = []): array
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        $statement = self::$admin->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Back to the provisioned baseline. The schema is shared by every test in
     * this class and Postgres has no per-test rollback.
     */
    private function resetSharedData(): void
    {
        if (self::$admin === null) {
            return;
        }

        self::$admin->exec('set search_path to "'.self::$schema.'"');

        self::$admin->exec(
            'truncate inventory_update_queue, stock_snapshots, inventory_ledger, import_history,
                      variants, items, categories cascade'
        );

        self::$admin->exec("delete from users where username <> '".self::OWNER_USERNAME."'");
    }
}
