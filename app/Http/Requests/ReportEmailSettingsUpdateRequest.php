<?php

namespace App\Http\Requests;

use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportEmailSettingsRules;
use App\Support\Email\ReportSchedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReportEmailSettingsUpdateRequest extends FormRequest
{
    /**
     * Authorization is handled by the route's role:owner middleware.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ReportEmailSettingsRules::rules($this);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [ReportEmailSettingsRules::after($this)];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ReportEmailSettingsRules::messages();
    }

    /**
     * The key => value map for {@see ReportEmailSettings::update()}.
     *
     * Owns the password rule, because it is the only place that ever holds the
     * plaintext:
     *
     *  - "remove the saved password" checked → store '';
     *  - the field left blank → the key is OMITTED entirely, and update()'s
     *    skip-if-absent guard leaves the stored ciphertext exactly as it was;
     *  - anything else → sealed under the per-install APP_KEY.
     *
     * `recipients` is not in here: the list is written through
     * {@see ReportEmailSettings::setRecipients()}, which owns its encoding.
     *
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        $data = $this->validated();
        unset($data['recipients'], $data['report_email_smtp_password_clear']);

        $data['report_email_include_cost'] = $this->boolean('report_email_include_cost') ? '1' : '0';
        $data['report_email_smtp_port'] = (string) $this->validated('report_email_smtp_port');

        // Every boolean needs its own coercion: a validated `boolean` rule
        // yields a PHP bool, and update() casts with (string), which turns
        // false into '' rather than '0'.
        foreach (ReportSchedule::KINDS as $kind) {
            $data['report_email_'.$kind.'_enabled'] = $this->boolean('report_email_'.$kind.'_enabled') ? '1' : '0';
        }

        // The one array-valued setting; update() would choke on it as-is.
        $data['report_email_daily_days'] = ReportSchedule::encodeDays((array) ($data['report_email_daily_days'] ?? []));

        foreach (['report_email_smtp_host', 'report_email_smtp_username', 'report_email_from_address', 'report_email_from_name'] as $key) {
            $data[$key] = (string) ($data[$key] ?? '');
        }

        $password = (string) ($this->validated('report_email_smtp_password') ?? '');

        if ($this->boolean('report_email_smtp_password_clear')) {
            $data['report_email_smtp_password'] = '';
        } elseif ($password === '') {
            unset($data['report_email_smtp_password']);
        } else {
            $data['report_email_smtp_password'] = ReportEmailSettings::seal($password);
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return ReportEmailSettings::normalizeAddresses((array) $this->validated('recipients', []));
    }
}
