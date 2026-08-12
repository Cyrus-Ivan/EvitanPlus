<?php

namespace App\Mail;

use App\Models\Instance;
use App\Support\Email\ReportEmailSettings;
use App\Support\Settings\BusinessSettings;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Proof that the configured sender actually works, sent from the Business
 * Settings page. The analogue of the printer's test slip: credentials are only
 * ever proven by trying them.
 *
 * No attachment and no figures — it is not a report, and a recipient who gets
 * one should not have to wonder whether it was.
 */
class TestReportEmailMail extends Mailable
{
    public function __construct(
        private readonly ReportEmailSettings $settings,
        private readonly BusinessSettings $business,
    ) {}

    public function envelope(): Envelope
    {
        $business = (string) ($this->business->all()['business_name'] ?? '');

        return new Envelope(
            from: new Address((string) $this->settings->fromAddress(), $this->settings->fromName()),
            subject: trim($business.' — report email test', ' —'),
        );
    }

    public function content(): Content
    {
        $instance = Instance::current();

        return new Content(
            view: 'emails.test-report-email',
            text: 'emails.test-report-email-text',
            with: [
                'business' => $this->business->all(),
                'branchName' => $instance === null ? null : (string) $instance->branch_name,
                'branchCode' => $instance === null ? null : (string) $instance->branch_code,
                'sentAt' => Carbon::now(),
            ],
        );
    }
}
