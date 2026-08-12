<?php

namespace Tests\Unit\Email;

use App\Models\Setting;
use App\Support\Email\ReportEmailSettings;
use App\Support\Settings\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ReportEmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): ReportEmailSettings
    {
        return new ReportEmailSettings(new BusinessSettings);
    }

    public function test_it_falls_back_to_the_defaults_before_anything_is_stored(): void
    {
        $settings = $this->settings();

        $this->assertSame('off', $settings->period());
        $this->assertSame([], $settings->recipients());
        $this->assertSame(587, $settings->port());
        $this->assertSame('tls', $settings->encryption());
        $this->assertNull($settings->host());
        $this->assertFalse($settings->isConfigured());
        $this->assertFalse($settings->includeCost());
    }

    public function test_the_recipient_list_round_trips_through_the_newline_encoding(): void
    {
        $settings = $this->settings();

        $settings->setRecipients(['  owner@shop.test ', 'admin@shop.test']);

        $this->assertSame(['owner@shop.test', 'admin@shop.test'], $settings->recipients());
        $this->assertSame(
            "owner@shop.test\nadmin@shop.test",
            Setting::where('key', 'report_email_recipients')->value('value'),
        );
    }

    public function test_it_drops_blanks_and_case_insensitive_duplicates_but_keeps_the_case_typed(): void
    {
        $settings = $this->settings();

        $settings->setRecipients(['Owner@Shop.test', '', '  ', 'owner@shop.TEST', 'admin@shop.test']);

        // The local part of an address is technically case-sensitive, so the
        // survivor keeps the case it was typed in.
        $this->assertSame(['Owner@Shop.test', 'admin@shop.test'], $settings->recipients());
    }

    public function test_an_absent_key_is_left_standing_by_an_update(): void
    {
        $settings = $this->settings();
        $settings->update(['report_email_smtp_host' => 'smtp.shop.test']);

        $settings->update(['report_email_smtp_port' => '465']);

        $this->assertSame('smtp.shop.test', $settings->host());
        $this->assertSame(465, $settings->port());
    }

    public function test_the_password_is_sealed_at_rest_and_opens_again(): void
    {
        $settings = $this->settings();

        $settings->update(['report_email_smtp_password' => ReportEmailSettings::seal('hunter2')]);

        $stored = (string) Setting::where('key', 'report_email_smtp_password')->value('value');

        $this->assertNotSame('hunter2', $stored);
        $this->assertStringNotContainsString('hunter2', $stored);
        $this->assertTrue($settings->hasPassword());
        $this->assertSame('hunter2', $settings->password());
    }

    public function test_an_unopenable_password_degrades_to_null_rather_than_throwing(): void
    {
        // A settings row copied to an install with a different APP_KEY. The
        // owner must still be able to open the page that fixes it.
        $settings = $this->settings();
        $settings->update(['report_email_smtp_password' => 'not-a-ciphertext']);

        $this->assertNull($settings->password());
        $this->assertTrue($settings->hasPassword());
    }

    public function test_is_configured_needs_both_a_host_and_a_sending_address(): void
    {
        $settings = $this->settings();

        $settings->update(['report_email_smtp_host' => 'smtp.shop.test']);
        $this->assertFalse($settings->isConfigured());

        $settings->update(['report_email_from_address' => 'reports@shop.test']);
        $this->assertTrue($settings->isConfigured());
    }

    public function test_the_from_name_falls_back_to_the_business_name(): void
    {
        (new BusinessSettings)->update(['business_name' => 'Evitan Sari-Sari']);
        $settings = $this->settings();

        $this->assertSame('Evitan Sari-Sari', $settings->fromName());

        $settings->update(['report_email_from_name' => 'Daily Reports']);

        $this->assertSame('Daily Reports', $settings->fromName());
    }

    public function test_the_display_shape_never_carries_the_password_and_hands_back_an_array(): void
    {
        $settings = $this->settings();
        $settings->update(['report_email_smtp_password' => ReportEmailSettings::seal('hunter2')]);
        $settings->setRecipients(['owner@shop.test']);

        $display = $settings->forDisplay();

        $this->assertArrayNotHasKey('report_email_smtp_password', $display);
        $this->assertSame('1', $display['password_set']);
        $this->assertSame(['owner@shop.test'], $display['recipients']);
        // The newline encoding is the server's business.
        $this->assertArrayNotHasKey('report_email_recipients', $display);
    }

    public function test_the_audit_shape_replaces_the_password_with_a_sentinel(): void
    {
        // audit_logs is pushed to Postgres, so the ciphertext must not be in
        // anything SettingsDiff is fed.
        $settings = $this->settings();

        $this->assertSame('(unset)', $settings->forAudit()['report_email_smtp_password']);

        $settings->update(['report_email_smtp_password' => ReportEmailSettings::seal('hunter2')]);

        $this->assertSame('(set)', $settings->forAudit()['report_email_smtp_password']);
    }

    public function test_the_markers_are_not_writable_through_update(): void
    {
        $settings = $this->settings();

        $settings->update([
            ReportEmailSettings::LAST_PERIOD_KEY => 'daily:2026-08-11',
            ReportEmailSettings::LAST_ATTEMPT_KEY => '2026-08-11T00:00:00+08:00',
        ]);

        $this->assertSame('', $settings->lastPeriod());
        $this->assertNull($settings->lastAttemptAt());

        $settings->markPeriodSent('daily:2026-08-11');
        $settings->markAttempt();

        $this->assertSame('daily:2026-08-11', $settings->lastPeriod());
        $this->assertNotNull($settings->lastAttemptAt());

        $settings->clearAttempt();

        $this->assertNull($settings->lastAttemptAt());
    }

    public function test_an_unrecognised_period_or_encryption_reads_as_the_default(): void
    {
        $settings = $this->settings();

        Setting::updateOrCreate(['key' => 'report_email_period'], ['value' => 'fortnightly']);
        Setting::updateOrCreate(['key' => 'report_email_smtp_encryption'], ['value' => 'starttls']);

        $this->assertSame('off', $settings->period());
        $this->assertSame('tls', $settings->encryption());
    }

    public function test_crypt_seals_differently_each_time_but_opens_the_same(): void
    {
        $first = ReportEmailSettings::seal('hunter2');
        $second = ReportEmailSettings::seal('hunter2');

        $this->assertNotSame($first, $second);
        $this->assertSame('hunter2', Crypt::decryptString($first));
        $this->assertSame('hunter2', Crypt::decryptString($second));
    }
}
