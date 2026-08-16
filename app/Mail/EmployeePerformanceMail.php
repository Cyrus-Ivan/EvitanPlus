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
 * One month of employee performance, with one PDF attached PER EMPLOYEE.
 *
 * Several employees ride on one message because the question is asked of a team
 * at the end of a month, but each gets their own document: a performance report
 * is about a person, and it is forwarded to that person. The body carries a line
 * per employee so a recipient can read the shape of the month without opening
 * anything.
 *
 * Deliberately NOT ShouldQueue: this app runs no queue worker in the packaged
 * build (`config/nativephp.php` → `queue_workers` is empty), so a queued mail
 * would sit in the jobs table forever and the only symptom would be a report
 * that never arrived. See {@see ReportMailer}.
 */
class EmployeePerformanceMail extends Mailable
{
    /**
     * @param  list<array{name: string, filename: string, pdf: string, summary: array<string, mixed>}>  $documents
     *                                                                                                              One per employee, in the order they are listed in the body.
     * @param  string|null  $sentBy  The person who pressed Send.
     */
    public function __construct(
        private readonly ReportEmailSettings $settings,
        private readonly BusinessSettings $business,
        private readonly string $periodLabel,
        private readonly array $documents,
        private readonly ?string $sentBy = null,
    ) {}

    public function envelope(): Envelope
    {
        $business = (string) ($this->business->all()['business_name'] ?? '');

        // One employee is named; several are counted. A subject line listing
        // twenty names is a subject line nobody reads.
        $who = count($this->documents) === 1
            ? $this->documents[0]['name']
            : count($this->documents).' employees';

        return new Envelope(
            from: new Address((string) $this->settings->fromAddress(), $this->settings->fromName()),
            subject: trim($business.' — employee performance, '.$who.', '.$this->periodLabel, ' —'),
        );
    }

    public function content(): Content
    {
        $shared = [
            'business' => $this->business->all(),
            'periodLabel' => $this->periodLabel,
            // The bytes are not passed to the view; only what it prints.
            'employees' => array_map(fn (array $document): array => [
                'name' => $document['name'],
                'summary' => $document['summary'],
            ], $this->documents),
            'sentBy' => $this->sentBy,
            'generatedAt' => Carbon::now(),
        ];

        return new Content(
            view: 'emails.employee-performance',
            // A plaintext alternative is worth one more file: an HTML-only
            // message carrying attachments, from a sender no recipient has
            // corresponded with before, is a spam-filter magnet.
            text: 'emails.employee-performance-text',
            with: $shared,
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $document): Attachment => Attachment::fromData(
                fn (): string => $document['pdf'],
                $document['filename'],
            )->withMime('application/pdf'),
            $this->documents,
        );
    }
}
