<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An adjustment issued against ANOTHER branch (M8).
 *
 * The sibling of {@see StockAdjustmentRequest}, and the differences are the
 * whole design:
 *
 *  - **`branch_id` is required and is the TARGET**, not this device. It is not
 *    validated against a local table because there isn't one — the roster lives
 *    in Postgres (`OnlineRead`), and the insert's own foreign key is the check
 *    that matters.
 *  - **There is no `set` mode**, so `mode` is `add` or `remove` only. "Set to
 *    count" needs a current on-hand to subtract from, and the only figure this
 *    device could use is a baseline that excludes the target's unsynced sales.
 *    Deriving a delta from it would be arithmetic dressed up as a fact, and the
 *    resulting subtraction would be rejected at the far end for reasons nobody
 *    could see from here.
 *
 * `inventory_update_queue.quantity` is signed and stays signed, but the request
 * is not: `quantity` here is positive and the direction is `mode`, exactly as on
 * {@see StockAdjustmentRequest} and {@see IssueTransferRequest}.
 * `InventoryQueueController::store()` is where the two meet and the minus sign is
 * applied.
 *
 * Notes stay required for a removal, for the same reason they are on a local
 * adjustment: the ledger entry it may become has to explain itself.
 */
class IssueStockAdjustmentRequest extends FormRequest
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
        return [
            'branch_id' => ['required', 'uuid'],
            'variant_id' => ['required', 'uuid', 'exists:variants,id'],
            'mode' => ['required', 'string', 'in:add,remove'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'notes' => [
                Rule::requiredIf(fn (): bool => $this->input('mode') === 'remove'),
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
            'quantity.min' => 'Enter how many are being added or removed.',
        ];
    }
}
