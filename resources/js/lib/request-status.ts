/**
 * How an answered stock request is toned — the third label module, after
 * `lib/stock-movement.ts` and `lib/login-events.ts`.
 *
 * The three words are the ones `inventory_update_queue.status` holds (M11.7).
 * Only the TONE lives here, not the whole label: an approval says how much was
 * approved (`Approved (+3)`, `Partly approved (+2 of +3)`), and those quantities
 * belong to the leg the caller is holding, not to the status word. Composing
 * them here would mean passing the leg in, which is the call site with extra
 * steps.
 *
 * "accepted" covers a partial approval too — a leg that moved *some* stock was
 * answered yes, and the shortfall is a quantity, not a fourth status.
 */
export type RequestStatus = 'pending' | 'accepted' | 'rejected';

/**
 * A status's badge tone.
 *
 * A refusal is the only destructive one: it is the outcome that leaves the
 * asking branch's stock where it was, and the one a person scanning a card needs
 * to catch without reading. Pending is `outline` — present, unanswered.
 */
export function requestStatusVariant(status: RequestStatus): 'secondary' | 'destructive' | 'outline' {
    if (status === 'rejected') return 'destructive';

    return status === 'pending' ? 'outline' : 'secondary';
}
