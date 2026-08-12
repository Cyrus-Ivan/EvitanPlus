<?php

namespace Tests\Feature\Reports;

use App\Mail\SalesReportMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Email\ReportEmailException;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Emailing the report (the replacement for the PDF download, which is gone).
 * The document itself is asserted in {@see SalesReportTest}; this file is about
 * who it goes to, what is recorded, and what happens when it cannot be sent.
 */
class ReportEmailTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_posted_list_is_what_gets_sent_not_the_stored_defaults(): void
    {
        Mail::fake();
        $this->configure(['owner@shop.test', 'admin@shop.test']);

        // What the dialog produced: one default unticked, one address added for
        // this send only.
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('reports.email'), [
                'recipients' => ['owner@shop.test', 'accountant@shop.test'],
                'period' => 'daily',
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        Mail::assertSent(SalesReportMail::class, fn (SalesReportMail $mail): bool => $mail->hasTo('owner@shop.test')
            && $mail->hasTo('accountant@shop.test')
            && ! $mail->hasTo('admin@shop.test'));
    }

    public function test_a_send_never_writes_back_to_the_stored_defaults(): void
    {
        Mail::fake();
        $settings = $this->configure(['owner@shop.test', 'admin@shop.test']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('reports.email'), ['recipients' => ['accountant@shop.test']]);

        $this->assertSame(['owner@shop.test', 'admin@shop.test'], $settings->recipients());
    }

    public function test_it_records_who_it_went_to_and_what_it_covered(): void
    {
        Mail::fake();
        $this->configure();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('reports.email'), [
                'recipients' => ['owner@shop.test'],
                'period' => 'monthly',
                'date' => '2026-03-10',
            ]);

        $entry = AuditLog::where('action', 'report.emailed')->latest('created_at')->first();

        $this->assertNotNull($entry);
        $this->assertSame(['owner@shop.test'], $entry->new_values['recipients']);
        $this->assertSame('monthly', $entry->new_values['period']);
        $this->assertSame('2026-03-01', $entry->new_values['date']);
        $this->assertSame('local', $entry->new_values['scope']);
    }

    public function test_at_least_one_recipient_is_required(): void
    {
        Mail::fake();
        $this->configure();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('reports.email'), ['recipients' => []])
            ->assertSessionHasErrors('recipients');

        Mail::assertNothingSent();
    }

    public function test_a_malformed_or_duplicated_address_is_refused(): void
    {
        Mail::fake();
        $this->configure();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('reports.email'), ['recipients' => ['not-an-address']])
            ->assertSessionHasErrors('recipients.0');

        $this->actingAs($owner)
            ->post(route('reports.email'), ['recipients' => ['owner@shop.test', 'Owner@Shop.test']])
            ->assertSessionHasErrors('recipients.0');

        Mail::assertNothingSent();
    }

    public function test_an_unconfigured_sender_comes_back_as_a_toast_not_a_send(): void
    {
        Mail::fake();
        // Deliberately not configured: a hand-built request must be refused by
        // the mailer even though the dialog would have explained first.
        app(ReportEmailSettings::class)->setRecipients(['owner@shop.test']);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('reports.email'), ['recipients' => ['owner@shop.test']])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');

        Mail::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.email_failed']);
    }

    public function test_a_failing_transport_is_a_toast_and_an_audit_row_never_a_500(): void
    {
        Mail::fake();
        $this->configure();

        $this->app->instance(ReportMailer::class, new class(app(ReportEmailSettings::class)) extends ReportMailer
        {
            public function send(array $recipients, Mailable $mail): void
            {
                throw new ReportEmailException('The mail server could not be reached.');
            }
        });

        $response = $this->actingAs(User::factory()->owner()->create())
            ->post(route('reports.email'), ['recipients' => ['owner@shop.test']]);

        $response->assertRedirect();
        $response->assertSessionHas('toast.type', 'error');
        $response->assertSessionHas('toast.message', 'The mail server could not be reached.');

        $entry = AuditLog::where('action', 'report.email_failed')->latest('created_at')->first();
        $this->assertNotNull($entry);
        $this->assertSame(1, $entry->new_values['recipients']);
        $this->assertSame('The mail server could not be reached.', $entry->new_values['reason']);

        $this->assertSame(0, AuditLog::where('action', 'report.emailed')->count());
    }

    public function test_the_reports_page_says_whether_email_is_set_up(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('reports'))
            ->assertInertia(fn ($page) => $page
                ->where('email.configured', false)
                ->where('email.recipients', [])
                ->where('email.canConfigure', true)
            );

        $this->configure(['owner@shop.test']);

        $this->actingAs($owner)
            ->get(route('reports'))
            ->assertInertia(fn ($page) => $page
                ->where('email.configured', true)
                ->where('email.recipients', ['owner@shop.test'])
            );

        // An Admin sees the same list but cannot go and change it.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports'))
            ->assertInertia(fn ($page) => $page->where('email.canConfigure', false));
    }
}
