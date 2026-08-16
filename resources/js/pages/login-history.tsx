import { BranchScopeSelect } from '@/components/branch-scope-select';
import EmptyState from '@/components/empty-state';
import LoginEventCalendar from '@/components/login-history/event-calendar';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { MONTHS } from '@/lib/calendar';
import { eventLabel } from '@/lib/login-events';
import type { BranchScope, BreadcrumbItem, LoginDay, LoginEventType, RosterBranch } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, History, WifiOff } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Login History', href: '/login-history' }];

const ALL = 'all';

// This branch's own sessions, from the local mirror — the default, and what a
// Cashier always gets. Any other value is read live from the business database.
const LOCAL = 'local';

// The props a filter, scope or month change reloads. Listed once because every
// control funnels through applyFilters(): a prop left out of this list silently
// freezes while the rest of the screen updates around it.
//
// `branches` and `years` are deliberately NOT in it. Neither changes with the
// filters, and re-sending them would make the server re-read the roster — over
// the network — on every step of the month.
const RELOADED = ['days', 'total', 'filters', 'scope', 'consolidatedOnline'];

/**
 * Shown when cross-branch sessions were asked for and the business database
 * could not be reached, so what is on screen is this branch's own history.
 *
 * The risk this guards against is a correct-looking calendar that covers fewer
 * branches than its heading claims, so the first line names what is on screen.
 */
function OfflineHistoryNotice() {
    return (
        <div role="alert" className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            <WifiOff className="mt-0.5 size-4 shrink-0" />
            <p>
                <span className="font-medium">These are this branch's sessions only.</span> Other branches' login history lives in the business
                database and this device is offline, so it could not be included. Reconnect to see login activity across branches.
            </p>
        </div>
    );
}

interface Filters {
    event: string | null;
    // The month on screen, echoed back after normalisation, with the ‹ ›
    // steppers' destinations already resolved server-side so a year rollover is
    // decided in one place.
    month: number;
    year: number;
    label: string;
    prev_month: number;
    prev_year: number;
    next_month: number;
    next_year: number;
}

export default function LoginHistory({
    days,
    total,
    canViewAll,
    eventTypes,
    filters,
    years,
    scope,
    branches,
    consolidatedOnline,
}: {
    // Every day of the selected month, each carrying its own events.
    days: LoginDay[];
    // The month's event count, across every day.
    total: number;
    canViewAll: boolean;
    eventTypes: LoginEventType[];
    filters: Filters;
    years: number[];
    // M11.5. What was actually read — a failed online read comes back as
    // 'local' with consolidatedOnline false, never as the scope asked for.
    scope: BranchScope;
    branches: RosterBranch[];
    // Null when nothing cross-branch was asked for; false only on a fallback.
    consolidatedOnline: boolean | null;
}) {
    const applyFilters = (next: Partial<Record<'event' | 'scope', string | null>> & { month?: number; year?: number }) => {
        const merged = { ...filters, scope, ...next };

        router.get(
            route('login-history'),
            {
                event: merged.event && merged.event !== ALL ? merged.event : undefined,
                // Always sent, so stepping the month cannot fall back to today's
                // on the next filter change.
                month: merged.month,
                year: merged.year,
                // Carried through every filter change, or narrowing by month
                // would silently drop the screen back to this branch.
                scope: merged.scope !== LOCAL ? merged.scope : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: RELOADED },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Login History" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    {/* Which branches (M11.5). Admin+, and every scope but this
                        branch's own is online-only — a failed read snaps this
                        back to this branch and the notice below says why. */}
                    {canViewAll && (
                        <BranchScopeSelect
                            scope={scope}
                            branches={branches}
                            onChange={(value) => applyFilters({ scope: value })}
                            label="Branches shown"
                        />
                    )}
                    {/* No user picker: this screen is about the branch, and the
                        per-person question is /employee-performance. See
                        LoginHistoryController's docblock. */}
                    <Select value={filters.event ?? ALL} onValueChange={(value) => applyFilters({ event: value })}>
                        <SelectTrigger className="w-44" aria-label="Event">
                            <SelectValue placeholder="All events" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All events</SelectItem>
                            {eventTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {eventLabel(type)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {/* The month, in the shape employee performance uses — the
                        calendar's window is a month, so a free date range would
                        be a control the grid could not honour. */}
                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Previous month"
                            onClick={() => applyFilters({ month: filters.prev_month, year: filters.prev_year })}
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <Select value={String(filters.month)} onValueChange={(value) => applyFilters({ month: Number(value) })}>
                            <SelectTrigger className="w-36" aria-label="Month">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {MONTHS.map((label, index) => (
                                    <SelectItem key={label} value={String(index + 1)}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={String(filters.year)} onValueChange={(value) => applyFilters({ year: Number(value) })}>
                            <SelectTrigger className="w-28" aria-label="Year">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {years.map((value) => (
                                    <SelectItem key={value} value={String(value)}>
                                        {value}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Next month"
                            onClick={() => applyFilters({ month: filters.next_month, year: filters.next_year })}
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                </div>

                {consolidatedOnline === false && <OfflineHistoryNotice />}

                {total === 0 ? (
                    <EmptyState
                        icon={History}
                        title={`No login events in ${filters.label}`}
                        description="Authentication events appear here as users sign in and out. Try another month, or widen the filters."
                    />
                ) : (
                    <>
                        {/* The count, above the grid: a month whose events sit in
                            three cells otherwise reads as an empty month. */}
                        <p className="text-muted-foreground text-sm">
                            {total} {total === 1 ? 'event' : 'events'} in {filters.label}. Select a day to see it in full.
                        </p>

                        <LoginEventCalendar days={days} canViewAll={canViewAll} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
