import type { Branch } from '@/types';
import { useEffect, useState } from 'react';

/**
 * The branch roster, fetched when a dialog opens.
 *
 * Not a page prop, and deliberately: the roster lives in Postgres, and reading
 * it on every Inventory render would put a database connection behind a screen a
 * cashier uses all day. A dialog that never opens never asks for it.
 *
 * `here` is this device's own branch, which the batch dialogs use both as the
 * default target and as the test for whether a figure on screen is a live local
 * count or another branch's last synced baseline.
 *
 * A failure is not an error state: an offline device simply has no other
 * branches to offer, and adjusting this one still works. `loading` is what tells
 * "the roster has not arrived" apart from "the roster arrived and is empty" —
 * only the transfer dialog needs the distinction, because only it has nothing to
 * do when there is nowhere to send.
 */
export default function useBranchRoster(open: boolean): { branches: Branch[]; here: Branch | null; loading: boolean } {
    const [branches, setBranches] = useState<Branch[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;

        setLoading(true);

        fetch(route('stock-updates.branches'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload: { branches?: Branch[] }) => {
                if (!cancelled) {
                    setBranches(payload.branches ?? []);
                }
            })
            .catch(() => undefined)
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open]);

    return { branches, here: branches.find((branch) => branch.is_this_branch) ?? null, loading };
}
