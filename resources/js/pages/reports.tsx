import EmptyState from '@/components/empty-state';
import SalesBarChart from '@/components/reports/sales-bar-chart';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useCurrency } from '@/hooks/use-currency';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, ReportChartBucket, ReportChartItem, ReportPeriod, ReportRow, ReportTotals } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChartColumn, ChevronLeft, ChevronRight, FileDown } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/reports' }];

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
}: {
    period: ReportPeriod;
    rows: ReportRow[];
    totals: ReportTotals;
    chartItems: ReportChartItem[];
    chart: ReportChartBucket[];
    canViewCost: boolean;
    filters: Filters;
}) {
    const { format: money } = useCurrency();

    // Load the report for the same granularity anchored on a different date (the
    // ‹ › steppers pass filters.prev / filters.next; the picker passes its value).
    const goToDate = (date: string) => {
        if (!date) return;
        router.get(
            route('reports'),
            { period, date },
            { preserveState: true, preserveScroll: true, replace: true, only: ['period', 'rows', 'totals', 'chartItems', 'chart', 'filters'] },
        );
    };

    // Switching granularity resets to the LATEST period of that kind (today /
    // this week / this month / this year) — sending no date lets the server
    // default the anchor to now.
    const switchPeriod = (next: string) => {
        if (!next || next === period) return;
        router.get(
            route('reports'),
            { period: next },
            { preserveState: true, preserveScroll: true, replace: true, only: ['period', 'rows', 'totals', 'chartItems', 'chart', 'filters'] },
        );
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

                    <Button variant="outline" asChild className="ml-auto">
                        <a href={route('reports.export', { period, date: filters.date })}>
                            <FileDown className="size-4" />
                            Export PDF
                        </a>
                    </Button>
                </div>

                {/* Summary cards — recomputed net = gross − returns − defects. */}
                <div className={'grid gap-3 sm:grid-cols-2 ' + (canViewCost ? 'lg:grid-cols-6' : 'lg:grid-cols-4')}>
                    <StatCard label="Gross sales" value={money(totals.gross_sales)} />
                    <StatCard label="Returns" value={money(totals.returns)} tone="negative" />
                    <StatCard label="Defects" value={money(totals.defects)} tone="negative" />
                    <StatCard label="Net sales" value={money(totals.net_sales)} tone="net" />
                    {canViewCost && <StatCard label="Gross profit" value={money(totals.profit ?? '0')} tone="net" />}
                    {canViewCost && <StatCard label="Uncosted sales" value={money(totals.uncosted_sales ?? '0')} tone="negative" />}
                </div>

                {rows.length === 0 ? (
                    <EmptyState
                        icon={ChartColumn}
                        title="No sales in this period"
                        description="Pick a different period, or ring up a sale to see it reported here."
                    />
                ) : (
                    <>
                        {/* Net sales over time, stacked by item. */}
                        <Card>
                            <CardContent className="p-4">
                                <div className="text-muted-foreground mb-3 text-sm font-medium">Net sales by item, per {SUB_NOUN[period]}</div>
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
                    </>
                )}
            </div>
        </AppLayout>
    );
}
