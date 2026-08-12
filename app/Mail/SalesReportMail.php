<?php

namespace App\Mail;

use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use App\Support\Settings\BusinessSettings;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The sales report, with the PDF attached.
 *
 * Deliberately NOT ShouldQueue: this app runs no queue worker in the packaged
 * build (`config/nativephp.php` → `queue_workers` is empty), so a queued mail
 * would sit in the jobs table forever and the only symptom would be a report
 * that never arrived. See {@see ReportMailer}.
 */
class SalesReportMail extends Mailable
{
    /**
     * @param  array<string, string|int>  $totals
     * @param  string  $pdf  The rendered document's bytes.
     * @param  string|null  $sentBy  The person who pressed Send, or null when
     *                               the scheduled send produced this.
     */
    public function __construct(
        private readonly ReportEmailSettings $settings,
        private readonly BusinessSettings $business,
        private readonly string $scopeLabel,
        private readonly string $periodLabel,
        private readonly string $periodNoun,
        private readonly array $totals,
        private readonly bool $canViewCost,
        private readonly string $pdf,
        private readonly string $filename,
        private readonly ?string $sentBy = null,
    ) {}

    public function envelope(): Envelope
    {
        $business = (string) ($this->business->all()['business_name'] ?? '');

        return new Envelope(
            from: new Address((string) $this->settings->fromAddress(), $this->settings->fromName()),
            subject: trim($business.' — '.$this->scopeLabel.' sales report, '.$this->periodLabel, ' —'),
        );
    }

    public function content(): Content
    {
        $shared = [
            'business' => $this->business->all(),
            'scopeLabel' => $this->scopeLabel,
            'periodLabel' => $this->periodLabel,
            'periodNoun' => $this->periodNoun,
            'totals' => $this->totals,
            'canViewCost' => $this->canViewCost,
            'sentBy' => $this->sentBy,
            'generatedAt' => Carbon::now(),
        ];

        return new Content(
            view: 'emails.sales-report',
            // A plaintext alternative is worth one more file: an HTML-only
            // message carrying an attachment, from a sender no recipient has
            // corresponded with before, is a spam-filter magnet.
            text: 'emails.sales-report-text',
            with: $shared,
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
