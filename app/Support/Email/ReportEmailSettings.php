<?php

namespace App\Support\Email;

use App\Console\Commands\SendScheduledSalesReport;
use App\Models\Setting;
use App\Support\Audit\SettingsDiff;
use App\Support\Printing\PrinterSettings;
use App\Support\Settings\BusinessSettings;
use App\Support\Sync\CredentialVault;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Read/write accessor for the report-email configuration: who the sales report
 * goes to by default, how often it goes out on its own, and the SMTP account it
 * is sent from.
 *
 * Like {@see PrinterSettings} — and deliberately unlike
 * {@see BusinessSettings} — every key here is DEVICE-LOCAL. The keys are not in
 * BusinessSettings::KEYS, which is what makes them invisible to the two places
 * that would otherwise reach them: App\Support\Sync\Puller::scope() narrows the
 * settings read to those keys and upserts by `key`, and
 * App\Support\Sync\Hydrator::copySettings() does the same. So a pull cannot
 * erase this configuration and a re-hydrate cannot overwrite it, for the same
 * reason the printer rows survive both.
 *
 * That is a design decision, not an accident of where the code landed. An SMTP
 * password mirrored into the business schema would sit in Postgres readable by
 * every branch role, and it is sealed here under the per-install APP_KEY, which
 * does not travel between installs anyway. See docs/glossary.md §10.
 *
 * Three shapes leave this class and they are not interchangeable:
 *  - all()        — internal, carries the sealed password ciphertext.
 *  - forDisplay() — the only shape that crosses to the frontend; no password.
 *  - forAudit()   — the only shape fed to SettingsDiff; the password is a
 *                   sentinel, because audit_logs is pushed to Postgres.
 */
class ReportEmailSettings
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /**
     * The address list is stored newline-separated rather than as JSON. A blob
     * that fails to parse has to fail somewhere — either the settings page 500s
     * or it silently degrades to "no recipients", which stops the scheduled
     * send forever with nothing on screen to say so. explode() is total, and
     * the `email:rfc` rule forbids newlines in an address, so the encoding
     * round-trips exactly.
     */
    public const RECIPIENT_SEPARATOR = "\n";

    /**
     * Every recognised key mapped to its default. Booleans are stored as the
     * strings '1'/'0' to match the string-valued settings table.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'report_email_recipients' => '',
        'report_email_daily_enabled' => '0',
        'report_email_daily_days' => '1,2,3,4,5,6,7',
        'report_email_daily_time' => ReportSchedule::DEFAULT_TIME,
        'report_email_weekly_enabled' => '0',
        'report_email_weekly_time' => ReportSchedule::DEFAULT_TIME,
        'report_email_monthly_enabled' => '0',
        'report_email_monthly_time' => ReportSchedule::DEFAULT_TIME,
        'report_email_yearly_enabled' => '0',
        'report_email_yearly_time' => ReportSchedule::DEFAULT_TIME,
        'report_email_include_cost' => '0',
        'report_email_smtp_host' => '',
        'report_email_smtp_port' => '587',
        'report_email_smtp_encryption' => 'tls',
        'report_email_smtp_username' => '',
        'report_email_smtp_password' => '',
        'report_email_from_address' => '',
        'report_email_from_name' => '',
    ];

    /**
     * The last window successfully sent for one kind, as a bare `Y-m-d` start
     * date, under a key that names the kind — `report_email_last_weekly`.
     *
     * One key per kind, not one shared key: the four schedules are independent,
     * and a daily and a weekly window can start on the same date. (M14 solved
     * that with a `daily:` prefix inside a single value; the per-kind key makes
     * the collision unrepresentable instead.)
     *
     * Deliberately outside DEFAULTS so no form can write one and no settings
     * diff can mention one — see {@see self::markSent()}.
     */
    public const LAST_SENT_KEY_PREFIX = 'report_email_last_';

    /**
     * When the last send FAILED, as an ISO-8601 string. The backoff clock, also
     * deliberately outside DEFAULTS.
     */
    public const LAST_ATTEMPT_KEY = 'report_email_last_attempt_at';

    public function __construct(private readonly BusinessSettings $business) {}

    /**
     * Every report-email setting as a key => string map, falling back to the
     * default for any key that has not been stored yet.
     *
     * Carries the sealed password ciphertext. Use {@see self::forDisplay()} for
     * anything that reaches the frontend and {@see self::forAudit()} for
     * anything that reaches the audit log.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = Setting::whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');

        return collect(self::DEFAULTS)
            ->mapWithKeys(fn (string $default, string $key): array => [
                $key => (string) ($stored[$key] ?? $default),
            ])
            ->all();
    }

    /**
     * Persist the given settings, upserting one row per key. Only the
     * recognised keys are written; anything else is ignored.
     *
     * A key that is ABSENT from $values is left standing — that is what lets
     * the update request omit the password to mean "keep the stored one"
     * without ever handling the ciphertext.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach (array_keys(self::DEFAULTS) as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $values[$key]],
                );
            }
        });
    }

    /**
     * The configured default recipients, in the order they were saved.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return self::normalizeAddresses(
            explode(self::RECIPIENT_SEPARATOR, $this->get('report_email_recipients')),
        );
    }

    /**
     * @param  array<int, string>  $addresses
     */
    public function setRecipients(array $addresses): void
    {
        $this->update([
            'report_email_recipients' => implode(self::RECIPIENT_SEPARATOR, self::normalizeAddresses($addresses)),
        ]);
    }

    /**
     * Trim, drop blanks, and drop case-insensitive duplicates while keeping the
     * case as typed — the local part of an address is technically
     * case-sensitive, so nothing here lowercases what it keeps.
     *
     * @param  array<int, string>  $addresses
     * @return list<string>
     */
    public static function normalizeAddresses(array $addresses): array
    {
        $seen = [];
        $kept = [];

        foreach ($addresses as $address) {
            $address = trim($address);

            if ($address === '') {
                continue;
            }

            $fold = mb_strtolower($address);

            if (isset($seen[$fold])) {
                continue;
            }

            $seen[$fold] = true;
            $kept[] = $address;
        }

        return $kept;
    }

    /**
     * All four automatic sends, keyed by kind, whether they are switched on or
     * not — {@see ReportSchedule::$enabled} is how a caller tells.
     *
     * The one accessor that reads {@see self::all()} directly rather than going
     * through get(): every typed accessor here is its own SELECT, and four
     * schedules over three keys apiece would be twelve of them on a tick whose
     * whole point is to be cheap when there is nothing to do.
     *
     * @return array<string, ReportSchedule>
     */
    public function schedules(): array
    {
        $values = $this->all();

        return collect(ReportSchedule::KINDS)
            ->mapWithKeys(fn (string $kind): array => [$kind => ReportSchedule::fromValues($kind, $values)])
            ->all();
    }

    /**
     * Whether anything goes out on its own. Replaces M14's `period !== 'off'`
     * as the single expression of "a schedule exists", which is what makes a
     * sender and a recipient required.
     */
    public function isScheduled(): bool
    {
        foreach ($this->schedules() as $schedule) {
            if ($schedule->enabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the SCHEDULED report carries cost, COGS and profit. Owner-only to
     * set and off by default: item cost is Owner-only to view, and an emailed
     * report has no viewer whose role that check could be true of, so including
     * it is an explicit decision by the one person entitled to make it.
     */
    public function includeCost(): bool
    {
        return $this->get('report_email_include_cost') === '1';
    }

    public function encryption(): string
    {
        $value = $this->get('report_email_smtp_encryption');

        return in_array($value, self::ENCRYPTIONS, true) ? $value : self::DEFAULTS['report_email_smtp_encryption'];
    }

    public function host(): ?string
    {
        return $this->trimmedOrNull('report_email_smtp_host');
    }

    public function port(): int
    {
        return (int) ($this->get('report_email_smtp_port') ?: self::DEFAULTS['report_email_smtp_port']);
    }

    public function username(): ?string
    {
        return $this->trimmedOrNull('report_email_smtp_username');
    }

    /**
     * The SMTP password in the clear, or null when none is stored.
     *
     * Degrades rather than throws when the ciphertext cannot be opened — the
     * same "settings rows moved to an install with a different APP_KEY" case
     * {@see CredentialVault} documents. Here it must not
     * throw at all: a Business Settings page that 500s is a page on which the
     * owner cannot fix the very setting that is broken. The test-email button
     * is where they find out.
     */
    public function password(): ?string
    {
        $sealed = $this->get('report_email_smtp_password');

        if ($sealed === '') {
            return null;
        }

        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException $e) {
            report($e);

            return null;
        }
    }

    public function hasPassword(): bool
    {
        return $this->get('report_email_smtp_password') !== '';
    }

    /**
     * Seal a plaintext password under the per-install APP_KEY. The plaintext
     * never reaches the settings table and the ciphertext never leaves it.
     */
    public static function seal(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    public function fromAddress(): ?string
    {
        return $this->trimmedOrNull('report_email_from_address');
    }

    /**
     * The display name on the From header, falling back to the business name so
     * a blank field still produces something a recipient recognises.
     */
    public function fromName(): string
    {
        $name = $this->trimmedOrNull('report_email_from_name');

        return $name ?? (string) ($this->business->all()['business_name'] ?? '');
    }

    /**
     * Whether a send can be ATTEMPTED — a host to reach and an address to send
     * from. Deliberately not "will succeed": credentials are only proven by
     * trying, which is what the test-email button is for.
     */
    public function isConfigured(): bool
    {
        return $this->host() !== null && $this->fromAddress() !== null;
    }

    /**
     * The shape that crosses to the frontend. The password ciphertext is not in
     * it at all — only whether one is stored — and the recipients are an array,
     * because the newline encoding is this class's business and nobody else's.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(): array
    {
        $values = $this->all();
        unset($values['report_email_smtp_password'], $values['report_email_recipients']);

        // The weekday list crosses as an array for the same reason the address
        // list does: the comma encoding is this class's business, and the form
        // posts back what a multi-select control holds. Strings, because that
        // is what a toggle group's values are.
        $values['report_email_daily_days'] = array_map(
            strval(...),
            ReportSchedule::parseDays($values['report_email_daily_days']),
        );

        return $values + [
            'recipients' => $this->recipients(),
            'password_set' => $this->hasPassword() ? '1' : '0',
        ];
    }

    /**
     * The shape fed to {@see SettingsDiff}. The password is
     * replaced by a sentinel, so a settings.updated row records THAT the
     * password changed without recording what it is.
     *
     * This is load-bearing, not cosmetic: App\Models\AuditLog is pushed upward,
     * so anything in old_values/new_values reaches the business schema where
     * every branch role can SELECT it.
     *
     * @return array<string, string>
     */
    public function forAudit(): array
    {
        $values = $this->all();
        $values['report_email_smtp_password'] = $this->hasPassword() ? '(set)' : '(unset)';

        return $values;
    }

    /**
     * The start date of the last window sent for this kind, or '' when none has
     * been. See {@see SendScheduledSalesReport} for how it is compared.
     */
    public function lastSent(string $kind): string
    {
        return (string) (Setting::where('key', self::lastSentKey($kind))->value('value') ?? '');
    }

    public function markSent(string $kind, string $windowStart): void
    {
        Setting::updateOrCreate(['key' => self::lastSentKey($kind)], ['value' => $windowStart]);
    }

    public static function lastSentKey(string $kind): string
    {
        return self::LAST_SENT_KEY_PREFIX.$kind;
    }

    public function lastAttemptAt(): ?Carbon
    {
        $value = (string) (Setting::where('key', self::LAST_ATTEMPT_KEY)->value('value') ?? '');

        if ($value === '') {
            return null;
        }

        return rescue(fn (): Carbon => Carbon::parse($value), null, report: false);
    }

    public function markAttempt(): void
    {
        Setting::updateOrCreate(
            ['key' => self::LAST_ATTEMPT_KEY],
            ['value' => Carbon::now()->toIso8601String()],
        );
    }

    /**
     * Blanked rather than deleted — every other write in this class is an
     * upsert, and a row that comes and goes is one more state to reason about
     * for no gain.
     */
    public function clearAttempt(): void
    {
        Setting::updateOrCreate(['key' => self::LAST_ATTEMPT_KEY], ['value' => '']);
    }

    private function trimmedOrNull(string $key): ?string
    {
        $value = trim($this->get($key));

        return $value === '' ? null : $value;
    }

    private function get(string $key): string
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? '';
    }
}
