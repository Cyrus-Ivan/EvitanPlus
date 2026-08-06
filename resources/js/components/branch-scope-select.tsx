import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { BranchScope, RosterBranch, SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

// This device's own rows, from the local SQLite mirror — the default, and the
// only scope that works offline.
const LOCAL = 'local';

const ALL = 'all';

/**
 * Which branches a screen covers (`docs/glossary.md` §"Branch scope") — reports
 * since M11, login history since M11.5, one control since both wanted the same
 * two corrections.
 *
 * The list is `this branch | all branches | every OTHER branch`. This branch is
 * named rather than labelled "This branch" so every entry reads the same way and
 * the picker says which branch you are standing at; it appears exactly once,
 * because the server strips its id from the roster and snaps `?scope=<own id>`
 * back to `local` (see `App\Http\Controllers\Concerns\OffersBranchScope`).
 *
 * `allowAll` drops the middle entry, and Inventory is the one screen that does:
 * a wider scope there lands on a stock *number* rather than on a list of rows,
 * and a business-wide total per variant cannot be read without a per-branch
 * breakdown printed under it. `App\Http\Controllers\Inventory\ItemController`
 * snaps `?scope=all` back to `local` to match, so the option cannot return by
 * URL.
 *
 * `branches` is empty when the business database could not be reached — the
 * other branches' rows live only there — so the content says so instead of
 * leaving a two-entry list looking broken.
 */
export function BranchScopeSelect({
    scope,
    branches,
    onChange,
    label,
    allowAll = true,
}: {
    scope: BranchScope;
    branches: RosterBranch[];
    onChange: (scope: string) => void;
    // What the control is choosing between on this screen, for screen readers:
    // "Branches shown", "Branches reported on".
    label: string;
    allowAll?: boolean;
}) {
    const { identity } = usePage<SharedData>().props;

    // Null before the setup wizard has hydrated the device, and on a build with
    // no branch identity at all — there is no branch to name, so say the plain
    // thing instead.
    const here = identity?.code && identity?.branch ? `${identity.code} — ${identity.branch} (this branch)` : 'This branch';

    return (
        <Select value={scope} onValueChange={onChange}>
            <SelectTrigger className="w-64" aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={LOCAL}>{here}</SelectItem>
                {allowAll && <SelectItem value={ALL}>All branches</SelectItem>}
                {branches.map((branch) => (
                    <SelectItem key={branch.id} value={branch.id}>
                        {branch.code} — {branch.name}
                    </SelectItem>
                ))}
                {branches.length === 0 && (
                    <p className="text-muted-foreground px-2 py-1.5 text-xs">Connect to the business database to pick another branch.</p>
                )}
            </SelectContent>
        </Select>
    );
}
