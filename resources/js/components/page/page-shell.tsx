import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/**
 * The body of a page inside `AppLayout` — the container eleven pages had spelled
 * out identically.
 *
 * `min-h-full flex-1` is what lets a page's empty state fill the window rather
 * than sitting in a short box at the top, and it is the part most easily dropped
 * when a new page is copied from an old one.
 */
export default function PageShell({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('flex min-h-full flex-1 flex-col gap-4 p-4', className)}>{children}</div>;
}

/**
 * A page's own title, description and actions.
 *
 * Most list pages in this app deliberately have NO heading: the breadcrumb bar
 * above them already names the page, and a title repeating it would be the same
 * word twice down the left edge of the screen. This is for the pages that need
 * to say more than their name — `/inventory/stock-updates` explains what a
 * pending row is before showing any.
 *
 * `h1` at `text-lg`, matching what the one page with a heading already used.
 * `components/heading.tsx` (h2/text-xl) and `heading-small.tsx` (h3/text-base)
 * are the SETTINGS scales and stay theirs — those pages sit inside a layout that
 * has already opened with an h1.
 */
export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 className="text-lg font-semibold">{title}</h1>
                {description && <p className="text-muted-foreground text-sm">{description}</p>}
            </div>
            {actions}
        </div>
    );
}
