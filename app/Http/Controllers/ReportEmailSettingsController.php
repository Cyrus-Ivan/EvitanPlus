<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportEmailSettingsUpdateRequest;
use App\Mail\TestReportEmailMail;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SettingsDiff;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Settings\BusinessSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Report-email configuration: the default recipients, how often the report goes
 * out on its own, and the SMTP account it is sent from. Owner-only via the
 * route's role:owner middleware; its own section of the business-settings area,
 * at `/business-settings/report-email`.
 *
 * Separate from {@see BusinessSettingsController} for exactly the reason
 * {@see PrinterSettingsController} is separate: business identity is SHARED and
 * changing one of those six keys is an online write, while everything here is
 * DEVICE-LOCAL — no upstream row, never mirrored, and writable offline like any
 * other piece of this device's own configuration. See
 * {@see ReportEmailSettings} and `docs/glossary.md` §10.
 */
class ReportEmailSettingsController extends Controller
{
    public function __construct(
        private readonly ReportEmailSettings $settings,
        private readonly BusinessSettings $business,
    ) {}

    /**
     * Show the report-email section.
     *
     * forDisplay(), never all(): the sealed SMTP password must not cross to the
     * frontend, only whether one is stored. No `online` prop for the reason
     * given on {@see PrinterSettingsController::edit()} — these keys are
     * device-local too.
     */
    public function edit(): Response
    {
        return Inertia::render('business-settings/report-email', [
            'reportEmailSettings' => $this->settings->forDisplay(),
        ]);
    }

    public function update(ReportEmailSettingsUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        // forAudit(), never all(): audit_logs is pushed upward, so anything in
        // the diff reaches the business schema where every branch role can read
        // it. The password is a sentinel in that shape.
        $before = $this->settings->forAudit();

        $this->settings->update($request->settingsPayload());
        $this->settings->setRecipients($request->recipients());

        $diff = SettingsDiff::changed($before, $this->settings->forAudit());

        if ($diff !== null) {
            $audit->record(
                action: 'settings.updated',
                description: 'Updated report email settings: '.implode(', ', array_keys($diff['new'])).'.',
                old: $diff['old'],
                new: $diff['new'],
            );
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Report email settings saved.',
        ]);
    }

    /**
     * Send a short test message to the stored default recipients, so the owner
     * can confirm the account works before a report depends on it. The printer's
     * test slip, for the other output device.
     */
    public function test(ReportMailer $mailer, AuditRecorder $audit): RedirectResponse
    {
        $recipients = $this->settings->recipients();

        if (! $this->settings->isConfigured()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'No sending address is configured. Set one up above and save first.',
            ]);
        }

        if ($recipients === []) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'There are no recipients yet. Add one above and save first.',
            ]);
        }

        try {
            $mailer->send($recipients, new TestReportEmailMail($this->settings, $this->business));
        } catch (ReportEmailException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $audit->record(
            action: 'report.email_tested',
            description: 'Sent a report email test to '.count($recipients).' recipient(s).',
            new: ['recipients' => $recipients],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Test email sent to '.count($recipients).' recipient(s).',
        ]);
    }
}
