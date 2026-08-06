import { BranchIdentity } from '@/components/branch-identity';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/react';
import {
    ChartColumn,
    ClipboardCheck,
    FileSpreadsheet,
    History,
    Package,
    PackageSearch,
    ReceiptText,
    ScrollText,
    ShoppingCart,
    Store,
    Tags,
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
        ],
    },
    // Its own group rather than five entries under Operations: the catalog, the
    // ledger behind it and the queue in front of it are one job, and selling is
    // another.
    {
        title: 'Inventory Management',
        items: [
            // No dedicated ability backs categories either. Admin-gated to match
            // what every action on that page needs — the route itself is
            // Cashier-readable, but a screen whose every button is disabled is
            // not worth a nav entry.
            { title: 'Categories', url: '/inventory/categories', icon: Tags, minRole: 'admin' },
            { title: 'Inventory', url: '/inventory', icon: Package }, // view-only for Cashier
            // No dedicated ability backs the import/export routes, so this one
            // gates by role floor; the routes are `role:admin` server-side.
            { title: 'Import / Export', url: '/inventory/import', icon: FileSpreadsheet, minRole: 'admin' },
            // Ungated ON PURPOSE, unlike everything else that touches stock:
            // answering one is reporting what physically happened, and the
            // person who knows is whoever is in the shop. See routes/web.php.
            // "Pending Stocks" is the page's own name (M11.8). The rows on it
            // are still *pending stock adjustments* — that is the term of art
            // (docs/glossary.md) and it outranks the label on a screen.
            { title: 'Pending Stocks', url: '/inventory/stock-updates', icon: ClipboardCheck },
            { title: 'Stock History', url: '/inventory/stock-history', icon: PackageSearch }, // read-only ledger
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

            {/* Which installation this is, and who is signed in — one panel,
                because they are read together and neither is navigation. It sits
                at the main content's own level rather than pressed into the
                rail: `bg-background rounded-xl shadow-sm` and NO border is
                exactly what `SidebarInset` gives the card across the gutter, so
                the footer is lifted off the sidebar by the same amount and by
                the same means — a border here would be a fifth edge the main
                container does not draw, and the two would stop matching.
                Every class here is a theme token, so nothing needs a
                `dark:` twin — which is also what retires an old trap, where a
                bare `group-data-[collapsible=icon]:` reset could not outrank a
                `dark:` one and the panel survived collapsing in dark mode,
                invisibly.
                The user chip is one step above the panel, and borrows the sync
                button's treatment on purpose (`border-input` + `shadow-sm` over
                the same fill as its parent, see `sync-indicator.tsx`): the two
                raised controls on screen should look raised the same way. The
                identity above it stays plain text — it is a standing label, and
                a border would promise a click that is not there.
                In icon mode the panel takes itself apart — a box drawn around a
                lone avatar in a 3rem rail is worse than no box. */}
            <SidebarFooter>
                <div className="bg-background rounded-xl p-2 shadow-sm group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0 group-data-[collapsible=icon]:shadow-none">
                    <BranchIdentity className="px-2 pt-1 pb-3 group-data-[collapsible=icon]:hidden" />
                    <NavUser className="border-input bg-background border shadow-sm group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:shadow-none" />
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
