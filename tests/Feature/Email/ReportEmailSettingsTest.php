<?php

namespace Tests\Feature\Email;

use App\Mail\TestReportEmailMail;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Email\ReportEmailSettings;
use App\Support\Settings\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReportEmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'recipients' => ['owner@shop.test'],
            'report_email_period' => 'off',
            'report_email_include_cost' => false,
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_smtp_port' => 587,
            'report_email_smtp_encryption' => 'tls',
            'report_email_smtp_username' => 'reports@shop.test',
            'report_email_smtp_password' => 'hunter2',
            'report_email_from_address' => 'reports@shop.test',
            'report_email_from_name' => 'Acme Reports',
        ], $overrides);
    }

    private function settings(): ReportEmailSettings
    {
        return app(ReportEmailSettings::class);
    }

    // ------------------------------------------------------------- the page

    public function test_the_page_carries_the_configuration_but_never_the_password(): void
    {
        $this->settings()->update([
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_smtp_password' => ReportEmailSettings::seal('hunter2'),
        ]);
        $this->settings()->setRecipients(['owner@shop.test', 'admin@shop.test']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('report-email-settings'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('business-settings/report-email', false)
                ->where('reportEmailSettings.report_email_smtp_host', 'smtp.shop.test')
                ->where('reportEmailSettings.recipients', ['owner@shop.test', 'admin@shop.test'])
                ->where('reportEmailSettings.password_set', '1')
                ->missing('reportEmailSettings.report_email_smtp_password')
                // The newline encoding is the server's business.
                ->missing('reportEmailSettings.report_email_recipients')
            );
    }

    // ------------------------------------------------------------ the write

    public function test_the_owner_can_save_the_configuration(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('report-email-settings.update'), $this->payload([
                'recipients' => ['owner@shop.test', ' admin@shop.test '],
                'report_email_period' => 'weekly',
                'report_email_include_cost' => true,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = $this->settings();
        $this->assertSame(['owner@shop.test', 'admin@shop.test'], $settings->recipients());
        $this->assertSame('weekly', $settings->period());
        $this->assertTrue($settings->includeCost());
        $this->assertSame('smtp.shop.test', $settings->host());
        $this->assertSame(587, $settings->port());
        $this->assertSame('hunter2', $settings->password());
        $this->assertSame('Acme Reports', $settings->fromName());
    }

    public function test_an_admin_or_cashier_may_not_change_it(): void
    {
        foreach (['admin', 'cashier'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->patch(route('report-email-settings.update'), $this->payload())
                ->assertForbidden();
        }

        $this->assertNull($this->settings()->host());
    }

    // ------------------------------------------------------------- password

    public function test_a_blank_password_keeps_the_stored_one_byte_for_byte(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->patch(route('report-email-settings.update'), $this->payload());

        $sealed = Setting::where('key', 'report_email_smtp_password')->value('value');

        $this->actingAs($owner)
            ->patch(route('report-email-settings.update'), $this->payload([
                'report_email_smtp_password' => '',
                'report_email_from_name' => 'Renamed',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($sealed, Setting::where('key', 'report_email_smtp_password')->value('value'));
        $this->assertSame('hunter2', $this->settings()->password());
        $this->assertSame('Renamed', $this->settings()->fromName());
    }

    public function test_a_new_password_reseals_and_the_clear_flag_removes_it(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->patch(route('report-email-settings.update'), $this->payload());

        $this->actingAs($owner)
            ->patch(route('report-email-settings.update'), $this->payload(['report_email_smtp_password' => 'correct-horse']))
            ->assertSessionHasNoErrors();

        $this->assertSame('correct-horse', $this->settings()->password());

        $this->actingAs($owner)
            ->patch(route('report-email-settings.update'), $this->payload([
                'report_email_smtp_password' => '',
                'report_email_smtp_password_clear' => true,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->settings()->hasPassword());
        $this->assertNull($this->settings()->password());
    }

    public function test_the_password_never_reaches_the_audit_log(): void
    {
        // audit_logs is pushed upward, so anything in old_values/new_values
        // reaches the business schema where every branch role can read it.
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('report-email-settings.update'), $this->payload());

        $entry = AuditLog::where('action', 'settings.updated')->latest('created_at')->first();

        $this->assertNotNull($entry);
        $this->assertSame('(set)', $entry->new_values['report_email_smtp_password']);
        $this->assertSame('(unset)', $entry->old_values['report_email_smtp_password']);
        $this->assertStringNotContainsString('hunter2', json_encode($entry->new_values));
    }

    public function test_an_unchanged_save_writes_no_audit_row(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->patch(route('report-email-settings.update'), $this->payload([
            'report_email_smtp_password' => '',
        ]));

        $before = AuditLog::where('action', 'settings.updated')->count();

        $this->actingAs($owner)->patch(route('report-email-settings.update'), $this->payload([
            'report_email_smtp_password' => '',
        ]));

        $this->assertSame($before, AuditLog::where('action', 'settings.updated')->count());
    }

    // ----------------------------------------------------------- validation

    public function test_the_obvious_field_errors_are_caught(): void
    {
        $owner = User::factory()->owner()->create();

        $cases = [
            'report_email_smtp_port' => ['report_email_smtp_port' => 70000],
            'report_email_smtp_encryption' => ['report_email_smtp_encryption' => 'starttls'],
            'report_email_period' => ['report_email_period' => 'fortnightly'],
            'recipients.0' => ['recipients' => ['not-an-address']],
            'report_email_from_address' => ['report_email_from_address' => 'not-an-address'],
        ];

        foreach ($cases as $field => $override) {
            $this->actingAs($owner)
                ->patch(route('report-email-settings.update'), $this->payload($override))
                ->assertSessionHasErrors($field);
        }
    }

    public function test_a_duplicate_recipient_is_refused_case_insensitively(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('report-email-settings.update'), $this->payload([
                'recipients' => ['owner@shop.test', 'Owner@Shop.test'],
            ]))
            ->assertSessionHasErrors('recipients.0');
    }

    public function test_a_schedule_needs_a_sender_and_a_recipient(): void
    {
        $owner = User::factory()->owner()->create();

        // A schedule that can never fire is a field error at save time, not a
        // nightly log line nobody reads.
        $this->actingAs($owner)
            ->patch(route('report-email-settings.update'), $this->payload([
                'report_email_period' => 'daily',
                'report_email_smtp_host' => '',
                'report_email_from_address' => '',
            ]))
            ->assertSessionHasErrors(['report_email_smtp_host', 'report_email_from_address']);

        $this->actingAs($owner)
            ->patch(route('report-email-settings.update'), $this->payload([
                'report_email_period' => 'daily',
                'recipients' => [],
            ]))
            ->assertSessionHasErrors('recipients');
    }

    public function test_switching_the_schedule_off_lets_a_blank_form_be_saved(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('report-email-settings.update'), $this->payload([
                'recipients' => [],
                'report_email_period' => 'off',
                'report_email_smtp_host' => '',
                'report_email_smtp_username' => '',
                'report_email_smtp_password' => '',
                'report_email_from_address' => '',
                'report_email_from_name' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->settings()->isConfigured());
    }

    // ----------------------------------------------------------- test email

    public function test_the_test_email_goes_to_the_stored_recipients(): void
    {
        Mail::fake();
        (new BusinessSettings)->update(['business_name' => 'Acme Retail']);

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->patch(route('report-email-settings.update'), $this->payload([
            'recipients' => ['owner@shop.test', 'admin@shop.test'],
        ]));

        $this->actingAs($owner)
            ->post(route('report-email-settings.test'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        Mail::assertSent(TestReportEmailMail::class, fn (TestReportEmailMail $mail): bool => $mail->hasTo('owner@shop.test')
            && $mail->hasTo('admin@shop.test')
            && $mail->hasFrom('reports@shop.test'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.email_tested']);
    }

    public function test_the_test_email_refuses_and_says_so_when_nothing_is_configured(): void
    {
        Mail::fake();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('report-email-settings.test'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');

        Mail::assertNothingSent();
    }

    public function test_the_test_email_refuses_when_there_are_no_recipients(): void
    {
        Mail::fake();

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->patch(route('report-email-settings.update'), $this->payload(['recipients' => []]));

        $this->actingAs($owner)
            ->post(route('report-email-settings.test'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');

        Mail::assertNothingSent();
    }
}
