<?php

namespace App\Http\Requests\Reports;

use App\Http\Controllers\Concerns\OffersBranchScope;
use App\Http\Controllers\ReportsController;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportEmailSettingsRules;
use App\Support\Reports\ReportPeriods;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmailReportRequest extends FormRequest
{
    /**
     * Authorization is the route's role:admin middleware, plus
     * {@see OffersBranchScope::normalizeScope()} — which
     * {@see ReportsController} calls with the `$authorize` hook that makes the
     * consolidated scopes Owner-only.
     *
     * `scope` is deliberately NOT validated against a list here. normalizeScope
     * is the authority — it constrains AND authorizes in one place — and a
     * second copy of that rule is how the Owner-only boundary drifts. It is one
     * shared method on the trait since the release hardening sweep; it was six
     * near-identical private copies, and four of them widened an unusable value
     * to `all` instead of narrowing it to `local`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipients' => ['required', 'array', 'min:1', 'max:'.ReportEmailSettingsRules::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'period' => ['nullable', Rule::in(array_keys(ReportPeriods::SUB_NOUNS))],
            'date' => ['nullable', 'date'],
            'scope' => ['nullable', 'string'],
            'by' => ['nullable', Rule::in(['item', 'branch'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipients.required' => 'Pick at least one recipient.',
            'recipients.min' => 'Pick at least one recipient.',
        ] + ReportEmailSettingsRules::messages();
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return ReportEmailSettings::normalizeAddresses((array) $this->validated('recipients', []));
    }
}
