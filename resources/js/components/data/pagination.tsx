import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PaginationLink } from '@/types';
import { Link } from '@inertiajs/react';

/**
 * Renders a Laravel paginator's `links` array as Inertia links. Each link
 * carries the full query string (the controller uses ->withQueryString()), so
 * active filters and search survive page changes. Preserves scroll/state so the
 * page does not jump on navigation.
 */
export default function Pagination({ links }: { links: PaginationLink[] }) {
    // Nothing to page through (single page => 3 links: prev, "1", next).
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center justify-end gap-1" aria-label="Pagination">
            {links.map((link, index) => {
                const className = cn(
                    buttonVariants({ variant: link.active ? 'default' : 'outline', size: 'sm' }),
                    !link.url && 'pointer-events-none opacity-50',
                );

                if (!link.url) {
                    return <span key={index} className={className} dangerouslySetInnerHTML={{ __html: link.label }} />;
                }

                return (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        preserveState
                        className={className}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                );
            })}
        </nav>
    );
}
