<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Read/write accessor for the business-identity settings (Phase 4). These live
 * as individual key/value rows in the settings table; this class is the single
 * place that knows the key names so they aren't scattered across the codebase.
 */
class BusinessSettings
{
    /**
     * Every recognised key mapped to its default, applied at read time so the
     * keys exist logically even before any row is written (a fresh install has
     * none until the setup wizard completes).
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
     * Every business setting as a key => string map, falling back to the
     * default for any key that has not been stored yet.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = Setting::whereIn('key', self::KEYS)->pluck('value', 'key');

        return collect(self::DEFAULTS)
            ->mapWithKeys(fn (string $default, string $key): array => [
                $key => (string) ($stored[$key] ?? $default),
            ])
            ->all();
    }

    /**
     * Persist the given business settings, upserting one row per key. Only the
     * recognised KEYS are written; anything else is ignored.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach (self::KEYS as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $values[$key]],
                );
            }
        });
    }
}
