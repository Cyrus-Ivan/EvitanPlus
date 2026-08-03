import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Which business and which branch this device is (M11.5).
 *
 * It sits next to {@link SyncIndicator} rather than in the sidebar because the
 * two answer one question together: a till that is behind, or frozen by the
 * version gate, is reported by branch — and the person reading the indicator is
 * usually the person who has to say which branch they are calling about. The
 * sidebar wordmark stays the PRODUCT name; this is the installation's name.
 *
 * The branch code earns its place over the branch name alone: it prefixes every
 * receipt serial this till prints, so it is the identifier that appears on
 * paper and the one the developer will ask for.
 */
export function BranchIdentity({ className }: { className?: string }) {
    const { identity } = usePage<SharedData>().props;

    // Null before the setup wizard has hydrated the device. Rendering nothing is
    // right: there is no branch to name yet, and the wizard says so itself.
    if (!identity) {
        return null;
    }

    const branch = identity.code && identity.branch ? `${identity.code} — ${identity.branch}` : (identity.code ?? identity.branch);

    return (
        <div className={cn('flex min-w-0 flex-col text-right leading-tight', className)}>
            {identity.business && <span className="truncate text-sm font-medium">{identity.business}</span>}
            {branch && <span className="text-muted-foreground truncate text-xs">{branch}</span>}
        </div>
    );
}
