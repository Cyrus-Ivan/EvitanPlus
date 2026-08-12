<?php

namespace Tests\Feature\Reports;

use App\Mail\SalesReportMail;
use App\Models\AuditLog;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The scheduled send: whether a period is due, and whether it has already gone
 * out. The document itself is {@see SalesReportTest}'s business.
 */
class ScheduledReportEmailTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): ReportEmailSettings
    {
        return app(ReportEmailSettings::class);
    }

    private function configure(string $period = 'daily', bool $includeCost = false): ReportEmailSettings
    {
        $settings = $this->settings();
        $settings->update([
            'report_email_period' => $period,
            'report_email_include_cost' => $includeCost ? '1' : '0',
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_from_address' => 'reports@shop.test',
        ]);
        $settings->setRecipients(['owner@shop.test']);

        return $settings;
    }

    private function runCommand(int $expected = 0): void
    {
        $this->artisan('reports:send-scheduled')->assertExitCode($expected);
    }

    // ---------------------------------------------------- nothing to do

    public function test_it_sends_nothing_when_the_schedule_is_off(): void
    {
        Mail::fake();
        $this->configure(period: 'off');

        $this->runCommand();

        Mail::assertNothingSent();
    }

    public function test_it_sends_nothing_and_still_succeeds_when_the_sender_is_unconfigured(): void
    {
        Mail::fake();
        $settings = $this->settings();
        $settings->update(['report_email_period' => 'daily']);
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

    // --------------------------------------------------------- the window

    public function test_it_sends_the_last_complete_day_and_records_the_marker(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 06:30'));
        $this->configure();

        $this->runCommand();

        Mail::assertSent(SalesReportMail::class, function (SalesReportMail $mail): bool {
            return $mail->hasTo('owner@shop.test')
                // Yesterday, not today: a report on a period still in progress
                // would be a partial figure presented as a final one.
                && str_contains($mail->attachments()[0]->as, 'sales-report-daily-2026-03-10-');
        });

        $this->assertSame('daily:2026-03-10', $this->settings()->lastPeriod());

        $entry = AuditLog::where('action', 'report.emailed')->latest('created_at')->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->user_id);
        $this->assertTrue($entry->new_values['scheduled']);
    }

    public function test_a_second_run_the_same_day_sends_nothing_and_the_next_day_sends_again(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 06:30'));
        $this->configure();

        $this->runCommand();
        Mail::assertSentCount(1);

        $this->travelTo(Carbon::parse('2026-03-11 23:45'));
        $this->runCommand();
        Mail::assertSentCount(1);

        $this->travelTo(Carbon::parse('2026-03-12 06:30'));
        $this->runCommand();
        Mail::assertSentCount(2);
    }

    public function test_a_device_that_was_off_for_a_week_sends_one_report_not_seven(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 06:30'));
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

    public function test_switching_daily_to_weekly_on_a_shared_start_date_still_sends(): void
    {
        Mail::fake();
        // 2026-03-16 is a Monday, so the weekly window that completed on
        // 2026-03-23 starts on 2026-03-16 — the same date as the daily window
        // sent on the 17th. Only the period kind in the marker tells them apart.
        $this->travelTo(Carbon::parse('2026-03-17 06:30'));
        $this->configure();
        $this->runCommand();
        $this->assertSame('daily:2026-03-16', $this->settings()->lastPeriod());

        $this->travelTo(Carbon::parse('2026-03-23 06:30'));
        $this->settings()->update(['report_email_period' => 'weekly']);
        $this->runCommand();

        $this->assertSame('weekly:2026-03-16', $this->settings()->lastPeriod());
        Mail::assertSentCount(2);
    }

    // -------------------------------------------------------- the failure

    public function test_a_failure_leaves_the_period_unsent_and_backs_off(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 06:30'));
        $this->configure();
        $this->failTheMailer();

        $this->runCommand(expected: 1);

        // The marker is untouched, so the same window is retried.
        $this->assertSame('', $this->settings()->lastPeriod());
        $this->assertNotNull($this->settings()->lastAttemptAt());

        // Inside the backoff: nothing is even attempted.
        $this->travelTo(Carbon::parse('2026-03-11 06:40'));
        $this->runCommand();

        // Past it: attempted again, and this time it works.
        $this->travelTo(Carbon::parse('2026-03-11 07:00'));
        $this->app->forgetInstance(ReportMailer::class);
        $this->runCommand();

        Mail::assertSentCount(1);
        $this->assertSame('daily:2026-03-10', $this->settings()->lastPeriod());
        $this->assertNull($this->settings()->lastAttemptAt());
    }

    // ----------------------------------------------------------- the cost

    public function test_cost_is_left_out_unless_the_owner_turned_it_on(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-03-11 06:30'));
        $this->configure();

        $this->runCommand();
        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => ! array_key_exists('profit', $this->totalsOf($mail)));

        $this->travelTo(Carbon::parse('2026-03-12 06:30'));
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
