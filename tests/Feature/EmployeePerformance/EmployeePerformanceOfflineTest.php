<?php

namespace Tests\Feature\EmployeePerformance;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Email\ReportEmailSettings;
use App\Support\Sync\OnlineRead;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What the employee report does when the business database cannot be reached.
 *
 * This suite deliberately does NOT install
 * {@see Concerns\ReadsTheBusinessLocally}: no test device holds a sealed branch
 * credential, so the real {@see OnlineRead} cannot open a
 * connection, and that is exactly the case under test.
 *
 * The rule being pinned is that there is NO fallback. Every other scoped screen
 * degrades to `local` and says so, because a branch's own rows are a smaller true
 * answer to a question about that branch. This report asks about a PERSON, who
 * can work at more than one branch, so the local mirror is not a smaller answer
 * but a wrong one — a calendar that looks entirely correct and says somebody
 * posted elsewhere never came in all month. Better to show nothing and say why.
 */
class EmployeePerformanceOfflineTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_renders_and_says_it_cannot_report()
    {
        $employee = User::factory()->cashier()->create(['name' => 'Alma Reyes']);

        // Local rows that the deleted fallback would have reported on. Nothing
        // may come back from them: their presence is the whole trap.
        LoginHistory::create([
            'user_id' => $employee->id,
            'event_type' => LoginEventType::Login->value,
            'occurred_at' => Carbon::parse('2026-08-03 08:00'),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('employee-performance', ['user' => $employee->id, 'month' => 8, 'year' => 2026]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('employee-performance', false)
                ->where('online', false)
                // No month at all, rather than this branch's month.
                ->where('days', [])
                ->where('summary', null)
                // The employee picker is still read locally and still names who
                // the page would have reported on, so the notice appears under a
                // chosen employee rather than a blank screen.
                ->where('employee.name', 'Alma Reyes')
                ->has('employees', 2));
    }

    public function test_the_page_still_offers_the_months_and_years_it_would_report_on()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('employee-performance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('online', false)
                ->has('years', 6)
                ->has('filters.month')
                ->has('filters.prev_month'));
    }

    public function test_a_days_receipts_come_back_null_rather_than_empty()
    {
        $employee = User::factory()->cashier()->create();

        // Null, not `[]`. An empty list is a day this employee sold nothing on,
        // which is a claim this device is in no position to make — and the
        // dialog tells the two apart on exactly this value.
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('employee-performance.day', ['user' => $employee->id, 'date' => '2026-08-03']))
            ->assertOk()
            ->assertExactJson(['sales' => null]);
    }

    public function test_a_send_is_refused_and_nothing_leaves_the_device()
    {
        Mail::fake();

        // Configured on purpose: the refusal must come from the report having no
        // figures, not from there being nowhere to send them.
        $settings = app(ReportEmailSettings::class);
        $settings->update([
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_from_address' => 'reports@shop.test',
        ]);
        $settings->setRecipients(['owner@shop.test']);

        $employee = User::factory()->cashier()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('employee-performance.email'), [
                'recipients' => ['owner@shop.test'],
                'users' => [$employee->id],
                'month' => 8,
                'year' => 2026,
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');

        Mail::assertNothingSent();

        // A refusal before any PDF was built is not a failed send, so it writes
        // no audit row — the same shape as posting an employee that does not
        // exist.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'employee_report.emailed']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'employee_report.email_failed']);
    }
}
