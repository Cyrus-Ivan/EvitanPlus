<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\ImportHistory;
use App\Models\Instance;
use App\Models\InventoryLedger;
use App\Models\Item;
use App\Models\StockSnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\OpeningStockCsvSchema;
use App\Support\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * OPENING STOCK: the one bulk path onto a shelf, and the one movement that
 * reaches the ledger without somebody confirming it first.
 *
 * Two claims are under test and they pull against each other. That the import
 * WORKS — a spreadsheet of SKUs and quantities becomes real stock, locally and
 * upstream, in one act. And that it is SHUT — the moment the branch sells
 * anything, this route is gone and every later movement goes back through the
 * queue. The second is what makes the first defensible, so a test that proves
 * the write without the window would be proving the wrong thing.
 */
class OpeningStockImportTest extends TestCase
{
    use FakesThePendingQueue, RefreshDatabase;

    private function stockOf(Variant $variant): int
    {
        return app(StockLedger::class)->currentStock($variant);
    }

    /**
     * A variant at zero, which is what a device off the setup wizard has.
     */
    private function variant(string $sku, string $product = 'Bottled Water'): Variant
    {
        return Variant::factory()->for(Item::factory()->create(['name' => $product]))->create([
            'sku' => $sku,
            'name' => '500ml',
        ]);
    }

    /**
     * Build an uploaded CSV. Pass $columns to add unrecognised ones or drop a
     * required one.
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>|null  $columns
     */
    private function csv(array $rows, ?array $columns = null): UploadedFile
    {
        $columns ??= OpeningStockCsvSchema::COLUMNS;

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', $columns), escape: '');
        }
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent('opening-stock.csv', $contents);
    }

    /**
     * The branch has traded. Written straight to the ledger rather than rung up
     * through POS: what closes the window is a `type = 'sale'` row existing, and
     * building a whole transaction to produce one would tie this file's subject
     * to the checkout path.
     */
    private function sellSomething(User $actor): void
    {
        $variant = $this->variant('SOLD-001', 'Something Sold');

        app(StockLedger::class)->record(
            variant: $variant,
            quantity: 5,
            type: 'stock_adjustment',
            saleType: null,
            referenceType: 'manual_adjustment',
            referenceId: null,
            performedBy: $actor,
        );

        app(StockLedger::class)->record(
            variant: $variant,
            quantity: -1,
            type: 'sale',
            saleType: 'sale',
            referenceType: 'transaction',
            referenceId: null,
            performedBy: $actor,
        );
    }

    // ---------------------------------------------------------------- the window

    public function test_the_window_is_open_on_a_branch_that_has_not_sold(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inventory'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('openingWindow', true));
    }

    public function test_the_window_closes_at_the_first_sale(): void
    {
        $owner = User::factory()->owner()->create();
        $this->sellSomething($owner);

        $this->actingAs($owner)
            ->get(route('inventory'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('openingWindow', false));
    }

    /**
     * A closed window is a 403, not a field error: the route no longer exists
     * for this branch, and a validation message would suggest a different file
     * might work.
     */
    public function test_a_closed_window_refuses_every_route(): void
    {
        $owner = User::factory()->owner()->create();
        $this->variant('WATER-500');
        $this->sellSomething($owner);

        $file = $this->csv([['sku' => 'WATER-500', 'quantity' => '24']]);

        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $file])->assertForbidden();
        $this->actingAs($owner)->post(route('opening-stock.preview'), ['scope' => 'local', 'file' => $file])->assertForbidden();

        $this->assertSame(0, ImportHistory::query()->count());
    }

    /**
     * A return or a defect closes it too. Both are `type = 'sale'` and split on
     * `sale_type`, and any of the three means the branch is trading.
     */
    public function test_a_defect_closes_the_window_as_well_as_a_sale(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        app(StockLedger::class)->record(
            variant: $variant, quantity: 0, type: 'sale', saleType: 'defect',
            referenceType: 'return', referenceId: null, performedBy: $owner,
        );

        $this->actingAs($owner)
            ->get(route('inventory'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('openingWindow', false));
    }

    // ------------------------------------------------------------------ the write

    public function test_it_sets_stock_from_a_file(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '24']])])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(24, $this->stockOf($variant));

        $entry = InventoryLedger::query()->sole();
        $this->assertSame(24, $entry->quantity);
        $this->assertSame('stock_adjustment', $entry->type);
        // The value a spreadsheet-sourced movement has carried since Phase 7,
        // and the reason no version floor moves for this feature.
        $this->assertSame('csv_import', $entry->reference_type);

        $import = ImportHistory::query()->sole();
        $this->assertSame('success', $import->status);
        $this->assertSame('opening_stock', $import->mode);
        $this->assertSame(1, $import->total_rows);
        $this->assertSame(1, $import->success_count);
        // The movement is traceable back to the file that asked for it.
        $this->assertSame($import->id, $entry->reference_id);
    }

    /**
     * The property the whole design rests on: a quantity is a TOTAL, so running
     * the same file twice is not running it twice.
     */
    public function test_re_uploading_the_same_file_writes_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');
        $rows = [['sku' => 'WATER-500', 'quantity' => '24']];

        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv($rows)]);
        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv($rows)])
            ->assertSessionHasNoErrors();

        $this->assertSame(24, $this->stockOf($variant));
        $this->assertSame(1, InventoryLedger::query()->count());
        // Both runs are recorded — the second really happened, it just had
        // nothing to do.
        $this->assertSame(2, ImportHistory::query()->count());
    }

    public function test_a_correction_writes_only_the_difference(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '10']])]);
        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '4']])])
            ->assertSessionHasNoErrors();

        $this->assertSame(4, $this->stockOf($variant));
        $this->assertSame(
            [10, -6],
            InventoryLedger::query()->orderBy('created_at')->orderBy('id')->pluck('quantity')->all(),
        );
    }

    /**
     * MULTIBRANCH-ROADMAP.md M7: a zero opening balance is the absence of a
     * movement, not a movement of nothing. A snapshot written without the
     * movement behind it is what made a branch unable to ever sync again.
     */
    public function test_a_zero_quantity_writes_no_ledger_row(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '0']])])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->stockOf($variant));
        $this->assertSame(0, InventoryLedger::query()->count());
        $this->assertSame(0, StockSnapshot::query()->count());

        // The row was accepted, so the import succeeded. It simply asked for a
        // shelf that already said what it says.
        $import = ImportHistory::query()->sole();
        $this->assertSame('success', $import->status);
        $this->assertSame(1, $import->success_count);
    }

    /**
     * The same spreadsheet the item import was fed, with a quantity column
     * added — the workflow the two-file design exists to allow.
     */
    public function test_unrecognised_columns_are_ignored(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        $file = $this->csv(
            [[
                'category' => 'Beverages',
                'product' => 'Bottled Water',
                'sku' => 'WATER-500',
                'selling_price' => '15.00',
                'stock' => '999',
                'quantity' => '24',
            ]],
            ['category', 'product', 'sku', 'selling_price', 'stock', 'quantity'],
        );

        $this->actingAs($owner)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $file])->assertSessionHasNoErrors();

        // 24 from `quantity`, and emphatically not 999 from the leftover `stock`
        // column the item file used to carry.
        $this->assertSame(24, $this->stockOf($variant));
    }

    // ----------------------------------------------------------------- refusals

    /**
     * @return array<string, array{0: list<array<string, string>>, 1: string}>
     */
    public static function badFiles(): array
    {
        return [
            'unknown sku' => [[['sku' => 'NOPE-001', 'quantity' => '5']], 'No product has SKU'],
            'duplicate sku' => [
                [['sku' => 'WATER-500', 'quantity' => '5'], ['sku' => 'WATER-500', 'quantity' => '7']],
                'appears on more than one row',
            ],
            'negative quantity' => [[['sku' => 'WATER-500', 'quantity' => '-5']], 'whole number'],
            'fractional quantity' => [[['sku' => 'WATER-500', 'quantity' => '5.5']], 'whole number'],
            'blank quantity' => [[['sku' => 'WATER-500', 'quantity' => '']], 'Quantity is required'],
            'blank sku' => [[['sku' => '', 'quantity' => '5']], 'SKU is required'],
        ];
    }

    /**
     * Every one of these fails the WHOLE file. All-or-nothing is what lets a
     * bad row be fixed and the file re-uploaded without wondering which half
     * already landed.
     *
     * @param  list<array<string, string>>  $rows
     */
    #[DataProvider('badFiles')]
    public function test_a_bad_file_writes_nothing_and_says_why(array $rows, string $expected): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv($rows)])
            ->assertRedirect();

        $this->assertSame(0, $this->stockOf($variant));
        $this->assertSame(0, InventoryLedger::query()->count());

        $import = ImportHistory::query()->sole();
        $this->assertSame('failed', $import->status);
        $this->assertSame('opening_stock', $import->mode);
        $this->assertStringContainsString($expected, json_encode($import->errors));
    }

    public function test_an_archived_product_is_refused_by_name(): void
    {
        $owner = User::factory()->owner()->create();
        $item = Item::factory()->create(['name' => 'Discontinued', 'archived_at' => now()]);
        Variant::factory()->for($item)->create(['sku' => 'OLD-001']);

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'OLD-001', 'quantity' => '5']])]);

        $import = ImportHistory::query()->sole();
        $this->assertSame('failed', $import->status);
        // Named as archived rather than as unknown — "no product has SKU X"
        // sends somebody looking for a typo that is not there.
        $this->assertStringContainsString('archived product', json_encode($import->errors));
    }

    /**
     * An archived VARIANT of a live product gets its own message. Loading
     * archived rows in the lookup is what makes the distinction possible at all.
     */
    public function test_an_archived_variant_is_refused_by_name(): void
    {
        $owner = User::factory()->owner()->create();
        Variant::factory()
            ->for(Item::factory()->create(['name' => 'Still Sold']))
            ->create(['sku' => 'OLD-VAR', 'archived_at' => now()]);

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'OLD-VAR', 'quantity' => '5']])]);

        $import = ImportHistory::query()->sole();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString('archived variant', json_encode($import->errors));
        $this->assertSame(0, InventoryLedger::query()->count());
    }

    public function test_a_missing_column_is_refused(): void
    {
        $owner = User::factory()->owner()->create();
        $this->variant('WATER-500');

        $this->actingAs($owner)->post(route('opening-stock.store'), [
            'scope' => 'local',
            'file' => $this->csv([['sku' => 'WATER-500']], ['sku']),
        ]);

        $this->assertStringContainsString('quantity', json_encode(ImportHistory::query()->sole()->errors));
        $this->assertSame(0, InventoryLedger::query()->count());
    }

    // ------------------------------------------------- the stated target branch

    /**
     * The guard the branch scope needs, and the reason it is on the server
     * rather than only on the button.
     *
     * `/inventory` shows another branch's stock under that branch's heading
     * while everything else on the page stays local. An import fired from there
     * cannot write that branch — `OnlineWrite` stamps `branch_id` from
     * `instance` — so without this it would land on THIS branch, report success,
     * and be discovered when the other shop opened to empty shelves.
     */
    public function test_a_request_naming_another_branch_is_refused(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');
        $file = $this->csv([['sku' => 'WATER-500', 'quantity' => '24']]);

        $elsewhere = (string) Str::uuid();

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => $elsewhere, 'file' => $file])
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('opening-stock.preview'), ['scope' => $elsewhere, 'file' => $file])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, "THIS branch's stock"));

        $this->assertSame(0, $this->stockOf($variant));
        $this->assertSame(0, InventoryLedger::query()->count());
        $this->assertSame(0, ImportHistory::query()->count());
    }

    /**
     * Omission is the one bypass a stated intent must not have. A caller that
     * names no branch has not considered the question, which is exactly the
     * caller the screen's heading would have answered wrongly.
     */
    public function test_a_request_naming_no_branch_at_all_is_refused(): void
    {
        $owner = User::factory()->owner()->create();
        $this->variant('WATER-500');

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '24']])])
            ->assertForbidden();

        $this->assertSame(0, InventoryLedger::query()->count());
    }

    /**
     * This branch's own id is the same statement as `local`, as it is on every
     * other screen carrying a scope (`OffersBranchScope::isThisBranch()`).
     */
    public function test_this_branchs_own_id_is_accepted_like_local(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), [
                'scope' => (string) Instance::current()->branch_id,
                'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '24']]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(24, $this->stockOf($variant));
    }

    /**
     * The window is checked BEFORE the target, so a branch that has traded is
     * told the true reason rather than being sent to fix a scope that is fine.
     */
    public function test_a_closed_window_outranks_the_target_check(): void
    {
        $owner = User::factory()->owner()->create();
        $this->variant('WATER-500');
        $this->sellSomething($owner);

        $this->actingAs($owner)
            ->postJson(route('opening-stock.preview'), [
                'scope' => (string) Str::uuid(),
                'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '24']]),
            ])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'already made sales'));
    }

    public function test_a_cashier_cannot_reach_the_routes(): void
    {
        $cashier = User::factory()->cashier()->create();
        $file = $this->csv([['sku' => 'WATER-500', 'quantity' => '5']]);

        $this->actingAs($cashier)->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $file])->assertForbidden();
        $this->actingAs($cashier)->post(route('opening-stock.preview'), ['scope' => 'local', 'file' => $file])->assertForbidden();
        $this->actingAs($cashier)->get(route('opening-stock.template'))->assertForbidden();
    }

    // ------------------------------------------------------------------ preview

    public function test_the_preview_reports_the_arithmetic_and_writes_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        app(StockLedger::class)->record(
            variant: $variant, quantity: 10, type: 'stock_adjustment', saleType: null,
            referenceType: 'manual_adjustment', referenceId: null, performedBy: $owner,
        );

        $this->actingAs($owner)
            ->post(route('opening-stock.preview'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '24']])])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('moves', 1)
            ->assertJsonPath('unchanged', 0)
            ->assertJsonPath('lines.0.current', 10)
            ->assertJsonPath('lines.0.quantity', 24)
            ->assertJsonPath('lines.0.delta', 14);

        // One ledger row: the fixture's, and nothing the preview added.
        $this->assertSame(1, InventoryLedger::query()->count());
        $this->assertSame(0, ImportHistory::query()->count());
        $this->assertSame(10, $this->stockOf($variant));
    }

    public function test_the_preview_counts_lines_that_would_not_move(): void
    {
        $owner = User::factory()->owner()->create();
        $this->variant('WATER-500');
        $this->variant('JUICE-250', 'Orange Juice');

        $this->actingAs($owner)
            ->post(route('opening-stock.preview'), ['scope' => 'local', 'file' => $this->csv([
                ['sku' => 'WATER-500', 'quantity' => '24'],
                ['sku' => 'JUICE-250', 'quantity' => '0'],
            ])])
            ->assertOk()
            ->assertJsonPath('moves', 1)
            ->assertJsonPath('unchanged', 1);
    }

    // ------------------------------------------------------------------- upward

    /**
     * The rule that binds anything putting opening stock into a business schema:
     * write a LEDGER ENTRY, not just a snapshot.
     *
     * Postgres has no trigger behind `stock_snapshots` — the till maintains it,
     * and `RemoteStock::recompute()` rebuilds it by summing Postgres's OWN
     * ledger. So the mirrored rows have to land before the recompute runs, which
     * is what `afterMirroring()` orders. Get it wrong and the snapshot upstream
     * stays at zero while this device reads the real figure; the branch's first
     * sale then sums negative, `stock_snapshots_non_negative` refuses it, and
     * the refusal takes down every cycle the branch ever runs again.
     *
     * The stand-in is SQLite, so this proves the ORDER and the arithmetic. That
     * it survives real Postgres is tests/Integration/OpeningStockImportTest.php.
     */
    public function test_the_movements_reach_the_business_database_and_the_snapshot_is_recomputed(): void
    {
        $remote = $this->fakeBusinessQueue();

        $owner = User::factory()->owner()->create();
        $variant = $this->variant('WATER-500');

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '24']])])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $remote->table('inventory_ledger')->count());
        $this->assertSame(24, (int) $remote->table('inventory_ledger')->value('quantity'));

        // Recomputed from the ledger that just landed, not copied from here.
        $this->assertSame(
            24,
            (int) $remote->table('stock_snapshots')->where('variant_id', $variant->getKey())->value('quantity'),
        );
    }

    // -------------------------------------------------------------------- audit

    public function test_it_records_an_audit_entry(): void
    {
        $owner = User::factory()->owner()->create();
        $this->variant('WATER-500');

        $this->actingAs($owner)
            ->post(route('opening-stock.store'), ['scope' => 'local', 'file' => $this->csv([['sku' => 'WATER-500', 'quantity' => '24']])]);

        $entry = AuditLog::query()->where('action', 'inventory.opening_stock.imported')->sole();
        $this->assertSame($owner->getKey(), $entry->user_id);
    }

    public function test_the_template_names_both_columns(): void
    {
        $content = $this->actingAs(User::factory()->owner()->create())
            ->get(route('opening-stock.template'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('sku,quantity', $content);
    }
}
