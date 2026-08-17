<?php

namespace Tests\Feature\Reports;

use App\Models\Setting;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The M14 → M14.5 data migration, exercised on a device that actually has the
 * old settings.
 *
 * ## Why this file has to exist
 *
 * `2026_08_13_000001_split_report_email_period_into_schedules` runs in every
 * test — and does nothing in every test, because `RefreshDatabase` migrates a
 * device whose `settings` table is empty, so both keys it reads are absent and
 * both branches fall through. Its whole job is the upgrade of a device that has
 * been running M14, and that shape existed in no test at all: a migration can
 * only be wrong on data it was written for.
 *
 * The migration has already run by the time a test body starts, so these call
 * `up()` a second time against seeded rows. That is not an artificial setup —
 * the second run is against exactly the state the first run would have met on a
 * real upgrading device, which is the state under test.
 *
 * ## What it is protecting
 *
 * Two things an owner would notice, both silent:
 *
 * 1. **The schedule stops.** A device set to "every week" whose toggle does not
 *    carry over simply never emails again, and nothing reports it — the owner
 *    finds out by noticing an absence.
 * 2. **A period is sent twice.** The marker records the window that already went
 *    out. If it does not carry over, `SendScheduledSalesReport` finds no marker
 *    on the first tick after the upgrade and re-sends the period the owner has
 *    already read.
 *
 * Both are asserted through the constants the shipped code reads
 * ({@see ReportEmailSettings::LAST_SENT_KEY_PREFIX}, {@see ReportSchedule}), not
 * through literals, so a rename that misses the migration fails here. The
 * migration spells its own keys out on purpose — a migration is a record of what
 * was done on a day — which is precisely why the test must not.
 */
class ReportEmailScheduleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PERIOD_KEY = 'report_email_period';

    private const OLD_MARKER_KEY = 'report_email_last_period';

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_13_000001_split_report_email_period_into_schedules.php',
        );
    }

    /** @param array<string, string> $values */
    private function seedSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->create(['key' => $key, 'value' => $value]);
        }
    }

    private function value(string $key): ?string
    {
        return Setting::query()->where('key', $key)->value('value');
    }

    public function test_the_configured_period_becomes_that_kinds_toggle(): void
    {
        $this->seedSettings([self::OLD_PERIOD_KEY => 'weekly']);

        $this->migration()->up();

        $this->assertSame('1', $this->value('report_email_weekly_enabled'),
            'A device set to "every week" must keep emailing every week after the upgrade.');

        // Exactly one, not four. The migration carries a choice over; it does
        // not switch anything on that the owner never asked for.
        foreach (['daily', 'monthly', 'yearly'] as $other) {
            $this->assertNull($this->value('report_email_'.$other.'_enabled'),
                "The upgrade must not switch on a {$other} schedule nobody configured.");
        }
    }

    public function test_the_send_marker_carries_over_under_the_key_that_names_its_kind(): void
    {
        // M14 held kind and window start in one value because a weekly window's
        // start can equal a daily one's; M14.5 puts the kind in the key instead.
        $this->seedSettings([
            self::OLD_PERIOD_KEY => 'weekly',
            self::OLD_MARKER_KEY => 'weekly:2026-03-02',
        ]);

        $this->migration()->up();

        $this->assertSame('2026-03-02', $this->value(ReportEmailSettings::LAST_SENT_KEY_PREFIX.'weekly'),
            'Without the marker the first tick after the upgrade re-sends a period the owner already read.');
    }

    public function test_the_old_keys_are_gone_afterwards(): void
    {
        $this->seedSettings([
            self::OLD_PERIOD_KEY => 'daily',
            self::OLD_MARKER_KEY => 'daily:2026-03-10',
        ]);

        $this->migration()->up();

        $this->assertNull($this->value(self::OLD_PERIOD_KEY));
        $this->assertNull($this->value(self::OLD_MARKER_KEY));
    }

    public function test_a_device_that_had_email_switched_off_gets_no_schedule(): void
    {
        $this->seedSettings([self::OLD_PERIOD_KEY => 'off']);

        $this->migration()->up();

        foreach (ReportSchedule::KINDS as $kind) {
            $this->assertNull($this->value('report_email_'.$kind.'_enabled'),
                'Switched off before the upgrade must mean switched off after it.');
        }

        $this->assertNull($this->value(self::OLD_PERIOD_KEY));
    }

    public function test_an_unrecognised_period_or_marker_is_dropped_rather_than_guessed(): void
    {
        // A hand-edited settings row, or a value from a build that predates the
        // four kinds. Switching something on from a word nobody recognises would
        // be inventing a schedule; off is the new default and the safe read.
        $this->seedSettings([
            self::OLD_PERIOD_KEY => 'fortnightly',
            self::OLD_MARKER_KEY => 'fortnightly:2026-03-02',
        ]);

        $this->migration()->up();

        foreach (ReportSchedule::KINDS as $kind) {
            $this->assertNull($this->value('report_email_'.$kind.'_enabled'));
            $this->assertNull($this->value(ReportEmailSettings::LAST_SENT_KEY_PREFIX.$kind));
        }
    }

    public function test_a_period_with_no_marker_still_switches_its_schedule_on(): void
    {
        // The device was configured but has not sent yet — a genuine state, and
        // the one where reading the marker first and bailing would lose the
        // toggle. No marker means the first due window sends, which is right.
        $this->seedSettings([self::OLD_PERIOD_KEY => 'monthly']);

        $this->migration()->up();

        $this->assertSame('1', $this->value('report_email_monthly_enabled'));
        $this->assertNull($this->value(ReportEmailSettings::LAST_SENT_KEY_PREFIX.'monthly'));
    }

    public function test_running_it_twice_lands_in_the_same_place(): void
    {
        // LaunchProvisioner migrates on every launch. Laravel's ledger stops a
        // second run in production, but `put()` is written to update-or-insert
        // rather than insert blindly, and that promise is worth holding: the
        // second pass finds the old keys already deleted and must not undo the
        // first pass's work.
        $this->seedSettings([
            self::OLD_PERIOD_KEY => 'weekly',
            self::OLD_MARKER_KEY => 'weekly:2026-03-02',
        ]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('1', $this->value('report_email_weekly_enabled'));
        $this->assertSame('2026-03-02', $this->value(ReportEmailSettings::LAST_SENT_KEY_PREFIX.'weekly'));
        $this->assertSame(1, Setting::query()->where('key', 'report_email_weekly_enabled')->count(),
            'The upgrade must not leave a duplicate settings row behind.');
    }
}
