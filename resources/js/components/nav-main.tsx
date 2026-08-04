import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { type NavGroup, type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const page = usePage();
    const { can, hasRoleAtLeast } = usePermissions();

    // Match on pathname only so query strings (e.g. a pre-filtered
    // ?variant=… on Stock History) don't drop the active highlight.
    const currentPath = page.url.split('?')[0];

    // Gate every item: prefer the server-resolved ability (can), fall back to the
    // role floor for items without a matching ability, else always visible. Then
    // drop any group left empty.
    const isVisible = (item: NavItem): boolean => {
        if (item.permission) {
            return can(item.permission);
        }
        if (item.minRole) {
            return hasRoleAtLeast(item.minRole);
        }
        return true;
    };

    const visibleGroups = groups.map((group) => ({ ...group, items: group.items.filter(isVisible) })).filter((group) => group.items.length > 0);

    return (
        <>
            {visibleGroups.map((group) => (
                <SidebarGroup key={group.title} className="px-2 py-0">
                    <SidebarGroupLabel>{group.title}</SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton asChild isActive={item.url === currentPath}>
                                    {/* On mousedown, not on hover — and the
                                        difference is the page-loading bar.
                                        Inertia's `prefetch()` hides the one
                                        shared #nprogress element for the
                                        duration of the prefetch, and only a
                                        later visit un-hides it. Hover fires its
                                        prefetch 75ms AFTER the click it belongs
                                        to, so the hide landed on top of the
                                        navigation it should have been helping
                                        and the bar stayed invisible for the
                                        whole request. Mousedown puts the
                                        prefetch back in front of the visit,
                                        which un-hides on its way past. The
                                        request itself is unchanged — the visit
                                        adopts the in-flight prefetch either
                                        way — so this costs the hover head
                                        start and nothing else. */}
                                    <Link href={item.url} prefetch="click">
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
