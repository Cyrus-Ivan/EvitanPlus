import { BranchIdentity } from '@/components/branch-identity';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/react';
import {
    ChartColumn,
    FileSpreadsheet,
    History,
    Package,
    PackageSearch,
    ReceiptText,
    ScrollText,
    ShoppingCart,
    Store,
    UsersRound,
} from 'lucide-react';
import AppLogo from './app-logo';

// Full navigation surface, grouped by function. Each item carries the minimum
// role required to see it (omitted = Cashier-level, visible to everyone).
// NavMain filters these against the current user's role (see use-permissions).
const mainNavGroups: NavGroup[] = [
    {
        title: 'Operations',
        items: [
            { title: 'Point of Sale', url: '/pos', icon: ShoppingCart },
            { title: 'Sales', url: '/sales', icon: ReceiptText },
            { title: 'Inventory', url: '/inventory', icon: Package }, // view-only for Cashier
            { title: 'Stock History', url: '/inventory/stock-history', icon: PackageSearch }, // read-only ledger
            // No dedicated ability backs the import/export routes, so this one
            // gates by role floor; the routes are `role:admin` server-side.
            { title: 'Import / Export', url: '/inventory/import', icon: FileSpreadsheet, minRole: 'admin' },
            // No entry for pending stock adjustments: they are a dialog on Stock
            // History, because a queue row is a stock movement that has not
            // happened yet and belongs on top of the history it is about to join.
        ],
    },
    {
        title: 'Insights',
        items: [
            { title: 'Reports', url: '/reports', icon: ChartColumn, permission: 'access-reports' },
            { title: 'Login History', url: '/login-history', icon: History }, // Cashier sees own
            { title: 'Audit Logs', url: '/audit-logs', icon: ScrollText, permission: 'access-audit-logs' },
        ],
    },
    {
        title: 'Administration',
        items: [
            { title: 'User Management', url: '/users', icon: UsersRound, permission: 'manage-users' },
            { title: 'Business Settings', url: '/business-settings', icon: Store, permission: 'access-settings' },
        ],
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            {/* "click" for the same reason as every other nav
                                link — see `nav-main.tsx`. */}
                            <Link href="/pos" prefetch="click">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={mainNavGroups} />
            </SidebarContent>

            {/* Who is signed in, and which installation this is — one recessed
                panel, because they are read together and neither is navigation.
                The tint is plain black at low alpha rather than a theme token:
                `--muted` and `--sidebar-background` are within 0.1% of each
                other in dark mode, so a token would sink into the sidebar
                exactly where the effect is wanted. The inset shadow is the
                sunken cue; the border only stops it floating.
                The user sits ON the well rather than in it: same `bg-sidebar` as
                the surface it was lifted out of, plus a drop shadow. That is
                what separates it from the identity beneath, so no rule is drawn
                between them.
                In icon mode the panel takes itself apart — a box drawn around a
                lone avatar in a 3rem rail is worse than no box. The dark tint
                and shadow need their own icon-mode resets: a bare
                `group-data-[collapsible=icon]:` class does not outrank a `dark:`
                one, so the well survived collapsing in dark mode, invisibly. */}
            <SidebarFooter>
                <div className="border-sidebar-border/60 rounded-lg border bg-black/[0.03] p-2 shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0 group-data-[collapsible=icon]:shadow-none dark:bg-black/25 dark:shadow-[inset_0_1px_3px_rgb(0_0_0/0.5)] dark:group-data-[collapsible=icon]:bg-transparent dark:group-data-[collapsible=icon]:shadow-none">
                    <NavUser className="bg-sidebar shadow-sm group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:shadow-none" />
                    <BranchIdentity className="px-2 pt-3 pb-1 group-data-[collapsible=icon]:hidden" />
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
