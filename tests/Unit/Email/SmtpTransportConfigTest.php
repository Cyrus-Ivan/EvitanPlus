<?php

namespace Tests\Unit\Email;

use App\Support\Email\ReportEmailSettings;
use App\Support\Email\SmtpTransportConfig;
use App\Support\Settings\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mail::fake() replaces the mail manager outright and never reaches a
 * transport, so no feature test in this suite exercises this mapping. It is
 * asserted directly instead.
 */
class SmtpTransportConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $values
     */
    private function configFor(array $values): array
    {
        $settings = new ReportEmailSettings(new BusinessSettings);
        $settings->update($values);

        return SmtpTransportConfig::from($settings);
    }

    public function test_ssl_asks_for_implicit_tls_and_the_others_do_not(): void
    {
        $this->assertSame('smtps', $this->configFor(['report_email_smtp_encryption' => 'ssl'])['scheme']);
        $this->assertSame('smtp', $this->configFor(['report_email_smtp_encryption' => 'tls'])['scheme']);
        $this->assertSame('smtp', $this->configFor(['report_email_smtp_encryption' => 'none'])['scheme']);
    }

    public function test_a_blank_username_or_password_is_null_rather_than_an_empty_string(): void
    {
        // An empty string makes Symfony attempt AUTH with an empty user, which
        // a server needing no authentication rejects — and the operator reads
        // that as "wrong password".
        $config = $this->configFor([
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_smtp_username' => '',
            'report_email_smtp_password' => '',
        ]);

        $this->assertNull($config['username']);
        $this->assertNull($config['password']);
    }

    public function test_it_carries_the_host_port_and_the_opened_password(): void
    {
        $config = $this->configFor([
            'report_email_smtp_host' => 'smtp.shop.test',
            'report_email_smtp_port' => '465',
            'report_email_smtp_username' => 'reports@shop.test',
            'report_email_smtp_password' => ReportEmailSettings::seal('hunter2'),
        ]);

        $this->assertSame('smtp', $config['transport']);
        $this->assertSame('smtp.shop.test', $config['host']);
        $this->assertSame(465, $config['port']);
        $this->assertSame('reports@shop.test', $config['username']);
        $this->assertSame('hunter2', $config['password']);
    }

    public function test_the_timeout_is_bounded_rather_than_left_to_php(): void
    {
        // The send is synchronous inside a request somebody is waiting on;
        // config/mail.php's null would be PHP's own socket timeout.
        $this->assertSame(15, SmtpTransportConfig::TIMEOUT_SECONDS);
        $this->assertSame(15, $this->configFor([])['timeout']);
    }

    public function test_the_ehlo_domain_never_comes_back_empty(): void
    {
        config(['app.url' => 'not a url']);

        $this->assertSame('localhost', $this->configFor([])['local_domain']);
    }
}
