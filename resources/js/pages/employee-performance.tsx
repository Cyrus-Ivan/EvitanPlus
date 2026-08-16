import EmptyState from '@/components/data/empty-state';
import StatCard from '@/components/data/stat-card';
import FilterBar from '@/components/filters/filter-bar';
import MonthYearPicker from '@/components/filters/month-year-picker';
import PageShell from '@/components/page/page-shell';
import AttendanceCalendar from '@/components/reports/attendance-calendar';
import { type ReportEmailProp } from '@/components/reports/email-report-dialog';
import EmployeeReportDialog from '@/components/reports/employee-report-dialog';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useCurrency } from '@/hooks/use-currency';
import AppLayout from '@/layouts/app-layout';
import { formatHours } from '@/lib/format';
import type { BreadcrumbItem, EmployeeDay, EmployeeOption, EmployeeSummary } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Mail, UsersRound, WifiOff } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Employee Performance', href: '/employee-performance' }];

// The props a control change reloads. `employees`, `years` and `email` are
// deliberately NOT in it: they do not change with the month or the employee, and
// re-sending them on every step of the month is pure weight.
const RELOAD_PROPS = ['employee', 'days', 'summary', 'filters', 'online'];

interface Filters {
    month: number;
    year: number;
    label: string;
    prev_month: number;
    prev_year: number;
    next_month: number;
    next_year: number;
}

/**
 * Shown INSTEAD of the month when the business database cannot be reached.
 *
 * There is deliberately nothing behind it. This report always covers the whole
 * business, because a person is not a branch — somebody signs in at Manila on
 * Monday and Cebu on Tuesday — so the local mirror would not be a smaller answer
 * but a wrong one: a correct-looking calendar saying an employee posted elsewhere
 * never came in all month.
 */
function OfflineNotice() {
    return (
        <Alert variant="warning">
            <WifiOff />
            <p>
                <span className="font-medium">This report is read from the business database.</span> It covers every branch, because an employee can
                work at more than one — so there is no partial version of it to show while this device is offline. Reconnect and it will load.
            </p>
        </Alert>
    );
}

export default function EmployeePerformance({
    employee,
    employees,
    days,
    summary,
    filters,
    years,
    online,
    email,
}: {
    // Null only on a business with no users at all — before the wizard has
    // hydrated anybody.
    employee: EmployeeOption | null;
    employees: EmployeeOption[];
    days: EmployeeDay[];
    summary: EmployeeSummary | null;
    filters: Filters;
    years: number[];
    // False when the business database could not be reached. There is no local
    // month behind it — see OfflineNotice.
    online: boolean;
    email: ReportEmailProp;
}) {
    const { format: money } = useCurrency();
    const [emailOpen, setEmailOpen] = useState(false);

    const reload = (params: Record<string, string | number | undefined>) => {
        router.get(
            route('employee-performance'),
            {
                user: employee?.id,
                month: filters.month,
                year: filters.year,
                ...params,
            },
            { preserveState: true, preserveScroll: true, replace: true, only: RELOAD_PROPS },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee Performance" />
            <PageShell>
                <FilterBar
                    actions={
                        /* Enabled even when email is not set up yet: a disabled
                           button with no explanation is the worse failure, and the
                           dialog is where the explanation lives. Offline is the one
                           case that does disable it, because there is no report to
                           attach — and the reason is on screen right below. */
                        <Button
                            variant="outline"
                            disabled={!online}
                            title={online ? undefined : 'The business database cannot be reached, so there is no report to send.'}
                            onClick={() => setEmailOpen(true)}
                        >
                            <Mail className="size-4" />
                            Email report
                        </Button>
                    }
                >
                    <Select value={employee?.id ?? ''} onValueChange={(value) => reload({ user: value })}>
                        <SelectTrigger className="w-60" aria-label="Employee">
                            <SelectValue placeholder="Pick an employee" />
                        </SelectTrigger>
                        <SelectContent>
                            {employees.map((option) => (
                                <SelectItem key={option.id} value={option.id}>
                                    {option.name}
                                    {option.archived && ' (archived)'}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <MonthYearPicker
                        month={filters.month}
                        year={filters.year}
                        years={years}
                        onChange={reload}
                        previous={{ month: filters.prev_month, year: filters.prev_year }}
                        next={{ month: filters.next_month, year: filters.next_year }}
                    />
                </FilterBar>

                <EmployeeReportDialog
                    open={emailOpen}
                    onOpenChange={setEmailOpen}
                    email={email}
                    employees={employees}
                    defaultEmployeeId={employee?.id ?? null}
                    defaultMonth={filters.month}
                    defaultYear={filters.year}
                    years={years}
                />

                {!online && <OfflineNotice />}

                {!online ? null : employee === null || summary === null ? (
                    <EmptyState icon={UsersRound} title="No employees yet" description="Add a user and their months will be reported here." />
                ) : (
                    <>
                        <div>
                            <h2 className="text-lg font-semibold">
                                {employee.name}
                                <span className="text-muted-foreground text-sm font-normal">
                                    {' '}
                                    · {employee.role}
                                    {employee.archived && ' · archived'}
                                </span>
                            </h2>
                            {/* Always every branch — this report has no scope to
                                name, and saying so is what stops a month being
                                read as one branch's. */}
                            <p className="text-muted-foreground text-sm">All branches · {filters.label}</p>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <StatCard
                                label="Days worked"
                                value={String(summary.days_worked)}
                                hint={`${summary.shifts} ${summary.shifts === 1 ? 'shift' : 'shifts'}`}
                            />
                            <StatCard
                                label="Hours"
                                value={summary.hours.toFixed(2)}
                                // Stated next to the total it is missing from, so
                                // the number never has to be explained twice.
                                hint={
                                    summary.open_shifts > 0
                                        ? `${summary.open_shifts} ${summary.open_shifts === 1 ? 'shift' : 'shifts'} with no sign-out excluded`
                                        : undefined
                                }
                            />
                            <StatCard label="Transactions" value={String(summary.transactions)} />
                            <StatCard label="Net sales" value={money(summary.net_sales)} />
                            <StatCard
                                label="Net sales per hour"
                                value={summary.sales_per_hour === null ? '—' : money(summary.sales_per_hour)}
                                hint={summary.sales_per_hour === null ? 'No recorded hours' : undefined}
                            />
                        </div>

                        {/* The one seam worth stating outright: a shift and a
                            calendar day are not the same thing, and reconciling
                            them would mean inventing a shift boundary for sales. */}
                        <p className="text-muted-foreground text-sm">
                            A shift appears on the day it started, so a night shift crossing midnight is one entry marked{' '}
                            <span className="font-medium">+1d</span>. Sales are counted on the day they were rung, so that shift's after-midnight
                            sales appear on the next day. Net sales are gross minus returns and defects on this employee's own sales. Select a day for
                            its shifts, hours and individual receipts.
                        </p>

                        {/* The employee travels with the grid because a cell
                            opens into one day of THEIR month, and the day's
                            receipts are fetched under their id. */}
                        <AttendanceCalendar days={days} employee={employee} />

                        <div className="text-muted-foreground grid gap-1 text-sm sm:grid-cols-3">
                            <div>Gross sales: {money(summary.gross_sales)}</div>
                            <div>Returns: {money(summary.returns)}</div>
                            <div>Defects: {money(summary.defects)}</div>
                        </div>

                        {/* Where the Hours card's total was worked. Open shifts
                            are in neither, so this always foots to it. */}
                        {summary.hours_by_branch.length > 0 && (
                            <p className="text-muted-foreground text-sm">
                                Hours by branch: {summary.hours_by_branch.map((branch) => `${branch.code} ${formatHours(branch.hours)}`).join(' · ')}
                            </p>
                        )}
                    </>
                )}
            </PageShell>
        </AppLayout>
    );
}
