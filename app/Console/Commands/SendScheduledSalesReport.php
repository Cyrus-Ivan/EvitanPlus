<?php

namespace App\Console\Commands;

use App\Mail\SalesReportMail;
use App\Support\Audit\AuditRecorder;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Reports\ReportPeriods;
use App\Support\Reports\SalesReportBuilder;
use App\Support\Reports\SalesReportPdf;
use App\Support\Settings\BusinessSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Email this branch's sales report for the last complete period, if one is due
 * and has not gone out yet.
 *
 * ## Why it is marker-driven rather than fired at an instant
 *
 * NativePHP runs `artisan schedule:run` once a minute — but only while the app
 * window is open (see the electron plugin's `startScheduler`). A till that is
 * shut at midnight would simply miss a `dailyAt('00:05')`, silently and
 * forever. So this command asks a question it can answer at ANY moment: "has
 * the period that is now complete already been sent?" — comparing a marker
 * holding the last sent period's kind and start date. `bootstrap/app.php` runs
 * it every fifteen minutes and the answer is almost always no-op cheap.
 *
 * The marker carries the period KIND as well as the date because a weekly
 * window's start can equal a daily window's start; a date-only marker would
 * suppress the first send after the owner switched daily → weekly.
 *
 * ## What it does NOT do: catch up on a backlog
 *
 * The window is always the last complete period relative to now, so a device
 * that was off for three weeks sends ONE report — last week's — not three. A
 * marker holds a position, not a queue. The data is all still on the device and
 * any window can be emailed by hand from /reports.
 *
 * ## Scope and cost
 *
 * Always this branch, from local SQLite: there is no actor to authorize a
 * consolidated read and no screen to fall back on if one failed. Cost, COGS and
 * profit are included only when the Owner has explicitly turned them on
 * ({@see ReportEmailSettings::includeCost()}) — item cost is Owner-only to view
 * and an emailed report has no viewer whose role that could be true of, so it
 * takes a decision by the one person entitled to make it.
 */
class SendScheduledSalesReport extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = "Email this branch's sales report for the last complete period, if one is due";

    /**
     * How long to wait after a failed send before trying again. Without it a
     * dead mail server means one blocking attempt every fifteen minutes all
     * day, each paying the transport timeout.
     */
    private const BACKOFF_MINUTES = 15;

    public function handle(
        ReportEmailSettings $settings,
        SalesReportBuilder $builder,
        SalesReportPdf $pdf,
        ReportMailer $mailer,
        BusinessSettings $business,
        AuditRecorder $audit,
    ): int {
        // Every early return is SUCCESS: an unconfigured device must not turn
        // `schedule:run` into a job that fails once every fifteen minutes.
        $period = $settings->period();

        if ($period === ReportEmailSettings::PERIOD_OFF) {
            return self::SUCCESS;
        }

        if (! $settings->isConfigured()) {
            $this->warn('Report email has a schedule but no sender configured; nothing sent.');

            return self::SUCCESS;
        }

        $recipients = $settings->recipients();

        if ($recipients === []) {
            $this->warn('Report email has a schedule but no recipients; nothing sent.');

            return self::SUCCESS;
        }

        $lastAttempt = $settings->lastAttemptAt();

        if ($lastAttempt !== null && $lastAttempt->diffInMinutes(Carbon::now()) < self::BACKOFF_MINUTES) {
            return self::SUCCESS;
        }

        $anchor = ReportPeriods::lastCompleteAnchor($period, Carbon::now());
        [$from, $to] = ReportPeriods::resolveWindow($period, $anchor);
        $marker = $period.':'.$from->toDateString();

        if ($settings->lastPeriod() === $marker) {
            return self::SUCCESS;
        }

        $canViewCost = $settings->includeCost();
        $report = $builder->local($period, $from, $to, $canViewCost, topItems: true);
        $label = ReportPeriods::label($period, $from);

        try {
            $mailer->send($recipients, new SalesReportMail(
                settings: $settings,
                business: $business,
                scopeLabel: $pdf->scopeLabel($report['scope'], $report['branches']),
                periodLabel: $label,
                periodNoun: ReportPeriods::SUB_NOUNS[$period],
                totals: $report['totals'],
                canViewCost: $canViewCost,
                pdf: $pdf->render($report, $period, $from, $canViewCost),
                filename: $pdf->filename($report, $period, $from),
                // No actor: this is the device sending on a schedule, and the
                // email body says so rather than naming somebody who was not
                // there.
                sentBy: null,
            ));
        } catch (ReportEmailException $e) {
            // The period marker is deliberately NOT written, so the same window
            // is retried after the backoff — or the next time the app opens.
            $settings->markAttempt();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $settings->markPeriodSent($marker);
        $settings->clearAttempt();

        $audit->record(
            action: 'report.emailed',
            description: 'Scheduled '.$period.' sales report for '.$label.' emailed to '.count($recipients).' recipient(s).',
            new: [
                'recipients' => $recipients,
                'period' => $period,
                'date' => $from->toDateString(),
                'scope' => $report['scope'],
                'scheduled' => true,
            ],
            // A system event: AuditRecorder writes a null user_id.
            actor: null,
        );

        $this->info('Sent the '.$period.' sales report for '.$label.' to '.count($recipients).' recipient(s).');

        return self::SUCCESS;
    }
}
