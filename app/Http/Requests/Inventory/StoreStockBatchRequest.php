<?php

namespace App\Http\Requests\Inventory;

use App\Support\Inventory\AdjustmentQueue;
use App\Support\Sync\OnlineRead;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Asking for many stock movements as one act — a STOCK BATCH (docs/glossary.md
 * §4, M13).
 *
 * The fourth sibling of {@see StockAdjustmentRequest},
 * {@see IssueStockAdjustmentRequest} and {@see IssueTransferRequest}, and it is
 * those three widened by exactly one dimension: a list of lines instead of one
 * variant. Everything else it enforces, they enforce too.
 *
 * ## Two shapes behind one route, and never a mix
 *
 * `kind` picks which: an ADJUSTMENT names one branch and a direction, a TRANSFER
 * names two branches and no direction (the signs are the system's — see
 * `IssueTransferRequest`). They are not combinable, because the quantity on a
 * line means a different thing in each: how many to add or remove from one
 * shelf, versus how many are moving between two.
 *
 * ## `set` is offered for THIS branch only
 *
 * "Readjust to this number" needs a trustworthy on-hand figure to subtract from,
 * and the only one this device has is its own. Another branch's stock reads as a
 * BASELINE — it excludes whatever they have sold and not yet pushed — so a delta
 * computed from it is wrong by exactly the sales the network has not caught up
 * with, and wrong SILENTLY. `IssueStockAdjustmentRequest` has drawn this line
 * since M8 by allowing only `add` and `remove`; this draws the same one
 * conditionally, because the same dialog serves both targets.
 *
 * Branch ids are not checked against a local table, because there isn't one: the
 * roster lives in Postgres ({@see OnlineRead}), and the
 * insert's own foreign keys are the check that matters.
 */
class StoreStockBatchRequest extends FormRequest
{
    /** A line per variant, and a person has to be able to read the dialog. */
    public const MAX_LINES = 100;

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
            'kind' => ['required', 'in:adjustment,transfer'],

            'branch_id' => ['required_if:kind,adjustment', 'uuid'],
            'mode' => ['required_if:kind,adjustment', 'in:add,remove,set'],

            'from_branch_id' => ['required_if:kind,transfer', 'uuid'],
            'to_branch_id' => ['required_if:kind,transfer', 'uuid', 'different:from_branch_id'],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.variant_id' => ['required', 'uuid', 'exists:variants,id'],
            // Always positive. What it means is decided by `kind` and `mode`, and
            // asking an operator to type a minus sign to remove stock is how a
            // removal ends up adding.
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],

            // One note for the whole batch, and it lands on every row. Required
            // for the two adjustment modes that take stock away or overwrite a
            // count, exactly as StockAdjustmentRequest requires it for one
            // variant — a batch does not make the reason less necessary.
            'notes' => [
                $this->requiresNotes() ? 'required' : 'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateSetIsLocal($validator);
            $this->validateNoDuplicateVariants($validator);
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.required' => 'Choose whether this is a transfer or a stock adjustment.',
            'to_branch_id.different' => 'Pick a different branch to send the stock to.',
            'lines.required' => 'Add at least one product.',
            'lines.min' => 'Add at least one product.',
            'lines.max' => 'A batch can hold at most '.self::MAX_LINES.' products.',
            'lines.*.variant_id.required' => 'Choose a product for this row.',
            'lines.*.quantity.required' => 'Enter a quantity for this row.',
            'lines.*.quantity.min' => 'Enter how many, as a positive number.',
            'notes.required' => 'Say why, so the branch answering knows what they are agreeing to.',
        ];
    }

    /**
     * Whether the batch's single note is mandatory.
     *
     * Only for an adjustment that removes stock or overwrites a count. A
     * transfer's note is optional for the reason `IssueTransferRequest` gives:
     * both branches say what actually happened when they answer, so a note here
     * is context rather than a justification.
     */
    private function requiresNotes(): bool
    {
        return $this->input('kind') === 'adjustment'
            && in_array($this->input('mode'), ['remove', 'set'], true);
    }

    /**
     * `set` is a claim about a shelf this device can see. See the class docblock.
     */
    private function validateSetIsLocal(Validator $validator): void
    {
        if ($this->input('kind') !== 'adjustment' || $this->input('mode') !== 'set') {
            return;
        }

        if ((string) $this->input('branch_id') === app(AdjustmentQueue::class)->thisBranch()) {
            return;
        }

        $validator->errors()->add(
            'mode',
            'Setting stock to an exact number is only possible for this branch. '
                .'Another branch\'s figure does not include what they have sold since their last sync, '
                .'so use Add or Remove instead.',
        );
    }

    /**
     * One row per product.
     *
     * Two lines for the same variant are not additive, they are ambiguous: under
     * `set` they contradict each other outright, and under any mode they would
     * become two separate pending rows that somebody has to answer twice for one
     * shelf. Cheaper to refuse than to explain.
     */
    private function validateNoDuplicateVariants(Validator $validator): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $seen = [];

        foreach ($lines as $index => $line) {
            $variantId = is_array($line) ? ($line['variant_id'] ?? null) : null;

            if (! is_string($variantId) || $variantId === '') {
                continue;
            }

            if (isset($seen[$variantId])) {
                $validator->errors()->add(
                    "lines.{$index}.variant_id",
                    'This product is already on another row. Put the whole quantity on one row.',
                );
            }

            $seen[$variantId] = true;
        }
    }
}
