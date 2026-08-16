import { Breadcrumbs } from '@/components/layout/breadcrumbs';
import { SyncIndicator } from '@/components/sync/sync-indicator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        // One height, whatever the sidebar is doing. The starter kit shrank this
        // to h-12 in icon mode, which made collapsing the sidebar jolt the whole
        // page — and the sync button is two lines tall, so the smaller header had
        // no room left for it either.
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {/* Whether this till is behind, on every authenticated screen. WHICH
                till it is now lives in the sidebar footer, above the user — the
                two were reading as one crowded block in this corner. */}
            <div className="ml-auto flex items-center">
                <SyncIndicator />
            </div>
        </header>
    );
}
