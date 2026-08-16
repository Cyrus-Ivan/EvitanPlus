import type { PaymentType } from '@/types';

/**
 * How a payment type is named and toned, shared by the four surfaces that show
 * one: the sales list, the sale detail, the POS payment picker and the employee
 * day dialog.
 *
 * Shaped after `lib/stock-movement.ts` and `lib/login-events.ts`, and written
 * for the same reason: the four surfaces each carried their own copy, and they
 * had already drifted. The sales *list* toned the badge per type while the sale
 * *detail* hardcoded `secondary`, so one sale wore two different badges
 * depending on which screen you opened it from.
 *
 * Mirrors `App\Enums\PaymentType`. Adding a type means adding it here too —
 * `paymentLabel` falls back to the raw value rather than rendering nothing, so a
 * build that predates a new type degrades to "gcash" instead of a blank cell.
 */
export const PAYMENT: Record<PaymentType, { label: string; variant: 'default' | 'secondary' | 'outline' }> = {
    cash: { label: 'Cash', variant: 'default' },
    cashless: { label: 'Cashless', variant: 'secondary' },
    others: { label: 'Others', variant: 'outline' },
};

/** A payment type's label, falling back to the raw value for one this build predates. */
export function paymentLabel(type: PaymentType | string): string {
    return PAYMENT[type as PaymentType]?.label ?? type;
}

/** A payment type's badge tone. Unknown types read as `outline` — present, unclaimed. */
export function paymentVariant(type: PaymentType | string): 'default' | 'secondary' | 'outline' {
    return PAYMENT[type as PaymentType]?.variant ?? 'outline';
}
