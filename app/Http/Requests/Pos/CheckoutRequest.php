<?php

namespace App\Http\Requests\Pos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A POS checkout. Authorization is handled by the route `auth` middleware (POS
 * is Cashier-level), so no authorize() override — matches the app convention.
 *
 * The payload carries only what the client legitimately chooses: which variant
 * and how many, the payment type, an optional transaction-level discount, and
 * cash tendered. Prices, costs and line/transaction totals are NEVER accepted
 * from the client — the controller recomputes every peso from the current
 * variant records. The amount-discount ≤ subtotal cap and the cash_received ≥
 * total check need that server-computed total, so they live in the controller.
 */
class CheckoutRequest extends FormRequest
{
    /**
     * Normalize the optional comment before validation: trim it and collapse an
     * empty/whitespace-only value to null so a blank input never stores "".
     */
    protected function prepareForValidation(): void
    {
        $comment = trim((string) $this->input('comment', ''));

        $this->merge(['comment' => $comment === '' ? null : $comment]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.variant_id' => [
                'required',
                'uuid',
                Rule::exists('variants', 'id')->whereNull('archived_at'),
            ],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999999'],

            'payment_type' => ['required', Rule::in(['cash', 'cashless', 'others'])],

            // A discount that takes money off must say WHICH kind it is. Without
            // this the controller's `match` falls through to the amount branch
            // and applies it, then stores a null type beside a non-zero figure —
            // and every screen that reads the pair keys on the type, so the sale
            // renders as "no discount" while the receipt (which derives it from
            // subtotal − total) shows the money. A zero discount still needs no
            // type: nothing is being taken off.
            'discount_type' => [
                Rule::requiredIf(fn (): bool => (float) $this->input('discount', 0) > 0),
                'nullable',
                Rule::in(['amount', 'percentage']),
            ],
            'discount' => [
                'nullable',
                'numeric',
                'min:0',
                // A peso has two decimal places. `centavos()` would otherwise
                // round a third away in silence — see cash_received below.
                'decimal:0,2',
                // A percentage discount can never exceed 100%. The amount cap
                // (≤ subtotal) depends on the server-computed subtotal, so it is
                // enforced in the controller.
                ...($this->input('discount_type') === 'percentage' ? ['max:100'] : []),
            ],

            // Cash tendered is required for a cash sale; the ≥ total check is in
            // the controller. Non-cash payments may omit it.
            'cash_received' => [
                Rule::requiredIf(fn (): bool => $this->input('payment_type') === 'cash'),
                'nullable',
                'numeric',
                'min:0',
                // Refused below the centavo rather than rounded: `centavos()`
                // multiplies by 100 and rounds, so ₱100.999 tendered became
                // ₱101.00 and the CHANGE was computed from the rounded figure,
                // leaving the drawer short by up to a centavo with nothing said.
                'decimal:0,2',
            ],

            // Optional free-text note captured at sale time; normalized to null
            // when blank in prepareForValidation().
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
