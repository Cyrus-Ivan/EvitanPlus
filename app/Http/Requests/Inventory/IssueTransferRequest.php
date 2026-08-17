<?php

namespace App\Http\Requests\Inventory;

use App\Rules\SellableVariant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Moving stock from one branch to another.
 *
 * The third sibling of {@see StockAdjustmentRequest} and
 * {@see IssueStockAdjustmentRequest}, and the differences say what a transfer is:
 *
 *  - **Two branches, and they may not be the same one.** A transfer to yourself
 *    is a recount with extra steps, and it would spawn a second pending row aimed
 *    back at the branch that just answered the first.
 *  - **The quantity is POSITIVE and means "how many are moving".** The signs are
 *    the system's business, not the operator's: the sending leg is written
 *    negative and the receiving leg positive, and asking somebody to type a minus
 *    to send stock away is how a transfer ends up doubling it.
 *  - **Notes are always optional.** The two branches will each say what actually
 *    happened when they answer; a note here is context for that, not a
 *    justification for a removal that has not happened yet.
 *
 * Neither branch id is checked against a local table, because there isn't one:
 * the roster lives in Postgres (`OnlineRead`), and the insert's own foreign keys
 * are the check that matters.
 */
class IssueTransferRequest extends FormRequest
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
            'from_branch_id' => ['required', 'uuid'],
            'to_branch_id' => ['required', 'uuid', 'different:from_branch_id'],
            'variant_id' => ['required', 'uuid', SellableVariant::rule()],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_branch_id.different' => 'Pick a different branch to send the stock to.',
            'quantity.min' => 'Enter how many are being moved.',
        ];
    }
}
