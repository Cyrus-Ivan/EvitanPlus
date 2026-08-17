<?php

namespace App\Console\Commands;

use App\Mail\SalesReportMail;
use App\Support\Audit\AuditRecorder;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Email\ReportSchedule;
use App\Support\Reports\ReportPeriods;
use App\Support\Reports\SalesReportBuilder;
use App\Support\Reports\SalesReportPdf;
use App\Support\Settings\BusinessSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Email this branch's sales reports for every schedule whose moment has passed
 * and whose period has not gone out yet.
 *
 * ## Why it is marker-driven rather than fired at an instant
 *
 * NativePHP runs `artisan schedule:run` once a minute — but only while the app
 * window is open (see the electron plugin's `startScheduler`). A till that is
 * shut at midnight would simply miss a `dailyAt('00:05')`, silently and
 * forever. So this command asks a question it can answer at ANY moment: "is
 * there a schedule whose most recent firing instant has passed and whose window
 * has not been sent?" — comparing {@see ReportSchedule::lastDueAt()} against a
 * per-kind marker. `bootstrap/app.php` runs it every fifteen minutes and the
 * answer is almost always no-op cheap.
 *
 * The chosen send times could not live in `bootstrap/app.php` even if we wanted
 * them to: that closure runs on every artisan invocation, including `migrate` on
 * a device whose settings table does not exist yet, so it may not read them. It
 * is also why the times are a closed quarter-hour list — the tick is fifteen
 * minutes wide.
 *
 * ## What it does NOT do: catch up on a backlog
 *
 * Each schedule's window is the period that finished before its most recent
 * firing instant, so a device off for three weeks sends ONE weekly report — last
 * week's — not three. A marker holds a position, not a queue. The data is all
 * still on the device and any window can be emailed by hand from /reports.
 *
 * ## Four independent schedules, one transport
 *
 * Daily, weekly, monthly and yearly are switched on separately and can fall due
 * in the same run, so this walks them in order and each writes its own marker on
 * success. The backoff clock is shared and a failure stops the walk: the
 * transport is shared too, so if the mail server is unreachable for one it is
 * unreachable for all, and the rest keep their unwritten markers and are picked
 * up on the retry.
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

    protected $description = "Email this branch's sales report for any schedule that has fallen due";

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
        if (! $settings->isScheduled()) {
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

        // `abs()`, because `diffInMinutes` is SIGNED in Carbon 3. A marker
        // stamped in the future — a device whose clock is corrected backwards, or
        // an RTC that jumped — otherwise yields a negative difference, which is
        // permanently "less than the backoff", and every scheduled report stops
        // going out for good with nothing on screen to say why.
        if ($lastAttempt !== null && abs($lastAttempt->diffInMinutes(Carbon::now())) < self::BACKOFF_MINUTES) {
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $canViewCost = $settings->includeCost();
        $sent = 0;

        foreach ($settings->schedules() as $kind => $schedule) {
            if (! $schedule->enabled) {
                continue;
            }

            $due = $schedule->lastDueAt($now);

            // Never fired: daily with no weekday ticked. Validation forbids
            // saving that, but a hand-edited settings row must not throw.
            if ($due === null) {
                continue;
            }

            [$from, $to] = $schedule->windowFor($due);

            if ($settings->lastSent($kind) === $from->toDateString()) {
                continue;
            }

            $period = $schedule->period();
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
                    // Automated: nobody pressed a button, and the document says
                    // so in its generation details.
                    pdf: $pdf->render($report, $period, $from, $canViewCost, automated: true),
                    filename: $pdf->filename($report, $period, $from),
                    // No actor: this is the device sending on a schedule, and
                    // the email body says so rather than naming somebody who
                    // was not there.
                    sentBy: null,
                ));
            } catch (ReportEmailException $e) {
                // This kind's marker is deliberately NOT written, and neither
                // are the markers of any schedule after it, so the same windows
                // are retried after the backoff — or the next time the app
                // opens.
                $settings->markAttempt();
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $settings->markSent($kind, $from->toDateString());
            $sent++;

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
        }

        // Cleared on every run that got this far, not only one that sent
        // something. Reaching here means the walk completed without a transport
        // failure — the failure path returns early, above — so the backoff has
        // served its purpose whether or not a period happened to be due. Gated on
        // `$sent > 0`, a marker left by one failed send survived every subsequent
        // quiet tick and then delayed the next genuinely due report by up to
        // another fifteen minutes for no reason.
        $settings->clearAttempt();

        return self::SUCCESS;
    }
}
