<?php

namespace App\Support\Email;

use App\Support\Printing\PrinterSettingsRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The one validation rule set for the report-email settings, kept beside
 * {@see ReportEmailSettings} for the same reason
 * {@see PrinterSettingsRules} is kept beside the printer
 * settings: the conditional requirements are the interesting part and they must
 * not drift between callers.
 */
class ReportEmailSettingsRules
{
    /**
     * A hard ceiling on the address list. The send is synchronous and goes as
     * one message, so an unbounded list is an unbounded envelope on a request
     * the operator is waiting for.
     */
    public const MAX_RECIPIENTS = 20;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rules(FormRequest $request): array
    {
        // A sender is required as soon as anything depends on one: a schedule
        // that cannot fire must be a field error at save time, not a warning
        // line in a log nobody reads. A half-filled transport counts too — the
        // owner is plainly mid-setup.
        $needsTransport = fn (): bool => self::isScheduled($request)
            || filled($request->input('report_email_smtp_host'))
            || filled($request->input('report_email_from_address'));

        $schedules = [];

        foreach (ReportSchedule::KINDS as $kind) {
            $schedules['report_email_'.$kind.'_enabled'] = ['required', 'boolean'];
            $schedules['report_email_'.$kind.'_time'] = ['required', Rule::in(ReportSchedule::times())];
        }

        // The only per-kind field that is not a toggle and a time. Required
        // only when daily is on, so switching it off never traps the owner
        // behind a field they cannot see the point of.
        $schedules['report_email_daily_days'] = [
            'present', 'array', 'max:7',
            Rule::requiredIf(fn (): bool => $request->boolean('report_email_daily_enabled')),
        ];
        $schedules['report_email_daily_days.*'] = ['integer', Rule::in(ReportSchedule::DAYS)];

        return $schedules + [
            'recipients' => ['present', 'array', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'report_email_include_cost' => ['required', 'boolean'],
            'report_email_smtp_host' => ['nullable', 'string', 'max:255', Rule::requiredIf($needsTransport)],
            'report_email_smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'report_email_smtp_encryption' => ['required', Rule::in(ReportEmailSettings::ENCRYPTIONS)],
            'report_email_smtp_username' => ['nullable', 'string', 'max:255'],
            // Blank means "keep the stored password" — see
            // ReportEmailSettingsUpdateRequest::settingsPayload().
            'report_email_smtp_password' => ['nullable', 'string', 'max:255'],
            'report_email_smtp_password_clear' => ['nullable', 'boolean'],
            'report_email_from_address' => ['nullable', 'email:rfc', 'max:254', Rule::requiredIf($needsTransport)],
            'report_email_from_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The one rule that spans two fields: a schedule with nobody to send to is
     * the same silent no-op as a schedule with no sender.
     */
    public static function after(FormRequest $request): callable
    {
        return function (Validator $validator) use ($request): void {
            if (! self::isScheduled($request)) {
                return;
            }

            if (ReportEmailSettings::normalizeAddresses((array) $request->input('recipients', [])) !== []) {
                return;
            }

            $validator->errors()->add(
                'recipients',
                'Add at least one recipient, or switch the automatic reports off.',
            );
        };
    }

    /**
     * Whether the submitted form turns any automatic report on. The single
     * expression of "a schedule exists" on the write side, matching
     * {@see ReportEmailSettings::isScheduled()} on the read side.
     */
    public static function isScheduled(FormRequest $request): bool
    {
        foreach (ReportSchedule::KINDS as $kind) {
            if ($request->boolean('report_email_'.$kind.'_enabled')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'recipients.max' => 'A report can go to at most '.self::MAX_RECIPIENTS.' addresses.',
            'recipients.*.email' => 'Enter a valid email address.',
            'recipients.*.distinct' => 'This address is already on the list.',
            'report_email_daily_days.required' => 'Pick at least one day, or switch the daily report off.',
            'report_email_smtp_host.required' => 'A sending server is needed before the report can be emailed.',
            'report_email_from_address.required' => 'A sending address is needed before the report can be emailed.',
        ];
    }
}
