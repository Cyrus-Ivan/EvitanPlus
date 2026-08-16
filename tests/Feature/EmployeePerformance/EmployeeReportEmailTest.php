<?php

namespace Tests\Feature\EmployeePerformance;

use App\Mail\EmployeePerformanceMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Reports\EmployeePerformancePdf;
use App\Support\Settings\BusinessSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\EmployeePerformance\Concerns\ReadsTheBusinessLocally;
use Tests\TestCase;

/**
 * Emailing employee performance: one message, one PDF per employee.
 *
 * The document's contents are {@see EmployeePerformanceTest}'s business; this
 * file is about who it goes to, how many attachments it carries, what is
 * recorded, and what happens when it cannot be sent. A send that cannot read the
 * report at all is {@see EmployeePerformanceOfflineTest}'s.
 */
class EmployeeReportEmailTest extends TestCase
{
    use ReadsTheBusinessLocally, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The report is online-only, so there is no send at all without this.
        $this->readBusinessLocally();

        // Rendering a real document per employee is dompdf's memory paid for
        // something this class never looks at — and several sends of several
        // employees would exhaust the suite's limit. filename() stays real,
        // because the attachment names ARE what some of these tests assert on.
        $this->app->bind(
            EmployeePerformancePdf::class,
            fn (): EmployeePerformancePdf => new class(app(BusinessSettings::class)) extends EmployeePerformancePdf
            {
                public function render(array $report, array $employee, Carbon $from): string
                {
                    return '%PDF-1.4 stub for '.$employee['username'];
                }
            }
        );
    }

    private function configure(array $recipients = ['owner@shop.test', 'admin@shop.test']): ReportEmailSettings
    {
        $settings = app(ReportEmailSettings::class);
        $settings->update([
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_from_address' => 'reports@shop.test',
        ]);
        $settings->setRecipients($recipients);

        return $settings;
    }

    /**
     * @param  list<User>  $employees
     */
    private function send(User $actor, array $employees, array $overrides = [])
    {
        return $this->actingAs($actor)->post(route('employee-performance.email'), array_merge([
            'recipients' => ['owner@shop.test'],
            'users' => array_map(fn (User $user): string => $user->id, $employees),
            'month' => 8,
            'year' => 2026,
        ], $overrides));
    }

    public function test_each_employee_gets_their_own_attachment_on_one_message(): void
    {
        Mail::fake();
        $this->configure();

        $alma = User::factory()->cashier()->create(['name' => 'Alma', 'username' => 'alma']);
        $bo = User::factory()->cashier()->create(['name' => 'Bo', 'username' => 'bo']);

        $this->send(User::factory()->admin()->create(), [$alma, $bo])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        Mail::assertSent(EmployeePerformanceMail::class, function (EmployeePerformanceMail $mail): bool {
            $attachments = $mail->attachments();

            return count($attachments) === 2
                && str_contains($attachments[0]->as, 'alma')
                && str_contains($attachments[1]->as, 'bo');
        });

        // One message, not one per employee.
        Mail::assertSentCount(1);
    }

    public function test_one_employee_is_named_in_the_subject_and_several_are_counted(): void
    {
        Mail::fake();
        $this->configure();

        $alma = User::factory()->cashier()->create(['name' => 'Alma']);
        $bo = User::factory()->cashier()->create(['name' => 'Bo']);
        $admin = User::factory()->admin()->create();

        $this->send($admin, [$alma]);
        Mail::assertSent(EmployeePerformanceMail::class, fn (EmployeePerformanceMail $mail): bool => str_contains($mail->envelope()->subject, 'Alma')
            && str_contains($mail->envelope()->subject, 'August 2026'));

        $this->send($admin, [$alma, $bo]);
        Mail::assertSent(
            EmployeePerformanceMail::class,
            fn (EmployeePerformanceMail $mail): bool => str_contains($mail->envelope()->subject, '2 employees')
        );
    }

    public function test_the_posted_list_is_what_gets_sent_not_the_stored_defaults(): void
    {
        Mail::fake();
        $settings = $this->configure(['owner@shop.test', 'admin@shop.test']);

        $employee = User::factory()->cashier()->create();

        // What the dialog produced: one default unticked, one address added for
        // this send only.
        $this->send(User::factory()->admin()->create(), [$employee], [
            'recipients' => ['owner@shop.test', 'accountant@shop.test'],
        ])->assertSessionHas('toast.type', 'success');

        Mail::assertSent(EmployeePerformanceMail::class, fn (EmployeePerformanceMail $mail): bool => $mail->hasTo('owner@shop.test')
            && $mail->hasTo('accountant@shop.test')
            && ! $mail->hasTo('admin@shop.test'));

        // And nothing wrote back to the saved list.
        $this->assertSame(['owner@shop.test', 'admin@shop.test'], $settings->recipients());
    }

    public function test_it_records_who_it_went_to_and_what_it_covered(): void
    {
        Mail::fake();
        $this->configure();

        $employee = User::factory()->cashier()->create(['username' => 'alma']);

        $this->send(User::factory()->admin()->create(), [$employee]);

        $entry = AuditLog::where('action', 'employee_report.emailed')->latest('created_at')->first();

        $this->assertNotNull($entry);
        $this->assertSame(['owner@shop.test'], $entry->new_values['recipients']);
        $this->assertSame(['alma'], $entry->new_values['employees']);
        $this->assertSame('2026-08-01', $entry->new_values['month']);
        // No scope is recorded because there is none to record: the report
        // always covers the whole business.
        $this->assertArrayNotHasKey('scope', $entry->new_values);
    }

    public function test_it_refuses_a_send_with_no_employees_or_no_recipients(): void
    {
        Mail::fake();
        $this->configure();

        $employee = User::factory()->cashier()->create();
        $admin = User::factory()->admin()->create();

        $this->send($admin, [])->assertSessionHasErrors('users');
        $this->send($admin, [$employee], ['recipients' => []])->assertSessionHasErrors('recipients');
        $this->send($admin, [$employee], ['month' => 13])->assertSessionHasErrors('month');

        Mail::assertNothingSent();
    }

    public function test_it_refuses_more_employees_than_one_send_may_carry(): void
    {
        Mail::fake();
        $this->configure();

        $employees = User::factory()->cashier()->count(21)->create()->all();

        $this->send(User::factory()->admin()->create(), $employees)->assertSessionHasErrors('users');

        Mail::assertNothingSent();
    }

    public function test_an_employee_that_does_not_exist_sends_nothing(): void
    {
        Mail::fake();
        $this->configure();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('employee-performance.email'), [
                'recipients' => ['owner@shop.test'],
                'users' => ['00000000-0000-0000-0000-0000000000ff'],
                'month' => 8,
                'year' => 2026,
            ])
            ->assertSessionHas('toast.type', 'error');

        Mail::assertNothingSent();
    }

    public function test_an_unconfigured_sender_is_a_toast_not_a_crash(): void
    {
        Mail::fake();

        // No SMTP host and no from address: nothing can be sent, and the person
        // pressing Send must be told rather than shown a 500.
        $employee = User::factory()->cashier()->create();

        $this->send(User::factory()->admin()->create(), [$employee])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');

        Mail::assertNothingSent();
    }

    public function test_a_failing_transport_is_a_toast_and_an_audit_row_never_a_500(): void
    {
        $this->configure();

        $this->app->bind(ReportMailer::class, fn (): ReportMailer => new class(app(ReportEmailSettings::class)) extends ReportMailer
        {
            public function send(array $recipients, Mailable $mail): void
            {
                throw new ReportEmailException('The mail server refused the sign-in.');
            }
        });

        $employee = User::factory()->cashier()->create();

        $this->send(User::factory()->admin()->create(), [$employee])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error')
            ->assertSessionHas('toast.message', 'The mail server refused the sign-in.');

        $entry = AuditLog::where('action', 'employee_report.email_failed')->first();

        $this->assertNotNull($entry);
        $this->assertSame(1, $entry->new_values['recipients']);
        $this->assertSame(1, $entry->new_values['employees']);
    }

    public function test_a_cashier_cannot_send_one(): void
    {
        Mail::fake();
        $this->configure();

        $employee = User::factory()->cashier()->create();

        $this->send(User::factory()->cashier()->create(), [$employee])->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_the_page_carries_what_the_dialog_needs(): void
    {
        $this->configure(['owner@shop.test']);
        User::factory()->cashier()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('employee-performance'))
            ->assertInertia(fn ($page) => $page
                ->where('email.configured', true)
                ->where('email.recipients', ['owner@shop.test'])
                // Owner-only: an Admin is told to ask rather than shown a link
                // to a page they cannot open.
                ->where('email.canConfigure', false)
                ->has('years', 6));
    }
}
