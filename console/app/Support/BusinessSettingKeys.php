<?php

namespace Provisioning\Support;

use Illuminate\Support\Str;

/**
 * The console's copy of the app's business-identity setting keys.
 *
 * A COPY, deliberately: the console must not reference shipped-app code (§18 —
 * no runtime dependency in either direction), and the two apps have separate
 * composer autoloaders, so `use App\Support\Settings\BusinessSettings` is not
 * available here even in principle.
 *
 * The duplication is guarded by a unit test that reads
 * app/Support/Settings/BusinessSettings.php as a FILE and asserts the two lists
 * agree — the same dev-time file-read justification SchemaParityTest already
 * uses for the app's migrations. If someone adds a seventh setting to the app,
 * that test fails rather than a provisioned business quietly missing a key.
 */
final class BusinessSettingKeys
{
    /**
     * Mirrors App\Support\Settings\BusinessSettings::DEFAULTS.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'business_name' => 'Evitan Plus',
        'business_address' => '',
        'tin' => '',
        'receipt_footer' => '',
        'timezone' => 'Asia/Manila',
        'currency_symbol' => '₱',
    ];

    /**
     * The registered business name.
     *
     * Named because M3 reads it back: a branch command targets a schema, and
     * both its confirmation prompt and the credentials JSON need to say which
     * business that is. There is no businesses registry to ask (see
     * console/database/migrations-admin/) — each schema's own settings row is the
     * source of truth.
     */
    public const BUSINESS_NAME = 'business_name';

    /** @var list<string> */
    public const KEYS = [
        'business_name',
        'business_address',
        'tin',
        'receipt_footer',
        'timezone',
        'currency_symbol',
    ];

    /**
     * Insert-ready rows for the settings table, one per key.
     *
     * Values are cast to strings and blanks become '' rather than null, exactly
     * as the app's CompleteSetupRequest::businessPayload() does — the app reads
     * these with a string cast and a read-time default, so a null would present
     * as the default instead of the empty value the operator actually chose.
     *
     * created_at/updated_at are set explicitly: the Postgres settings table
     * declares timestampsTz() with no default, and nothing here goes through
     * Eloquent to fill them in.
     *
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    public static function rows(array $values): array
    {
        $now = now();

        return array_map(static fn (string $key): array => [
            'id' => (string) Str::orderedUuid(),
            'key' => $key,
            'value' => (string) ($values[$key] ?? self::DEFAULTS[$key]),
            'created_at' => $now,
            'updated_at' => $now,
        ], self::KEYS);
    }
}
