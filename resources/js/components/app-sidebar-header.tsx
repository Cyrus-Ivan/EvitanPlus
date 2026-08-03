import { BranchIdentity } from '@/components/branch-identity';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SyncIndicator } from '@/components/sync-indicator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {/* On every authenticated screen: which branch this is, and whether
                it is behind. Neither is a settings-page question, and they are
                read together — a till reports itself by branch. */}
            <div className="ml-auto flex min-w-0 items-center gap-3">
                <BranchIdentity className="hidden sm:flex" />
                <SyncIndicator />
            </div>
        </header>
    );
}
