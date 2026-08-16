import EmptyState from '@/components/data/empty-state';
import { BranchScopeSelect } from '@/components/filters/branch-scope-select';
import FilterBar from '@/components/filters/filter-bar';
import MonthYearPicker from '@/components/filters/month-year-picker';
import LoginEventCalendar from '@/components/login-history/event-calendar';
import PageShell from '@/components/page/page-shell';
import { Alert } from '@/components/ui/alert';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useListFilters } from '@/hooks/use-list-filters';
import AppLayout from '@/layouts/app-layout';
import { ALL } from '@/lib/filters';
import { eventLabel } from '@/lib/login-events';
import type { BranchScope, BreadcrumbItem, LoginDay, LoginEventType, RosterBranch } from '@/types';
import { Head } from '@inertiajs/react';
import { History, WifiOff } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Login History', href: '/login-history' }];

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
        <Alert variant="warning">
            <WifiOff />
            <p>
                <span className="font-medium">These are this branch's sessions only.</span> Other branches' login history lives in the business
                database and this device is offline, so it could not be included. Reconnect to see login activity across branches.
            </p>
        </Alert>
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
    // Only the four values that belong in the URL. `filters` also carries the
    // month's label and the steppers' resolved destinations, which are the
    // server's answers rather than the reader's questions — sending them back
    // would put four dead parameters in the address bar.
    //
    // `month` and `year` have no default and so are always sent, which is what
    // stops stepping the month from falling back to today's on the next filter
    // change.
    const { apply } = useListFilters({
        url: route('login-history'),
        values: { event: filters.event, month: filters.month, year: filters.year, scope },
        defaults: { event: ALL, scope: LOCAL },
        only: RELOADED,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Login History" />
            <PageShell>
                <FilterBar>
                    {/* Which branches (M11.5). Admin+, and every scope but this
                        branch's own is online-only — a failed read snaps this
                        back to this branch and the notice below says why. */}
                    {canViewAll && (
                        <BranchScopeSelect scope={scope} branches={branches} onChange={(value) => apply({ scope: value })} label="Branches shown" />
                    )}
                    {/* No user picker: this screen is about the branch, and the
                        per-person question is /employee-performance. See
                        LoginHistoryController's docblock. */}
                    <Select value={filters.event ?? ALL} onValueChange={(value) => apply({ event: value })}>
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
                    <MonthYearPicker
                        month={filters.month}
                        year={filters.year}
                        years={years}
                        onChange={apply}
                        previous={{ month: filters.prev_month, year: filters.prev_year }}
                        next={{ month: filters.next_month, year: filters.next_year }}
                    />
                </FilterBar>

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
            </PageShell>
        </AppLayout>
    );
}
