<?php

namespace Tests\Feature\Reports;

use App\Models\Item;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Sales\ProcessSaleReturn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports (Phase 12). Each period reports on a SINGLE selected period (one day /
 * week / month / year), resolved from an anchor `date`. Within that window the
 * rows and chart bars drill DOWN one level: a day → hours, a week/month → days,
 * a year → months. Every figure is recomputed from the append-only source tables
 * as net = gross − returns − defects; after-sales events are attributed to the
 * sub-bucket they OCCURRED in. Cost/COGS/profit are Owner-only.
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    /** Same as sellable(), but under an item with a known name (for chart breakdowns). */
    private function sellableNamed(string $itemName, float $price, ?float $cost, int $stock): Variant
    {
        $item = Item::factory()->create(['name' => $itemName]);
        $variant = Variant::factory()->create(['item_id' => $item->id, 'selling_price' => $price, 'cost' => $cost]);

        app(StockLedger::class)->record(
            $variant, $stock, 'stock_adjustment', null, 'manual_adjustment', null,
            User::factory()->admin()->create(),
        );

        return $variant;
    }

    /** Ring up a sale through the real checkout and return the resulting transaction. */
    private function sell(User $cashier, Variant $variant, int $quantity): Transaction
    {
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => [['variant_id' => $variant->id, 'quantity' => $quantity]],
                'payment_type' => 'cashless',
                'cash_received' => null,
            ])
            ->assertSessionHasNoErrors();

        return Transaction::query()->orderByDesc('transaction_number')->firstOrFail();
    }

    public function test_daily_report_shows_only_the_selected_day_bucketed_by_hour()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $this->sell($cashier, $variant, 2); // gross 200 at 09:00

        $this->travelTo(Carbon::parse('2026-03-10 14:00'));
        $this->sell($cashier, $variant, 1); // gross 100 at 14:00

        // A sale the next day must be excluded from the selected day entirely.
        $this->travelTo(Carbon::parse('2026-03-11 12:00'));
        $this->sell($cashier, $variant, 5);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports', false)
                ->where('period', 'daily')
                ->has('rows', 2) // only the two hourly buckets of 03-10
                ->where('rows.0.bucket', '2026-03-10 09')
                ->where('rows.0.transactions', 1)
                ->where('rows.0.gross_sales', '200.00')
                ->where('rows.0.net_sales', '200.00')
                ->where('rows.1.bucket', '2026-03-10 14')
                ->where('rows.1.net_sales', '100.00')
                ->where('totals.transactions', 2)
                ->where('totals.gross_sales', '300.00')
                ->where('totals.net_sales', '300.00'));
    }

    public function test_filters_payload_carries_the_anchor_prev_next_and_label()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->where('filters.period', 'daily')
                ->where('filters.date', '2026-03-10')
                ->where('filters.prev', '2026-03-09')
                ->where('filters.next', '2026-03-11')
                ->has('filters.label'));

        // Weekly snaps the anchor to the start of its Mon–Sun week, and steps by a week.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'weekly', 'date' => '2026-01-07'])) // Wed
            ->assertInertia(fn ($page) => $page
                ->where('filters.date', '2026-01-05') // Monday of that week
                ->where('filters.prev', '2025-12-29')
                ->where('filters.next', '2026-01-12'));
    }

    public function test_defaults_to_the_latest_period_when_no_date_is_given()
    {
        // Switching granularity sends no date; the server anchors on "now", so each
        // period defaults to its latest instance (today / this week / month / year).
        $this->travelTo(Carbon::parse('2026-07-17 12:00')); // a Friday

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('reports', ['period' => 'daily']))
            ->assertInertia(fn ($page) => $page->where('filters.date', '2026-07-17'));

        $this->actingAs($admin)
            ->get(route('reports', ['period' => 'weekly']))
            ->assertInertia(fn ($page) => $page->where('filters.date', '2026-07-13')); // Monday

        $this->actingAs($admin)
            ->get(route('reports', ['period' => 'monthly']))
            ->assertInertia(fn ($page) => $page->where('filters.date', '2026-07-01'));

        $this->actingAs($admin)
            ->get(route('reports', ['period' => 'yearly']))
            ->assertInertia(fn ($page) => $page->where('filters.date', '2026-01-01'));
    }

    public function test_returns_reduce_net_on_the_hour_they_occur()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $transaction = $this->sell($cashier, $variant, 2); // gross 200

        // Return one unit later the same day — it lands on the 15:00 bucket.
        $this->travelTo(Carbon::parse('2026-03-10 15:00'));
        app(ProcessSaleReturn::class)->process(
            $transaction,
            'return',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            null,
            'cashless',
            $cashier,
        );

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 2)
                // Sale hour is untouched by the later return.
                ->where('rows.0.bucket', '2026-03-10 09')
                ->where('rows.0.gross_sales', '200.00')
                ->where('rows.0.returns', '0.00')
                ->where('rows.0.net_sales', '200.00')
                // Return hour: no sales, a 100 refund, net goes negative.
                ->where('rows.1.bucket', '2026-03-10 15')
                ->where('rows.1.gross_sales', '0.00')
                ->where('rows.1.returns', '100.00')
                ->where('rows.1.net_sales', '-100.00')
                // Period net = 200 − 100.
                ->where('totals.returns', '100.00')
                ->where('totals.net_sales', '100.00'));
    }

    public function test_defects_reduce_net_sales()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();
        $admin = User::factory()->admin()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $transaction = $this->sell($cashier, $variant, 2); // gross 200

        // Defect (Admin+) one unit the same hour.
        app(ProcessSaleReturn::class)->process(
            $transaction,
            'defect',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            'damaged',
            null,
            $admin,
        );

        $this->actingAs($admin)
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.gross_sales', '200.00')
                ->where('rows.0.defects', '100.00')
                ->where('rows.0.net_sales', '100.00'));
    }

    public function test_monthly_report_shows_only_the_selected_month_bucketed_by_day()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-01-15 12:00'));
        $this->sell($cashier, $variant, 1); // gross 100

        $this->travelTo(Carbon::parse('2026-01-20 12:00'));
        $this->sell($cashier, $variant, 2); // gross 200

        // A February sale must be excluded from a January report.
        $this->travelTo(Carbon::parse('2026-02-20 12:00'));
        $this->sell($cashier, $variant, 5);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'monthly', 'date' => '2026-01-10']))
            ->assertInertia(fn ($page) => $page
                ->where('period', 'monthly')
                ->has('rows', 2) // two daily buckets within January
                ->where('rows.0.bucket', '2026-01-15')
                ->where('rows.0.net_sales', '100.00')
                ->where('rows.1.bucket', '2026-01-20')
                ->where('rows.1.net_sales', '200.00')
                ->where('totals.net_sales', '300.00'));
    }

    public function test_cost_and_profit_are_owner_only()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $transaction = $this->sell($cashier, $variant, 2); // gross 200, COGS 80

        // Return one unit later the same day — its cost is credited back out of COGS.
        $this->travelTo(Carbon::parse('2026-03-10 15:00'));
        app(ProcessSaleReturn::class)->process(
            $transaction,
            'return',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            null,
            'cashless',
            $cashier,
        );

        // Owner sees COGS + profit. Sale hour: net 200, COGS 80, profit 120.
        // Return hour: net −100, COGS −40 (returned cost credited), profit −60.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->where('canViewCost', true)
                ->where('rows.0.cogs', '80.00')
                ->where('rows.0.profit', '120.00')
                ->where('rows.1.cogs', '-40.00')
                ->where('rows.1.profit', '-60.00')
                ->where('totals.cogs', '40.00')
                ->where('totals.profit', '60.00'));

        // Admin never receives cost/profit — the keys are absent.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->where('canViewCost', false)
                ->missing('rows.0.cogs')
                ->missing('rows.0.profit')
                ->missing('rows.0.uncosted_sales')
                ->missing('totals.profit')
                ->missing('totals.uncosted_sales'));
    }

    public function test_uncosted_items_are_split_out_of_profit()
    {
        $costed = $this->sellable(price: 100, cost: 40, stock: 50);
        $uncosted = $this->sellable(price: 100, cost: null, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $this->sell($cashier, $costed, 1);   // gross 100, cost 40
        $this->sell($cashier, $uncosted, 1); // gross 100, no cost recorded

        // Net 200. Profit covers the costed item only: 100 − 40 = 60. The un-costed
        // item's 100 is reported as uncosted_sales, NOT as pure profit.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.net_sales', '200.00')
                ->where('rows.0.cogs', '40.00')
                ->where('rows.0.uncosted_sales', '100.00')
                ->where('rows.0.profit', '60.00')
                ->where('totals.uncosted_sales', '100.00')
                ->where('totals.profit', '60.00'));
    }

    public function test_weekly_report_shows_only_the_selected_week_bucketed_by_day()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        // Week of Mon 2026-01-05 .. Sun 2026-01-11.
        $this->travelTo(Carbon::parse('2026-01-05 12:00'));
        $this->sell($cashier, $variant, 1); // gross 100

        $this->travelTo(Carbon::parse('2026-01-08 12:00'));
        $this->sell($cashier, $variant, 2); // gross 200

        // A sale in a later week must be excluded.
        $this->travelTo(Carbon::parse('2026-01-19 12:00'));
        $this->sell($cashier, $variant, 5);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'weekly', 'date' => '2026-01-06']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'weekly')
                ->has('rows', 2)
                ->where('rows.0.bucket', '2026-01-05')
                ->where('rows.0.net_sales', '100.00')
                ->where('rows.1.bucket', '2026-01-08')
                ->where('rows.1.net_sales', '200.00')
                ->where('totals.net_sales', '300.00'));
    }

    public function test_yearly_report_shows_only_the_selected_year_bucketed_by_month()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 100);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-06-01 12:00'));
        $this->sell($cashier, $variant, 3); // gross 300 (June)

        $this->travelTo(Carbon::parse('2026-08-01 12:00'));
        $this->sell($cashier, $variant, 1); // gross 100 (August)

        // A sale in the previous year must be excluded.
        $this->travelTo(Carbon::parse('2025-06-01 12:00'));
        $this->sell($cashier, $variant, 2);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'yearly', 'date' => '2026-03-01']))
            ->assertInertia(fn ($page) => $page
                ->where('period', 'yearly')
                ->has('rows', 2)
                ->where('rows.0.bucket', '2026-06')
                ->where('rows.0.net_sales', '300.00')
                ->where('rows.1.bucket', '2026-08')
                ->where('rows.1.net_sales', '100.00')
                ->where('chart.0.bucket', '2026-06')
                ->whereType('chart.0.bucket', 'string'));
    }

    public function test_chart_breaks_net_sales_down_by_item()
    {
        $apple = $this->sellableNamed('Apple', price: 100, cost: 40, stock: 50);
        $banana = $this->sellableNamed('Banana', price: 50, cost: 20, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $this->sell($cashier, $apple, 1);  // gross 100
        $this->sell($cashier, $banana, 2); // gross 100

        // Both items gross 100, so ranking ties and breaks by name (Apple, Banana).
        // Each segment sums to the bucket's net (200).
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->has('chartItems', 2)
                ->where('chartItems.0.name', 'Apple')
                ->where('chartItems.1.name', 'Banana')
                ->has('chart', 1)
                ->where('chart.0.bucket', '2026-03-10 12')
                ->where("chart.0.values.{$apple->item_id}", '100.00')
                ->where("chart.0.values.{$banana->item_id}", '100.00')
                ->where('rows.0.net_sales', '200.00'));
    }

    public function test_chart_caps_items_at_top_8_plus_other()
    {
        $cashier = User::factory()->cashier()->create();
        $this->travelTo(Carbon::parse('2026-03-10 12:00'));

        // Ten items with strictly descending revenue (Item 1 = 100 … Item 10 = 10).
        for ($i = 1; $i <= 10; $i++) {
            $variant = $this->sellableNamed("Item {$i}", price: (11 - $i) * 10, cost: 1, stock: 10);
            $this->sell($cashier, $variant, 1);
        }

        // Top 8 keep their own segment; Items 9 (20) + 10 (10) fold into Other (30).
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->has('chartItems', 9)
                ->where('chartItems.0.name', 'Item 1')
                ->where('chartItems.8.key', '__other__')
                ->where('chartItems.8.name', 'Other')
                ->where('chart.0.values.__other__', '30.00'));
    }

    public function test_chart_segment_goes_negative_when_item_returns_exceed_its_sales()
    {
        $apple = $this->sellableNamed('Apple', price: 100, cost: 40, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $transaction = $this->sell($cashier, $apple, 2); // gross 200

        // Return one unit later the same day — that later hour has no sales for the
        // item, so its net there is a downward segment (−100).
        $this->travelTo(Carbon::parse('2026-03-10 14:00'));
        app(ProcessSaleReturn::class)->process(
            $transaction,
            'return',
            [['transaction_item_id' => $transaction->items()->first()->id, 'quantity' => 1]],
            null,
            'cashless',
            $cashier,
        );

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->has('chart', 2)
                ->where('chart.0.bucket', '2026-03-10 09')
                ->where("chart.0.values.{$apple->item_id}", '200.00')
                ->where('chart.1.bucket', '2026-03-10 14')
                ->where("chart.1.values.{$apple->item_id}", '-100.00'));
    }

    public function test_transaction_discount_is_apportioned_across_the_uncosted_split()
    {
        $costed = $this->sellable(price: 100, cost: 40, stock: 50);
        $uncosted = $this->sellable(price: 100, cost: null, stock: 50);
        $cashier = User::factory()->cashier()->create();

        // One sale of both lines (subtotal 200) with a ₱20 transaction discount → total 180.
        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'lines' => [
                    ['variant_id' => $costed->id, 'quantity' => 1],
                    ['variant_id' => $uncosted->id, 'quantity' => 1],
                ],
                'payment_type' => 'cashless',
                'discount' => 20,
                'discount_type' => 'amount',
                'cash_received' => null,
            ])
            ->assertSessionHasNoErrors();

        // Net 180. The discount is apportioned by line share (50/50 here), so the
        // un-costed half is 90 and the costed half 90 → profit 90 − 40 = 50. The
        // split reconciles exactly to net (90 costed + 90 uncosted = 180).
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.net_sales', '180.00')
                ->where('rows.0.uncosted_sales', '90.00')
                ->where('rows.0.cogs', '40.00')
                ->where('rows.0.profit', '50.00'));
    }

    public function test_export_streams_a_pdf_for_admin_and_owner()
    {
        // Two named items so the top-items grouping (and its refund join) actually
        // exercises more than one group end-to-end.
        $apple = $this->sellableNamed('Apple', price: 100, cost: 40, stock: 50);
        $banana = $this->sellableNamed('Banana', price: 50, cost: 20, stock: 50);
        $cashier = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-03-10 12:00'));
        $this->sell($cashier, $apple, 2);
        $this->sell($cashier, $banana, 1);

        foreach ([User::factory()->admin()->create(), User::factory()->owner()->create()] as $user) {
            $response = $this->actingAs($user)
                ->get(route('reports.export', ['period' => 'daily', 'date' => '2026-03-10']));

            $response->assertOk();
            $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_pdf_view_renders_the_net_sales_chart_and_top_items_table()
    {
        // The dompdf output stream is compressed, so assert against the Blade view's
        // rendered HTML directly. Two buckets — one positive, one negative net — plus
        // two ranked top items, mirroring what ReportsController::export passes.
        $data = [
            'business' => ['business_name' => 'Acme Store', 'business_address' => '', 'tin' => '', 'receipt_footer' => ''],
            'period' => 'daily',
            'periodNoun' => 'Hour',
            'label' => 'Tuesday, Mar 10, 2026',
            'generatedAt' => Carbon::parse('2026-03-10 16:00'),
            'rows' => [
                ['bucket' => '2026-03-10 09', 'label' => '9:00 AM', 'transactions' => 1, 'gross_sales' => '200.00', 'returns' => '0.00', 'defects' => '0.00', 'net_sales' => '200.00'],
                ['bucket' => '2026-03-10 15', 'label' => '3:00 PM', 'transactions' => 0, 'gross_sales' => '0.00', 'returns' => '100.00', 'defects' => '0.00', 'net_sales' => '-100.00'],
            ],
            'totals' => ['transactions' => 1, 'gross_sales' => '200.00', 'returns' => '100.00', 'defects' => '0.00', 'net_sales' => '100.00'],
            'topItems' => [
                ['name' => 'Apple', 'quantity' => 3, 'net_sales' => '250.00'],
                ['name' => 'Banana', 'quantity' => 2, 'net_sales' => '100.00'],
            ],
            'canViewCost' => false,
            'scopeLabel' => 'This branch',
            'branchRows' => null,
        ];

        $html = view('reports.pdf', $data)->render();

        // Chart: a positive (blue, up) and a negative (red, down) bar are emitted.
        $this->assertStringContainsString('class="bar"', $html);
        $this->assertStringContainsString('background-color: #2a78d6', $html); // positive net
        $this->assertStringContainsString('background-color: #e34948', $html); // negative net

        // Top-items table.
        $this->assertStringContainsString('Top selling items', $html);
        $this->assertStringContainsString('Apple', $html);
        $this->assertStringContainsString('₱250.00', $html);
        $this->assertStringContainsString('Banana', $html);

        // No cost/profit leaks for a non-Owner viewer, anywhere in the document.
        $this->assertStringNotContainsString('Profit', $html);
        $this->assertStringNotContainsString('Uncosted', $html);
    }

    public function test_export_is_blocked_for_cashiers()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('reports.export', ['period' => 'daily', 'date' => '2026-03-10']))
            ->assertForbidden();
    }
}
