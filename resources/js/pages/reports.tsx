import { BranchScopeSelect } from '@/components/branch-scope-select';
import EmptyState from '@/components/empty-state';
import EmailReportDialog, { type ReportEmailProp } from '@/components/reports/email-report-dialog';
import SalesBarChart from '@/components/reports/sales-bar-chart';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useCurrency } from '@/hooks/use-currency';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import type {
    BranchScope,
    BreadcrumbItem,
    ReportBranchRow,
    ReportChartBucket,
    ReportChartBy,
    ReportChartItem,
    ReportPeriod,
    ReportRow,
    ReportTotals,
    RosterBranch,
    SharedData,
} from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ChartColumn, ChevronLeft, ChevronRight, Mail, WifiOff } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/reports' }];

// The props the period/scope controls reload. Listed once because both the date
// steppers and the toggles ask for exactly the same set.
//
// `branches` is deliberately NOT in it. The roster arrives with every full page
// render now, and asking for it again would make the server re-read it — over
// the network — on every step of the date. The list is the same one either way,
// so the copy the client already holds stands.
const RELOAD_PROPS = ['period', 'rows', 'totals', 'chartItems', 'chart', 'filters', 'scope', 'chartBy', 'branchRows', 'consolidatedOnline'];

interface Filters {
    period: ReportPeriod;
    // Window-start anchor (what the date picker shows) and the prev/next anchors
    // the ‹ › steppers navigate to. `label` is the human name of the period.
    date: string;
    prev: string;
    next: string;
    label: string;
}

// Sub-bucket key → table row label, matching the server's drill-down granularity:
// daily 'YYYY-MM-DD HH' (hour), weekly/monthly 'YYYY-MM-DD' (day), yearly
// 'YYYY-MM' (month).
function formatBucket(bucket: string, period: ReportPeriod): string {
    if (period === 'daily') {
        const date = new Date(`${bucket.slice(0, 10)}T${bucket.slice(11, 13)}:00:00`);
        return Number.isNaN(date.getTime()) ? bucket : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }
    if (period === 'yearly') {
        const date = new Date(`${bucket}-01`);
        return Number.isNaN(date.getTime()) ? bucket : date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' });
    }
    const date = new Date(bucket);
    return Number.isNaN(date.getTime()) ? bucket : date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

// Singular noun for the sub-bucket (drill-down) granularity, used in the chart
// caption and the breakdown table's first column header.
const SUB_NOUN: Record<ReportPeriod, string> = {
    daily: 'hour',
    weekly: 'day',
    monthly: 'day',
    yearly: 'month',
};

/**
 * Shown when cross-branch figures were asked for and the business database could
 * not be reached, so what is on screen is this branch's own report.
 *
 * Deliberately NOT `OfflineNotice`: that one is about a screen whose CHANGES
 * need a connection, and its wording says so. Nothing here is being changed —
 * the risk is the opposite one, of a correct-looking report that covers fewer
 * branches than its heading claims. So the first line names what is on screen.
 */
function OfflineReportNotice() {
    return (
        <div role="alert" className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            <WifiOff className="mt-0.5 size-4 shrink-0" />
            <p>
                <span className="font-medium">These are this branch's figures only.</span> Other branches' sales live in the business database and
                this device is offline, so they could not be included. Selling is unaffected — reconnect to report across branches.
            </p>
        </div>
    );
}

function StatCard({ label, value, tone }: { label: string; value: string; tone?: 'net' | 'negative' }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</div>
                <div
                    className={
                        'mt-1 text-2xl font-semibold tabular-nums ' +
                        (tone === 'net' ? 'text-foreground' : tone === 'negative' ? 'text-muted-foreground' : 'text-foreground')
                    }
                >
                    {value}
                </div>
            </CardContent>
        </Card>
    );
}

export default function Reports({
    period,
    rows,
    totals,
    chartItems,
    chart,
    canViewCost,
    filters,
    scope,
    chartBy,
    branches,
    branchRows,
    consolidatedOnline,
    email,
}: {
    period: ReportPeriod;
    rows: ReportRow[];
    totals: ReportTotals;
    chartItems: ReportChartItem[];
    chart: ReportChartBucket[];
    canViewCost: boolean;
    filters: Filters;
    // M11. `scope` is what was actually reported on — a failed online read comes
    // back as 'local' with consolidatedOnline false, never as the scope asked for.
    scope: BranchScope;
    chartBy: ReportChartBy;
    branches: RosterBranch[];
    branchRows: ReportBranchRow[] | null;
    // Null when nothing consolidated was asked for; false when it was and the
    // business database could not be reached.
    consolidatedOnline: boolean | null;
    email: ReportEmailProp;
}) {
    const [emailOpen, setEmailOpen] = useState(false);
    const { format: money } = useCurrency();
    const { can } = usePermissions();
    const { sync } = usePage<SharedData>().props;

    const canConsolidate = can('view-consolidated-reports');
    const consolidated = scope !== 'local';
    // Sales rung up here that have not reached the business database yet, so are
    // not in figures that came FROM it. Only worth saying when consolidated.
    const unsynced = consolidated ? (sync?.unsynced_count ?? 0) : 0;

    const reload = (params: Record<string, string>) => {
        router.get(
            route('reports'),
            // Carry the scope through every navigation, or stepping a day would
            // silently drop the Owner back to this branch's figures.
            { ...(consolidated ? { scope, by: chartBy } : {}), ...params },
            { preserveState: true, preserveScroll: true, replace: true, only: RELOAD_PROPS },
        );
    };

    // Load the report for the same granularity anchored on a different date (the
    // ‹ › steppers pass filters.prev / filters.next; the picker passes its value).
    const goToDate = (date: string) => {
        if (!date) return;
        reload({ period, date });
    };

    // Switching granularity resets to the LATEST period of that kind (today /
    // this week / this month / this year) — sending no date lets the server
    // default the anchor to now.
    const switchPeriod = (next: string) => {
        if (!next || next === period) return;
        reload({ period: next });
    };

    // Switching which branches are reported on keeps the period and the date:
    // the whole point is comparing the same window from a different angle.
    const switchScope = (next: string) => {
        if (!next || next === scope) return;
        reload({ period, date: filters.date, scope: next });
    };

    const switchChartBy = (next: string) => {
        if (!next || next === chartBy) return;
        reload({ period, date: filters.date, scope, by: next });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reports" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                {/* Controls: granularity toggle + single-period selector + PDF export. */}
                <div className="flex flex-wrap items-center gap-3">
                    <ToggleGroup type="single" variant="outline" value={period} onValueChange={switchPeriod}>
                        <ToggleGroupItem value="daily" aria-label="Daily sales">
                            Daily
                        </ToggleGroupItem>
                        <ToggleGroupItem value="weekly" aria-label="Weekly sales">
                            Weekly
                        </ToggleGroupItem>
                        <ToggleGroupItem value="monthly" aria-label="Monthly sales">
                            Monthly
                        </ToggleGroupItem>
                        <ToggleGroupItem value="yearly" aria-label="Yearly sales">
                            Yearly
                        </ToggleGroupItem>
                    </ToggleGroup>

                    {/* Prev / date picker / next — pick the single period to report on. */}
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" onClick={() => goToDate(filters.prev)} aria-label="Previous period">
                            <ChevronLeft className="size-4" />
                        </Button>
                        <Input
                            type="date"
                            value={filters.date}
                            onChange={(e) => goToDate(e.target.value)}
                            className="w-40"
                            aria-label="Report date"
                        />
                        <Button variant="outline" size="icon" onClick={() => goToDate(filters.next)} aria-label="Next period">
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                    <span className="text-sm font-medium">{filters.label}</span>

                    {/* Which branches (M11). Owner-only, and online-only — the
                        server refuses anything but 'local' for anyone else. */}
                    {canConsolidate && <BranchScopeSelect scope={scope} branches={branches} onChange={switchScope} label="Branches reported on" />}

                    {/* Enabled even when email is not set up yet: a disabled
                        button with no explanation is the worse failure, and the
                        dialog is where the explanation lives. */}
                    <Button variant="outline" className="ml-auto" onClick={() => setEmailOpen(true)}>
                        <Mail className="size-4" />
                        Email report
                    </Button>
                </div>

                <EmailReportDialog
                    open={emailOpen}
                    onOpenChange={setEmailOpen}
                    email={email}
                    defaultPeriod={period}
                    defaultDate={filters.date}
                    defaultScope={scope}
                    chartBy={chartBy}
                    branches={branches}
                    canConsolidate={canConsolidate}
                />

                {/* The consolidated scope was asked for and could not be served,
                    so these are this branch's figures. Say so before the numbers. */}
                {consolidatedOnline === false && <OfflineReportNotice />}

                {/* Summary cards — recomputed net = gross − returns − defects. */}
                <div className={'grid gap-3 sm:grid-cols-2 ' + (canViewCost ? 'lg:grid-cols-6' : 'lg:grid-cols-4')}>
                    <StatCard label="Gross sales" value={money(totals.gross_sales)} />
                    <StatCard label="Returns" value={money(totals.returns)} tone="negative" />
                    <StatCard label="Defects" value={money(totals.defects)} tone="negative" />
                    <StatCard label="Net sales" value={money(totals.net_sales)} tone="net" />
                    {canViewCost && <StatCard label="Gross profit" value={money(totals.profit ?? '0')} tone="net" />}
                    {canViewCost && <StatCard label="Uncosted sales" value={money(totals.uncosted_sales ?? '0')} tone="negative" />}
                </div>

                {/* Consolidated figures are correct for what reached the business
                    database — which is not yet everything this till has rung up.

                    Hedged on purpose: the outbox also carries logins and audit
                    rows, so a backlog of 1 may well affect nothing here. Telling
                    the Owner their figures are short when they are not is the
                    same error as not telling them when they are. */}
                {unsynced > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {unsynced === 1 ? '1 change on this device is' : `${unsynced} changes on this device are`} still waiting to sync. Any sales or
                        returns among them are not counted above yet.
                    </p>
                )}

                {rows.length === 0 ? (
                    <EmptyState
                        icon={ChartColumn}
                        title="No sales in this period"
                        description="Pick a different period, or ring up a sale to see it reported here."
                    />
                ) : (
                    <>
                        {/* Net sales over time, stacked by item or by branch. */}
                        <Card>
                            <CardContent className="p-4">
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div className="text-muted-foreground text-sm font-medium">
                                        Net sales by {chartBy === 'branch' ? 'branch' : 'item'}, per {SUB_NOUN[period]}
                                    </div>
                                    {/* Only consolidated figures carry a branch to
                                        stack by; locally there is just this one. */}
                                    {consolidated && (
                                        <ToggleGroup type="single" variant="outline" size="sm" value={chartBy} onValueChange={switchChartBy}>
                                            <ToggleGroupItem value="item" aria-label="Stack the chart by item">
                                                By item
                                            </ToggleGroupItem>
                                            <ToggleGroupItem value="branch" aria-label="Stack the chart by branch">
                                                By branch
                                            </ToggleGroupItem>
                                        </ToggleGroup>
                                    )}
                                </div>
                                <SalesBarChart chart={chart} items={chartItems} period={period} />
                            </CardContent>
                        </Card>

                        {/* Breakdown table. */}
                        <div className="rounded-xl border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="capitalize">{SUB_NOUN[period]}</TableHead>
                                        <TableHead className="text-right">Txns</TableHead>
                                        <TableHead className="text-right">Gross</TableHead>
                                        <TableHead className="text-right">Returns</TableHead>
                                        <TableHead className="text-right">Defects</TableHead>
                                        <TableHead className="text-right">Net</TableHead>
                                        {canViewCost && <TableHead className="text-right">Uncosted</TableHead>}
                                        {canViewCost && <TableHead className="text-right">Profit</TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.bucket}>
                                            <TableCell className="font-medium">{formatBucket(row.bucket, period)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{row.transactions}</TableCell>
                                            <TableCell className="text-right tabular-nums">{money(row.gross_sales)}</TableCell>
                                            <TableCell className="text-muted-foreground text-right tabular-nums">
                                                {Number(row.returns) > 0 ? `−${money(row.returns)}` : money(row.returns)}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-right tabular-nums">
                                                {Number(row.defects) > 0 ? `−${money(row.defects)}` : money(row.defects)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">{money(row.net_sales)}</TableCell>
                                            {canViewCost && (
                                                <TableCell className="text-muted-foreground text-right tabular-nums">
                                                    {money(row.uncosted_sales ?? '0')}
                                                </TableCell>
                                            )}
                                            {canViewCost && <TableCell className="text-right tabular-nums">{money(row.profit ?? '0')}</TableCell>}
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="font-medium">Total</TableCell>
                                        <TableCell className="text-right tabular-nums">{totals.transactions}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(totals.gross_sales)}</TableCell>
                                        <TableCell className="text-muted-foreground text-right tabular-nums">
                                            {Number(totals.returns) > 0 ? `−${money(totals.returns)}` : money(totals.returns)}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-right tabular-nums">
                                            {Number(totals.defects) > 0 ? `−${money(totals.defects)}` : money(totals.defects)}
                                        </TableCell>
                                        <TableCell className="text-right font-semibold tabular-nums">{money(totals.net_sales)}</TableCell>
                                        {canViewCost && (
                                            <TableCell className="text-muted-foreground text-right font-semibold tabular-nums">
                                                {money(totals.uncosted_sales ?? '0')}
                                            </TableCell>
                                        )}
                                        {canViewCost && (
                                            <TableCell className="text-right font-semibold tabular-nums">{money(totals.profit ?? '0')}</TableCell>
                                        )}
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>

                        {/* The same period, cut by branch instead of by time (M11).
                            Same recompute, different GROUP BY, so it foots to the
                            table above. A branch with no sales shows zeros. */}
                        {branchRows !== null && branchRows.length > 0 && (
                            <div className="rounded-xl border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Branch</TableHead>
                                            <TableHead className="text-right">Txns</TableHead>
                                            <TableHead className="text-right">Gross</TableHead>
                                            <TableHead className="text-right">Returns</TableHead>
                                            <TableHead className="text-right">Defects</TableHead>
                                            <TableHead className="text-right">Net</TableHead>
                                            {canViewCost && <TableHead className="text-right">Uncosted</TableHead>}
                                            {canViewCost && <TableHead className="text-right">Profit</TableHead>}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {branchRows.map((row) => (
                                            <TableRow key={row.branch_id}>
                                                <TableCell className="font-medium">
                                                    {row.code} <span className="text-muted-foreground font-normal">{row.name}</span>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{row.transactions}</TableCell>
                                                <TableCell className="text-right tabular-nums">{money(row.gross_sales)}</TableCell>
                                                <TableCell className="text-muted-foreground text-right tabular-nums">
                                                    {Number(row.returns) > 0 ? `−${money(row.returns)}` : money(row.returns)}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-right tabular-nums">
                                                    {Number(row.defects) > 0 ? `−${money(row.defects)}` : money(row.defects)}
                                                </TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">{money(row.net_sales)}</TableCell>
                                                {canViewCost && (
                                                    <TableCell className="text-muted-foreground text-right tabular-nums">
                                                        {money(row.uncosted_sales ?? '0')}
                                                    </TableCell>
                                                )}
                                                {canViewCost && <TableCell className="text-right tabular-nums">{money(row.profit ?? '0')}</TableCell>}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
