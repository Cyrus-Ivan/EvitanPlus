import type { StockLedgerEntry } from '@/types';

/**
 * What a ledger row is called on screen — the frontend twin of
 * `App\Support\Inventory\MovementKind`, which decides which rows come back.
 *
 * A row says what happened across three columns (`type`, `sale_type`,
 * `reference_type`) and no one of them is the answer: `type` alone reads
 * "Adjustment" for a recount, a CSV import, another branch's request and both
 * legs of a transfer alike — every non-sale row there is. Adding a kind means
 * adding it here AND in `MovementKind`.
 */

/** Static labels, for anywhere a kind is named without a row in hand. */
export const MOVEMENT_LABEL: Record<string, string> = {
    sale: 'Sale',
    return: 'Return',
    defect: 'Defect',
    manual_adjustment: 'Adjustment',
    csv_import: 'CSV import',
    transfer: 'Transfer',
};

/**
 * The label for one row.
 *
 * A transfer is one kind and two labels: the sign says which end of it this
 * branch is (docs/glossary.md §4 — the counterparty column is deliberately not a
 * direction), and "Transfer" alone would leave stock leaving and stock arriving
 * looking identical.
 */
export function movementLabel(entry: StockLedgerEntry): string {
    if (entry.type === 'sale') {
        return MOVEMENT_LABEL[entry.sale_type ?? ''] ?? entry.sale_type ?? 'Sale';
    }

    // A refusal (M11.8): somebody was asked for stock to move and said it did
    // not. It is written to the ledger — as a movement of nothing — so the branch
    // that asked can read the outcome, which no other record could carry to them.
    // This branch has to come FIRST: zero is not negative, so the transfer rule
    // below would call every refused outgoing leg a "Transfer in".
    if (entry.quantity === 0) {
        return 'Turned down';
    }

    if (entry.reference_type === 'transfer') {
        return entry.quantity < 0 ? 'Transfer out' : 'Transfer in';
    }

    return MOVEMENT_LABEL[entry.reference_type] ?? 'Adjustment';
}

/** Adjustments read as filled, sales as outlined — unchanged from Phase 6. */
export function movementVariant(entry: StockLedgerEntry): 'secondary' | 'outline' {
    return entry.type === 'stock_adjustment' ? 'secondary' : 'outline';
}
