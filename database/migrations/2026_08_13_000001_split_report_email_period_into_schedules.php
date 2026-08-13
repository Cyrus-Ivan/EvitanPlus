<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carry M14's single `report_email_period` setting over to the four independent
 * schedules that replaced it.
 *
 * A data migration rather than a schema one: the settings table is key/value and
 * unchanged. It exists so an owner who had "every week" set keeps getting a
 * weekly report after the upgrade instead of silently getting nothing —
 * LaunchProvisioner migrates on every launch, so this runs on the way in.
 *
 * The old send marker is carried over too. It held "<kind>:<window start>" in
 * one row; without seeding the matching per-kind row, the first tick after the
 * upgrade would re-send a period that already went out.
 *
 * Every name here is spelled out rather than read from
 * App\Support\Email\ReportSchedule: a migration is a record of what was done on
 * a given day, and must not change meaning when that class does.
 *
 * Device-local rows throughout — none of these keys are in
 * BusinessSettings::KEYS, so nothing here touches Postgres.
 */
return new class extends Migration
{
    private const KINDS = ['daily', 'weekly', 'monthly', 'yearly'];

    private const OLD_PERIOD_KEY = 'report_email_period';

    private const OLD_MARKER_KEY = 'report_email_last_period';

    public function up(): void
    {
        $period = (string) (DB::table('settings')->where('key', self::OLD_PERIOD_KEY)->value('value') ?? '');

        // 'off', a blank, or anything unrecognised leaves all four switched off,
        // which is the new default anyway.
        if (in_array($period, self::KINDS, true)) {
            $this->put('report_email_'.$period.'_enabled', '1');
        }

        $marker = (string) (DB::table('settings')->where('key', self::OLD_MARKER_KEY)->value('value') ?? '');

        // "daily:2026-03-10" — the kind prefix the per-kind key made redundant.
        [$kind, $windowStart] = array_pad(explode(':', $marker, 2), 2, '');

        if (in_array($kind, self::KINDS, true) && $windowStart !== '') {
            $this->put('report_email_last_'.$kind, $windowStart);
        }

        DB::table('settings')->whereIn('key', [self::OLD_PERIOD_KEY, self::OLD_MARKER_KEY])->delete();
    }

    /**
     * Irreversible by choice. Four independent schedules do not collapse back
     * into one radio value without picking a winner, and this only ever runs on
     * a device being upgraded forward.
     */
    public function down(): void {}

    private function put(string $key, string $value): void
    {
        $exists = DB::table('settings')->where('key', $key)->exists();

        if ($exists) {
            DB::table('settings')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);

            return;
        }

        DB::table('settings')->insert([
            'id' => (string) Str::uuid(),
            'key' => $key,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
