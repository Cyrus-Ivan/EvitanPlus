<?php

namespace Tests\Feature\Reports;

use App\Mail\SalesReportMail;
use App\Models\AuditLog;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Reports\SalesReportPdf;
use App\Support\Settings\BusinessSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Unit\Email\ReportScheduleTest;

/**
 * The scheduled send: whether a schedule's moment has passed, which window that
 * covers, and whether it has already gone out. The arithmetic behind the first
 * two is {@see ReportScheduleTest}'s business and the document
 * itself is {@see SalesReportTest}'s.
 */
class ScheduledReportEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rendering a real document per send is dompdf's memory paid for
        // something this class never looks at — and enough sends in one class
        // to exhaust the suite's limit. filename() and scopeLabel() stay real,
        // because the attachment name IS what most of these tests assert on.
        $this->app->bind(SalesReportPdf::class, fn (): SalesReportPdf => new class(app(BusinessSettings::class)) extends SalesReportPdf
        {
            public function render(array $report, string $period, Carbon $from, bool $canViewCost): string
            {
                return '%PDF-1.4 stub';
            }
        });
    }

    private function settings(): ReportEmailSettings
    {
        return app(ReportEmailSettings::class);
    }

    /**
     * @param  array<string, string>  $overrides  raw settings keys, e.g. a time
     */
    private function configure(string $kind = 'daily', bool $includeCost = false, array $overrides = []): ReportEmailSettings
    {
        $settings = $this->settings();
        $settings->update([
            'report_email_'.$kind.'_enabled' => '1',
            'report_email_include_cost' => $includeCost ? '1' : '0',
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_from_address' => 'reports@shop.test',
            ...$overrides,
        ]);
        $settings->setRecipients(['owner@shop.test']);

        return $settings;
    }

    private function runCommand(int $expected = 0): void
    {
        $this->artisan('reports:send-scheduled')->assertExitCode($expected);
    }

    // ---------------------------------------------------- nothing to do

    public function test_it_sends_nothing_when_nothing_is_scheduled(): void
    {
        Mail::fake();
        $this->settings()->update([
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_from_address' => 'reports@shop.test',
        ]);
        $this->settings()->setRecipients(['owner@shop.test']);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_it_sends_nothing_and_still_succeeds_when_the_sender_is_unconfigured(): void
    {
        Mail::fake();
        $settings = $this->settings();
        $settings->update(['report_email_daily_enabled' => '1']);
        $settings->setRecipients(['owner@shop.test']);

        // SUCCESS, not FAILURE: an unconfigured device must not turn
        // `schedule:run` into a job that fails every fifteen minutes.
        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_it_sends_nothing_when_there_are_no_recipients(): void
    {
        Mail::fake();
        $this->configure()->setRecipients([]);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_a_daily_report_with_no_weekday_ticked_never_fires(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 23:00'));
        // Validation forbids saving this, but a hand-edited row must not throw.
        $this->configure(overrides: ['report_email_daily_days' => '']);

        $this->runCommand();

        Mail::assertNothingSent();
    }

    // ---------------------------------------------------- the time of day

    public function test_nothing_goes_out_before_the_chosen_time(): void
    {
        Mail::fake();
        // 07:59 against an 08:00 send: yesterday's report is not due yet, and
        // the day before that went out yesterday.
        $this->travelTo(Carbon::parse('2026-03-10 08:30'));
        $this->configure();
        $this->runCommand();
        Mail::assertSentCount(1);

        $this->travelTo(Carbon::parse('2026-03-11 07:59'));
        $this->runCommand();

        Mail::assertSentCount(1);
    }

    public function test_it_goes_out_once_the_chosen_time_has_passed(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 08:00'));
        $this->configure();

        $this->runCommand();

        Mail::assertSent(SalesReportMail::class, function (SalesReportMail $mail): bool {
            return $mail->hasTo('owner@shop.test')
                // Yesterday, not today: a report on a period still in progress
                // would be a partial figure presented as a final one.
                && str_contains($mail->attachments()[0]->as, 'sales-report-daily-2026-03-10-');
        });

        $this->assertSame('2026-03-10', $this->settings()->lastSent('daily'));

        $entry = AuditLog::where('action', 'report.emailed')->latest('created_at')->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->user_id);
        $this->assertTrue($entry->new_values['scheduled']);
    }

    public function test_a_device_closed_over_the_chosen_time_sends_late_rather_than_skipping(): void
    {
        Mail::fake();
        // Shut all morning; opened at a quarter to midnight. The instant was
        // missed, but the question the command asks is answerable at any moment.
        $this->travelTo(Carbon::parse('2026-03-11 23:45'));
        $this->configure(overrides: ['report_email_daily_time' => '06:00']);

        $this->runCommand();

        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => str_contains($mail->attachments()[0]->as, 'sales-report-daily-2026-03-10-'));
    }

    public function test_a_second_run_the_same_day_sends_nothing_and_the_next_day_sends_again(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 08:00'));
        $this->configure();

        $this->runCommand();
        Mail::assertSentCount(1);

        $this->travelTo(Carbon::parse('2026-03-11 23:45'));
        $this->runCommand();
        Mail::assertSentCount(1);

        $this->travelTo(Carbon::parse('2026-03-12 08:00'));
        $this->runCommand();
        Mail::assertSentCount(2);
    }

    public function test_a_device_that_was_off_for_a_week_sends_one_report_not_seven(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 08:00'));
        $this->configure();
        $this->runCommand();

        // Closed for a week, then opened. A marker holds a position, not a
        // backlog — the missed days are still on the device and can be emailed
        // by hand from /reports.
        $this->travelTo(Carbon::parse('2026-03-18 08:00'));
        $this->runCommand();

        Mail::assertSentCount(2);
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => str_contains($mail->attachments()[0]->as, 'sales-report-daily-2026-03-17-'));
    }

    // ------------------------------------------------------- the weekdays

    public function test_a_weekday_subset_only_fires_on_the_days_it_names(): void
    {
        Mail::fake();
        // Monday and Thursday only. 2026-03-12 is a Thursday.
        $this->travelTo(Carbon::parse('2026-03-12 08:00'));
        $this->configure(overrides: ['report_email_daily_days' => '1,4']);

        $this->runCommand();
        Mail::assertSentCount(1);
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => str_contains($mail->attachments()[0]->as, 'sales-report-daily-2026-03-11-'));

        // Friday and Saturday: nothing is ticked, so the Thursday send stands.
        $this->travelTo(Carbon::parse('2026-03-13 08:00'));
        $this->runCommand();
        $this->travelTo(Carbon::parse('2026-03-14 08:00'));
        $this->runCommand();
        Mail::assertSentCount(1);

        // The following Monday covers Sunday, the day before it.
        $this->travelTo(Carbon::parse('2026-03-16 08:00'));
        $this->runCommand();
        Mail::assertSentCount(2);
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => str_contains($mail->attachments()[0]->as, 'sales-report-daily-2026-03-15-'));
    }

    // -------------------------------------------- the other three kinds

    public function test_the_weekly_report_fires_on_a_monday_for_the_previous_week(): void
    {
        Mail::fake();
        // 2026-03-16 is a Monday; the week that just finished began 2026-03-09.
        $this->travelTo(Carbon::parse('2026-03-15 09:00'));
        $this->configure(kind: 'weekly');
        $this->runCommand();

        // A Sunday: the most recent Monday was the 9th, whose window began the
        // 2nd. That is a real send — the schedule was simply turned on late.
        Mail::assertSentCount(1);
        $this->assertSame('2026-03-02', $this->settings()->lastSent('weekly'));

        $this->travelTo(Carbon::parse('2026-03-16 09:00'));
        $this->runCommand();

        Mail::assertSentCount(2);
        $this->assertSame('2026-03-09', $this->settings()->lastSent('weekly'));
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => str_contains($mail->attachments()[0]->as, 'sales-report-weekly-2026-03-09-'));
    }

    public function test_the_monthly_report_fires_on_the_first_monday_for_the_previous_month(): void
    {
        Mail::fake();
        // The first Monday of April 2026 is the 6th.
        $this->travelTo(Carbon::parse('2026-04-06 08:00'));
        $this->configure(kind: 'monthly');

        $this->runCommand();

        $this->assertSame('2026-03-01', $this->settings()->lastSent('monthly'));
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => str_contains($mail->attachments()[0]->as, 'sales-report-monthly-2026-03-01-'));

        // Later the same month: already sent.
        $this->travelTo(Carbon::parse('2026-04-20 08:00'));
        $this->runCommand();
        Mail::assertSentCount(1);
    }

    public function test_the_yearly_report_fires_on_the_first_monday_for_the_previous_year(): void
    {
        Mail::fake();
        // The first Monday of 2026 is 2026-01-05.
        $this->travelTo(Carbon::parse('2026-01-05 08:00'));
        $this->configure(kind: 'yearly');

        $this->runCommand();

        $this->assertSame('2025-01-01', $this->settings()->lastSent('yearly'));
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => str_contains($mail->attachments()[0]->as, 'sales-report-yearly-2025-01-01-'));
    }

    public function test_two_schedules_due_in_the_same_run_both_send_and_keep_their_own_markers(): void
    {
        Mail::fake();
        // A Monday, so the daily and the weekly both fall due at 08:00.
        $this->travelTo(Carbon::parse('2026-03-16 08:00'));
        $this->configure(kind: 'daily');
        $this->configure(kind: 'weekly');

        $this->runCommand();

        Mail::assertSentCount(2);
        $this->assertSame('2026-03-15', $this->settings()->lastSent('daily'));
        $this->assertSame('2026-03-09', $this->settings()->lastSent('weekly'));

        // And neither re-sends on the next tick.
        $this->travelTo(Carbon::parse('2026-03-16 08:15'));
        $this->runCommand();
        Mail::assertSentCount(2);
    }

    // -------------------------------------------------------- the failure

    public function test_a_failure_leaves_the_period_unsent_and_backs_off(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 08:00'));
        $this->configure();
        $this->failTheMailer();

        $this->runCommand(expected: 1);

        // The marker is untouched, so the same window is retried.
        $this->assertSame('', $this->settings()->lastSent('daily'));
        $this->assertNotNull($this->settings()->lastAttemptAt());

        // Inside the backoff: nothing is even attempted.
        $this->travelTo(Carbon::parse('2026-03-11 08:10'));
        $this->runCommand();

        // Past it: attempted again, and this time it works.
        $this->travelTo(Carbon::parse('2026-03-11 08:30'));
        $this->app->forgetInstance(ReportMailer::class);
        $this->runCommand();

        Mail::assertSentCount(1);
        $this->assertSame('2026-03-10', $this->settings()->lastSent('daily'));
        $this->assertNull($this->settings()->lastAttemptAt());
    }

    public function test_a_failure_on_one_schedule_leaves_the_rest_for_the_retry(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-16 08:00'));
        $this->configure(kind: 'daily');
        $this->configure(kind: 'weekly');
        $this->failTheMailer();

        $this->runCommand(expected: 1);

        // The transport is shared, so a walk that fails once stops. Neither
        // marker is written and both are picked up after the backoff.
        $this->assertSame('', $this->settings()->lastSent('daily'));
        $this->assertSame('', $this->settings()->lastSent('weekly'));

        $this->travelTo(Carbon::parse('2026-03-16 08:30'));
        $this->app->forgetInstance(ReportMailer::class);
        $this->runCommand();

        Mail::assertSentCount(2);
    }

    // ----------------------------------------------------------- the cost

    public function test_cost_is_left_out_unless_the_owner_turned_it_on(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 08:00'));
        $this->configure();

        $this->runCommand();
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => ! array_key_exists('profit', $this->totalsOf($mail)));

        $this->travelTo(Carbon::parse('2026-03-12 08:00'));
        $this->configure(includeCost: true);

        $this->runCommand();
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => array_key_exists('profit', $this->totalsOf($mail)));
    }

    /**
     * @return array<string, mixed>
     */
    private function totalsOf(SalesReportMail $mail): array
    {
        return $mail->content()->with['totals'];
    }

    private function failTheMailer(): void
    {
        $this->app->instance(ReportMailer::class, new class(app(ReportEmailSettings::class)) extends ReportMailer
        {
            public function send(array $recipients, Mailable $mail): void
            {
                throw new ReportEmailException('The mail server could not be reached.');
            }
        });
    }
}
