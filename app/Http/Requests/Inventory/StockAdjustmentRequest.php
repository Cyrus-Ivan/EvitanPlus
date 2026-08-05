<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Manual stock adjustment. Authorization is the `role:admin` route group plus a
 * second `manage-inventory` gate check in authorize() (defense-in-depth).
 *
 * Three modes:
 *  - `add` (default) → `value` is how many are arriving.
 *  - `remove`        → `value` is how many are leaving. The controller applies
 *                      the minus sign.
 *  - `set`           → `value` is the true counted on-hand; the controller
 *                      derives the signed ledger delta (value − current stock).
 *
 * **`value` is positive in all three**, and the direction is the mode. It used to
 * be a single signed field where a minus meant "remove", which asked the operator
 * to carry the direction in a character they could forget to type — and forgetting
 * it does the exact opposite of what was intended. The signs belong to the system,
 * as they already do for a transfer ({@see IssueTransferRequest}); `min:1` here is
 * what lets the controller rely on that.
 *
 * Notes are optional except when removing stock or setting a count — both need an
 * explanation captured on the ledger row.
 */
class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-inventory') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCount = $this->input('mode') === 'set';

        return [
            'mode' => ['required', 'string', 'in:add,remove,set'],
            // 'set' is an absolute count and 0 is a legitimate one — a shelf can be
            // empty. 'add'/'remove' are quantities of movement, where 0 is not a
            // movement at all.
            'value' => [
                'required',
                'integer',
                $isCount ? 'min:0' : 'min:1',
                'max:999999',
            ],
            'notes' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('mode'), ['set', 'remove'], true)),
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.min' => $this->input('mode') === 'set'
                ? 'A counted quantity cannot be negative.'
                : 'Enter how many are being added or removed.',
        ];
    }
}
