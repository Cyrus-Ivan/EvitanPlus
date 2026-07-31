import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/react';
import {
    ChartColumn,
    DatabaseBackup,
    FileSpreadsheet,
    History,
    Package,
    PackageCheck,
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
            // Stock adjustments sent to OTHER branches, and what those branches
            // did with them (M8). Same role floor and same reason as above.
            { title: 'Stock Updates', url: '/inventory/stock-updates', icon: PackageCheck, minRole: 'admin' },
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
            { title: 'Database Backup', url: '/backup', icon: DatabaseBackup, permission: 'access-backup' },
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
                            <Link href="/pos" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={mainNavGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
