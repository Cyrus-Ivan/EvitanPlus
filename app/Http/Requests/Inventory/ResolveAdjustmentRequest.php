<?php

namespace App\Http\Requests\Inventory;

use App\Support\Inventory\AdjustmentResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Answering pending stock adjustments: approve, partially approve, or reject.
 *
 * ## No `manage-inventory` check, and that is deliberate
 *
 * Its siblings ({@see IssueStockAdjustmentRequest}, {@see StockAdjustmentRequest})
 * both gate on that ability, and copying them here would be the one change that
 * silently breaks this feature. Issuing an adjustment decides what SHOULD move
 * and is an Admin's call; resolving one reports what DID, and the person who
 * knows is whoever is standing in the shop when the delivery arrives. A Cashier
 * who cannot confirm stock leaves it waiting for somebody who is not there.
 *
 * Being authenticated is the whole of the requirement, and `resolved_by` records
 * who it was. What still bounds it is not a role but a branch: the resolver
 * re-reads every row filtered to this device's own branch, so nobody can answer
 * for a shop they are not in.
 *
 * ## A reason is required for anything other than a plain yes
 *
 * "It all arrived" needs no explanation. "None of it arrived" and "two of the
 * three arrived" are claims that contradict what somebody else asked for, and the
 * person who asked is often at another branch reading this days later, with the
 * queue row as their only account of what happened. A category would not help
 * them; a sentence does.
 *
 * The quantity itself is checked in {@see AdjustmentResolver}, not here: whether
 * an approved amount is a subset of what was asked for depends on the row in
 * Postgres, and this class deliberately never reads it — trusting a client-sent
 * `quantity` for that comparison is how a branch would approve itself more than
 * it was sent.
 */
class ResolveAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'adjustments' => ['required', 'array', 'min:1', 'max:500'],
            'adjustments.*.id' => ['required', 'uuid'],
            'adjustments.*.outcome' => ['required', Rule::in(AdjustmentResolver::OUTCOMES)],
            // Shape only. WHETHER each is required depends on that row's own
            // outcome, which no rule in this list can see — see withValidator().
            'adjustments.*.quantity' => [
                'nullable',
                'integer',
                'min:-999999',
                'max:999999',
                'not_in:0',
            ],
            'adjustments.*.reason' => [
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Require a quantity on the rows partially approved, and a reason on the rows
     * partially approved or turned down — each judged by ITS OWN outcome.
     *
     * These were `Rule::requiredIf` closures, which have no idea which row they
     * are running for, so the condition was asked of the whole submit: any one
     * partial made `quantity` required on EVERY row, and any one rejection made
     * `reason` required on every row. A card answered row by row — some arrived,
     * some did not — came back 422 with errors against rows the operator had
     * answered perfectly well. Defensible while a resolution was one row from a
     * dialog; not once "Approve all" began posting a whole card here.
     *
     * An `after` closure rather than `Rule::forEach`, which expands over the data
     * that IS there and so never fires for the missing key that is the whole
     * point of a `required` rule.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $adjustments */
            $adjustments = (array) $this->input('adjustments', []);

            foreach ($adjustments as $index => $adjustment) {
                $outcome = $adjustment['outcome'] ?? null;

                if ($outcome === 'partial' && ($adjustment['quantity'] ?? null) === null) {
                    $validator->errors()->add(
                        "adjustments.{$index}.quantity",
                        'Enter how much of it actually arrived.',
                    );
                }

                if (in_array($outcome, ['partial', 'reject'], true) && trim((string) ($adjustment['reason'] ?? '')) === '') {
                    $validator->errors()->add(
                        "adjustments.{$index}.reason",
                        'Say why, so the branch that asked for this can see what happened.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'adjustments.*.quantity.not_in' => 'Approving none of it is a rejection — turn it down instead.',
        ];
    }

    /**
     * The decisions, keyed by queue row id, in the shape the resolver takes.
     *
     * Keyed rather than a list because the resolver looks each row up by id
     * against what Postgres says is actually pending, and because two decisions
     * for the same row in one submit is not a thing to resolve twice.
     *
     * @return array<string, array{outcome: string, quantity: int|null, reason: string|null}>
     */
    public function decisions(): array
    {
        $decisions = [];

        /** @var array<int, array<string, mixed>> $adjustments */
        $adjustments = $this->validated()['adjustments'];

        foreach ($adjustments as $adjustment) {
            $decisions[(string) $adjustment['id']] = [
                'outcome' => (string) $adjustment['outcome'],
                'quantity' => isset($adjustment['quantity']) ? (int) $adjustment['quantity'] : null,
                'reason' => $adjustment['reason'] ?? null,
            ];
        }

        return $decisions;
    }
}
