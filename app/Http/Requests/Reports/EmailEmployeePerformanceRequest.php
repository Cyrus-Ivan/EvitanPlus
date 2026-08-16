<?php

namespace App\Http\Requests\Reports;

use App\Http\Controllers\EmployeePerformanceController;
use App\Support\Email\ReportEmailSettings;
use App\Support\Email\ReportEmailSettingsRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EmailEmployeePerformanceRequest extends FormRequest
{
    /**
     * How many employees one send may cover.
     *
     * A real bound, not a round number: every employee on the list is a separate
     * dompdf render held in memory and a separate attachment on one message. A
     * business with more staff than this sends twice.
     */
    public const MAX_USERS = 20;

    /**
     * Authorization is the route's `role:admin` middleware and nothing else.
     * {@see EmployeePerformanceController} reports on the whole business at all
     * times and carries no cost or profit, so there is no narrower boundary here
     * to enforce and no branch scope to constrain.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipients' => ['required', 'array', 'min:1', 'max:'.ReportEmailSettingsRules::MAX_RECIPIENTS],
            'recipients.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'users' => ['required', 'array', 'min:1', 'max:'.self::MAX_USERS],
            'users.*' => ['required', 'uuid', 'distinct'],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
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
            'users.required' => 'Pick at least one employee.',
            'users.min' => 'Pick at least one employee.',
            'users.max' => 'Pick at most '.self::MAX_USERS.' employees for one send.',
        ] + ReportEmailSettingsRules::messages();
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return ReportEmailSettings::normalizeAddresses((array) $this->validated('recipients', []));
    }

    /**
     * @return list<string>
     */
    public function employees(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('users', [])));
    }
}
