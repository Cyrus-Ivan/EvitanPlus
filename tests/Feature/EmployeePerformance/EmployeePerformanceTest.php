<?php

namespace Tests\Feature\EmployeePerformance;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variant;
use App\Support\Inventory\StockLedger;
use App\Support\Reports\EmployeePerformanceBuilder;
use App\Support\Reports\EmployeePerformancePdf;
use App\Support\Sales\ProcessSaleReturn;
use App\Support\Settings\BusinessSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\EmployeePerformance\Concerns\ReadsTheBusinessLocally;
use Tests\TestCase;

/**
 * Employee performance: one employee, one month, attendance and sales on the
 * same calendar.
 *
 * Every figure is recomputed from the append-only source tables — net = gross −
 * returns − defects, with a refund attributed to the cashier who made the SALE.
 * There is no cost, COGS or profit anywhere on it, for anybody.
 *
 * The screen is online-only, so the read is pointed at this device's own
 * connection — see {@see ReadsTheBusinessLocally} for what that does and does not
 * prove. The offline state is a suite of its own
 * ({@see EmployeePerformanceOfflineTest}), because it is the one case this trait
 * takes away.
 */
class EmployeePerformanceTest extends TestCase
{
    use ReadsTheBusinessLocally, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->readBusinessLocally();
    }

    private function sellable(float $price, ?float $cost, int $stock): Variant
    {
        $variant = Variant::factory()->create(['selling_price' => $price, 'cost' => $cost]);

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

    private function shift(User $user, string $in, ?string $out): void
    {
        LoginHistory::create([
            'user_id' => $user->id,
            'event_type' => LoginEventType::Login->value,
            'occurred_at' => Carbon::parse($in),
        ]);

        if ($out !== null) {
            LoginHistory::create([
                'user_id' => $user->id,
                'event_type' => LoginEventType::Logout->value,
                'occurred_at' => Carbon::parse($out),
            ]);
        }
    }

    private function month(User $viewer, User $employee, int $month = 8, int $year = 2026)
    {
        return $this->actingAs($viewer)->get(route('employee-performance', [
            'user' => $employee->id,
            'month' => $month,
            'year' => $year,
        ]));
    }

    public function test_the_calendar_covers_every_day_of_the_month_worked_or_not()
    {
        $employee = User::factory()->cashier()->create();

        $this->month(User::factory()->admin()->create(), $employee)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('employee-performance', false)
                // August has 31 days, all present, so a day off reads as
                // "worked nothing" rather than "no data".
                ->has('days', 31)
                ->where('days.0.date', '2026-08-01')
                ->where('days.0.weekday', 6) // Saturday, ISO
                ->where('days.0.shifts', [])
                ->where('days.0.transactions', 0)
                ->where('days.0.net_sales', '0.00')
                ->where('days.30.date', '2026-08-31')
                ->where('summary.days_worked', 0)
                ->where('summary.hours', 0));
    }

    public function test_a_days_shifts_and_that_days_sales_land_on_the_same_cell()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $employee = User::factory()->cashier()->create();

        $this->shift($employee, '2026-08-03 08:00', '2026-08-03 16:00');

        $this->travelTo(Carbon::parse('2026-08-03 10:00'));
        $this->sell($employee, $variant, 2); // 200
        $this->travelTo(Carbon::parse('2026-08-03 15:00'));
        $this->sell($employee, $variant, 1); // 100
        $this->travelBack();

        $this->month(User::factory()->admin()->create(), $employee)
            ->assertInertia(fn ($page) => $page
                ->has('days.2.shifts', 1)
                ->where('days.2.date', '2026-08-03')
                ->where('days.2.shifts.0.open', false)
                ->where('days.2.shifts.0.hours', 8)
                ->where('days.2.shifts.0.crosses_midnight', false)
                ->where('days.2.hours', 8)
                ->where('days.2.transactions', 2)
                ->where('days.2.net_sales', '300.00')
                ->where('summary.days_worked', 1)
                ->where('summary.hours', 8)
                ->where('summary.transactions', 2)
                ->where('summary.net_sales', '300.00')
                ->where('summary.sales_per_hour', '37.50'));
    }

    public function test_a_night_shift_is_one_cell_and_its_after_midnight_sales_are_the_next_days()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $employee = User::factory()->cashier()->create();

        $this->shift($employee, '2026-08-03 20:00', '2026-08-04 04:00');

        $this->travelTo(Carbon::parse('2026-08-03 22:00'));
        $this->sell($employee, $variant, 1);
        $this->travelTo(Carbon::parse('2026-08-04 01:00'));
        $this->sell($employee, $variant, 3);
        $this->travelBack();

        $this->month(User::factory()->admin()->create(), $employee)
            ->assertInertia(fn ($page) => $page
                // The shift belongs to the day it started, whole.
                ->has('days.2.shifts', 1)
                ->where('days.2.shifts.0.crosses_midnight', true)
                ->where('days.2.hours', 8)
                ->has('days.3.shifts', 0)
                // A sale belongs to the moment it was rung, so the tail of the
                // shift is counted on the 4th. Both are right; the page says so.
                ->where('days.2.net_sales', '100.00')
                ->where('days.3.net_sales', '300.00')
                ->where('summary.days_worked', 1)
                ->where('summary.net_sales', '400.00'));
    }

    public function test_an_open_shift_contributes_no_hours_and_is_counted_separately()
    {
        $employee = User::factory()->cashier()->create();

        $this->shift($employee, '2026-08-05 09:00', '2026-08-05 17:00');
        $this->shift($employee, '2026-08-06 09:00', null);

        $this->month(User::factory()->admin()->create(), $employee)
            ->assertInertia(fn ($page) => $page
                ->where('days.5.shifts.0.open', true)
                ->where('days.5.shifts.0.hours', null)
                // Null, not 0: an unknown day is not a day off.
                ->where('days.5.hours', null)
                ->where('summary.days_worked', 2)
                ->where('summary.shifts', 2)
                ->where('summary.open_shifts', 1)
                ->where('summary.hours', 8));
    }

    public function test_a_return_reduces_the_selling_cashiers_net_not_the_processors()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $seller = User::factory()->cashier()->create();
        $processor = User::factory()->admin()->create();

        $this->travelTo(Carbon::parse('2026-08-10 10:00'));
        $sale = $this->sell($seller, $variant, 3); // 300

        // Somebody else handles the return two days later.
        $this->travelTo(Carbon::parse('2026-08-12 11:00'));
        app(ProcessSaleReturn::class)->process(
            $sale,
            'return',
            [['transaction_item_id' => $sale->items()->first()->id, 'quantity' => 1]],
            'Changed mind',
            'cash',
            $processor,
        );
        $this->travelBack();

        $viewer = User::factory()->owner()->create();

        // The seller's month carries both the sale and the refund off it.
        $this->month($viewer, $seller)
            ->assertInertia(fn ($page) => $page
                ->where('days.9.gross_sales', '300.00')
                ->where('days.11.returns', '100.00')
                ->where('summary.gross_sales', '300.00')
                ->where('summary.returns', '100.00')
                ->where('summary.net_sales', '200.00'));

        // The person who pressed the button has no sales of their own.
        $this->month($viewer, $processor)
            ->assertInertia(fn ($page) => $page
                ->where('summary.gross_sales', '0.00')
                ->where('summary.returns', '0.00')
                ->where('summary.net_sales', '0.00'));
    }

    public function test_a_defect_reduces_net_on_the_day_it_happened()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $employee = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-08-10 10:00'));
        $sale = $this->sell($employee, $variant, 2); // 200

        $this->travelTo(Carbon::parse('2026-08-11 10:00'));
        app(ProcessSaleReturn::class)->process(
            $sale,
            'defect',
            [['transaction_item_id' => $sale->items()->first()->id, 'quantity' => 1]],
            'Broken',
            null,
            $employee,
        );
        $this->travelBack();

        $this->month(User::factory()->admin()->create(), $employee)
            ->assertInertia(fn ($page) => $page
                ->where('days.10.defects', '100.00')
                ->where('days.10.net_sales', '-100.00')
                ->where('summary.defects', '100.00')
                ->where('summary.net_sales', '100.00'));
    }

    public function test_another_employees_sales_are_not_this_employees()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $employee = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-08-10 10:00'));
        $this->sell($employee, $variant, 1);
        $this->sell($other, $variant, 5);
        $this->travelBack();

        $this->month(User::factory()->admin()->create(), $employee)
            ->assertInertia(fn ($page) => $page
                ->where('summary.transactions', 1)
                ->where('summary.net_sales', '100.00'));
    }

    public function test_the_month_is_bounded()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $employee = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-07-31 23:00'));
        $this->sell($employee, $variant, 1);
        $this->travelTo(Carbon::parse('2026-08-15 12:00'));
        $this->sell($employee, $variant, 2);
        $this->travelTo(Carbon::parse('2026-09-01 00:30'));
        $this->sell($employee, $variant, 4);
        $this->travelBack();

        $this->month(User::factory()->admin()->create(), $employee)
            ->assertInertia(fn ($page) => $page
                ->where('summary.transactions', 1)
                ->where('summary.net_sales', '200.00'));
    }

    public function test_no_cost_or_profit_figure_reaches_the_page_even_for_the_owner()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $employee = User::factory()->cashier()->create();

        $this->travelTo(Carbon::parse('2026-08-10 10:00'));
        $this->sell($employee, $variant, 1);
        $this->travelBack();

        $response = $this->month(User::factory()->owner()->create(), $employee)->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertArrayNotHasKey('profit', $props['summary']);
        $this->assertArrayNotHasKey('uncosted_sales', $props['summary']);
        $this->assertArrayNotHasKey('profit', $props['days'][9]);
        $this->assertStringNotContainsString('profit', json_encode($props));
    }

    public function test_the_page_opens_on_whoever_worked_this_branch_most_that_month()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);

        // Sorts first by name, and never rang anything here — the shape that
        // makes an alphabetical default show a month of zeros on a device where
        // plenty happened.
        User::factory()->cashier()->create(['name' => 'Aaron Reyes']);
        $busiest = User::factory()->cashier()->create(['name' => 'Zoe Santos']);
        $quieter = User::factory()->cashier()->create(['name' => 'Mika Tan']);

        $this->travelTo(Carbon::parse('2026-08-10 10:00'));
        $this->sell($busiest, $variant, 1);
        $this->sell($busiest, $variant, 1);
        $this->sell($quieter, $variant, 1);
        $this->travelBack();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('employee-performance', ['month' => 8, 'year' => 2026]))
            ->assertInertia(fn ($page) => $page
                ->where('employee.id', $busiest->id)
                ->where('summary.transactions', 2));

        // A month nobody sold in falls back to the first active name, so the
        // page is never blank.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('employee-performance', ['month' => 9, 'year' => 2026]))
            ->assertInertia(fn ($page) => $page->where('employee.name', 'Aaron Reyes'));
    }

    public function test_the_page_defaults_to_the_first_active_employee_and_this_month()
    {
        $this->travelTo(Carbon::parse('2026-08-15 12:00'));

        User::factory()->cashier()->create(['name' => 'Zara']);
        $first = User::factory()->cashier()->create(['name' => 'Alma']);
        $archived = User::factory()->cashier()->create(['name' => 'Aaron']);
        $archived->delete();

        $this->actingAs(User::factory()->admin()->create(['name' => 'Manager']))
            ->get(route('employee-performance'))
            ->assertInertia(fn ($page) => $page
                // Alma, not the archived Aaron who sorts before her.
                ->where('employee.id', $first->id)
                ->where('filters.month', 8)
                ->where('filters.year', 2026)
                ->where('filters.label', 'August 2026')
                // Archived employees stay pickable: a month worked before
                // somebody left is still readable.
                ->where('employees.0.name', 'Aaron')
                ->where('employees.0.archived', true));

        $this->travelBack();
    }

    public function test_the_steppers_walk_months_across_a_year_boundary()
    {
        $employee = User::factory()->cashier()->create();

        $this->month(User::factory()->admin()->create(), $employee, month: 1, year: 2026)
            ->assertInertia(fn ($page) => $page
                ->where('filters.prev_month', 12)
                ->where('filters.prev_year', 2025)
                ->where('filters.next_month', 2)
                ->where('filters.next_year', 2026)
                ->has('days', 31));
    }

    public function test_an_unknown_employee_is_not_found()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('employee-performance', ['user' => '01936b3e-0000-7000-8000-000000000000']))
            ->assertNotFound();
    }

    public function test_a_cashier_cannot_reach_the_page_at_all()
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee-performance'))
            ->assertForbidden();

        $this->actingAs(User::factory()->cashier()->create())
            ->post(route('employee-performance.email'), [])
            ->assertForbidden();
    }

    public function test_the_pdf_renders_the_month_as_a_calendar_and_names_who_it_is_about()
    {
        $variant = $this->sellable(price: 100, cost: 40, stock: 50);
        $employee = User::factory()->cashier()->create(['name' => 'Alma Reyes', 'username' => 'alma']);

        $this->shift($employee, '2026-08-03 20:00', '2026-08-04 04:00');
        $this->shift($employee, '2026-08-06 09:00', null); // no sign-out

        $this->travelTo(Carbon::parse('2026-08-03 22:00'));
        $this->sell($employee, $variant, 2);
        $this->travelBack();

        $from = Carbon::parse('2026-08-01')->startOfMonth();
        $report = app(EmployeePerformanceBuilder::class)
            ->build(DB::connection(), $employee->id, $from, $from->copy()->endOfMonth());

        // The dompdf output stream is compressed, so assert against the Blade
        // view's rendered HTML directly.
        $html = view('reports.employee-pdf', [
            'business' => app(BusinessSettings::class)->all(),
            'employee' => [
                'id' => $employee->id,
                'name' => 'Alma Reyes',
                'username' => 'alma',
                'role' => 'cashier',
                'archived' => false,
            ],
            'label' => 'August 2026',
            'generatedAt' => Carbon::parse('2026-09-01 09:00'),
            'weeks' => EmployeePerformancePdf::weeks($report['days']),
            'summary' => $report['summary'],
            'generatedFrom' => 'TST — Test Branch',
        ])->render();

        // Who and what it covers, on the first two lines — a document that has
        // left the screen has nothing else to say which employee it is about.
        $this->assertStringContainsString('(All branches)', $html);
        $this->assertStringContainsString('Alma Reyes', $html);
        $this->assertStringContainsString('August 2026', $html);

        // The calendar, with the night shift's +1d marker and the open shift.
        $this->assertStringContainsString('8:00pm', $html);
        $this->assertStringContainsString('+1d', $html);
        $this->assertStringContainsString('no sign-out', $html);
        $this->assertStringContainsString('₱200.00', $html);
        $this->assertStringContainsString('excluded from the hours above', $html);

        // No cost or profit exists on this report, for anybody.
        $this->assertStringNotContainsString('Profit', $html);
        $this->assertStringNotContainsString('Uncosted', $html);
    }

    public function test_the_report_carries_no_branch_scope_and_a_scope_parameter_changes_nothing()
    {
        $employee = User::factory()->cashier()->create();

        // The parameter every other reporting screen takes is not one of this
        // one's. A bookmarked or hand-typed `?scope=` must not narrow, widen or
        // break the report, and nothing about a scope may reach the page: there
        // is one answer here and it is the whole business.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('employee-performance', [
                'user' => $employee->id,
                'month' => 8,
                'year' => 2026,
                'scope' => '00000000-0000-0000-0000-00000000dead',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('online', true)
                ->has('days', 31)
                ->missing('scope')
                ->missing('branches')
                ->missing('consolidatedOnline'));
    }
}
