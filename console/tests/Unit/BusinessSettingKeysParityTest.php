<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Provisioning\Support\BusinessSettingKeys;

/**
 * Keeps the console's copy of the business setting keys in step with the app's.
 *
 * The console cannot `use App\Support\Settings\BusinessSettings`: it is a
 * separate application with its own autoloader, and §18 forbids a runtime
 * reference in either direction. So the keys are duplicated — and duplication
 * without a guard is duplication that drifts. If someone adds a seventh setting
 * to the app, a provisioned business would quietly lack it and every till would
 * fall back to the default; this test fails instead.
 *
 * The app's file is read as TEXT, never loaded — the same dev-time file-read
 * justification SchemaParityTest already documents for the app's migrations.
 */
class BusinessSettingKeysParityTest extends TestCase
{
    private const APP_FILE = '/../../../app/Support/Settings/BusinessSettings.php';

    public function test_the_console_knows_exactly_the_apps_setting_keys(): void
    {
        $appKeys = $this->appKeys();

        $this->assertNotEmpty($appKeys, 'Could not parse the app\'s BusinessSettings::DEFAULTS.');

        $this->assertSame(
            $appKeys,
            BusinessSettingKeys::KEYS,
            'The app and the console disagree about the business setting keys. '
            .'Update Provisioning\Support\BusinessSettingKeys to match.'
        );
    }

    public function test_every_key_has_a_default(): void
    {
        $this->assertSame(
            BusinessSettingKeys::KEYS,
            array_keys(BusinessSettingKeys::DEFAULTS),
            'KEYS and DEFAULTS must describe the same set, in the same order.'
        );
    }

    public function test_the_shared_defaults_agree(): void
    {
        $source = $this->appSource();

        // The two that actually matter at provisioning time: they are the values
        // the prompts offer, and a mismatch would ship a business configured
        // differently from what the operator was shown.
        $this->assertStringContainsString(
            "'timezone' => '".BusinessSettingKeys::DEFAULTS['timezone']."'",
            $source,
        );
        $this->assertStringContainsString(
            "'currency_symbol' => '".BusinessSettingKeys::DEFAULTS['currency_symbol']."'",
            $source,
        );
    }

    /** @return list<string> */
    private function appKeys(): array
    {
        // Match the DEFAULTS block, then the quoted keys inside it. Parsing the
        // constant rather than the KEYS list keeps this honest: DEFAULTS is what
        // the app actually reads with.
        if (! preg_match('/const DEFAULTS = \[(.*?)\];/s', $this->appSource(), $block)) {
            return [];
        }

        preg_match_all("/'([a-z_]+)'\s*=>/", $block[1], $keys);

        return $keys[1];
    }

    private function appSource(): string
    {
        $path = __DIR__.self::APP_FILE;

        $this->assertFileExists($path, 'The app\'s BusinessSettings moved; update this parity test.');

        return (string) file_get_contents($path);
    }
}
