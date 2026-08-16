import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/**
 * The row of controls above a list.
 *
 * Two shells had grown side by side: a plain `flex flex-wrap items-center gap-2`
 * on the pages that only filter, and a `justify-between` version on the pages
 * that also have buttons on the right. They are the same bar — the second one
 * just has an `actions` group — so this renders one and lets `actions` decide.
 *
 * `gap-2` throughout, settling a `gap-3` that had appeared on `/reports` alone
 * and made its toolbar sit looser than every other page's.
 */
export default function FilterBar({ actions, className, children }: { actions?: ReactNode; className?: string; children: ReactNode }) {
    return (
        <div className={cn('flex flex-wrap items-center gap-2', actions && 'justify-between', className)}>
            {/* The filters are their own group so they wrap among themselves and
                the actions stay together on the right rather than being pulled
                apart by the wrap. */}
            <div className="flex flex-wrap items-center gap-2">{children}</div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );
}
