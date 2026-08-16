import Heading from '@/components/page/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';

/**
 * The section nav for the three business-settings screens, and a deliberate
 * sibling of `layouts/settings/layout.tsx` rather than a generalisation of it:
 * the two areas differ in every string and agree only on the shape, and the
 * profile area is starter-kit code this initiative has no reason to touch.
 *
 * Each section is a real URL. The alternative — one page holding the section in
 * component state and mirroring it into `location.hash` — writes to the history
 * entry Inertia keeps its own page state in, and clearing that state wedges the
 * router for the rest of the session.
 */
const sidebarNavItems: NavItem[] = [
    {
        title: 'Business',
        url: '/business-settings',
        icon: null,
    },
    {
        title: 'Receipt printing',
        url: '/business-settings/printing',
        icon: null,
    },
    {
        title: 'Report email',
        url: '/business-settings/report-email',
        icon: null,
    },
];

export default function BusinessSettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;

    return (
        <div className="px-4 py-6">
            <Heading title="Business Settings" description="Manage your business identity, receipt printer, and report email." />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {sidebarNavItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': currentPath === item.url,
                                })}
                            >
                                {/* "click" for the same reason as every other
                                    nav link — see `nav-main.tsx`. */}
                                <Link href={item.url} prefetch="click">
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
