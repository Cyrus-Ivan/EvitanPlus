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
 */
export default function useBranchRoster(open: boolean): { branches: Branch[]; here: Branch | null } {
    const [branches, setBranches] = useState<Branch[]>([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;

        fetch(route('stock-updates.branches'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload: { branches?: Branch[] }) => {
                if (!cancelled) {
                    setBranches(payload.branches ?? []);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [open]);

    return { branches, here: branches.find((branch) => branch.is_this_branch) ?? null };
}
