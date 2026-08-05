import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Which business and which branch this device is (M11.5).
 *
 * Two homes, because there are two shells. Signed in, it sits in the sidebar
 * footer above the user: an installation's name is the same standing fact as
 * whose account is open, and neither changes as you move around the app — in
 * the header it competed with {@link SyncIndicator} for one corner and read as
 * clutter. On the login screen there is no sidebar, so it keeps its place beside
 * the indicator: a device you cannot log into is exactly when you need to know
 * which branch it is. The sidebar wordmark stays the PRODUCT name; this is the
 * installation's name.
 *
 * The branch code earns its place over the branch name alone: it prefixes every
 * receipt serial this till prints, so it is the identifier that appears on
 * paper and the one the developer will ask for.
 *
 * Left-aligned by default, which is what the sidebar wants; the login screen
 * passes `text-right` for its corner.
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
        <div className={cn('flex min-w-0 flex-col leading-tight', className)}>
            {identity.business && <span className="truncate text-sm font-medium">{identity.business}</span>}
            {branch && <span className="text-muted-foreground truncate text-xs">{branch}</span>}
        </div>
    );
}
