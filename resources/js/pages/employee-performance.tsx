import EmptyState from '@/components/empty-state';
import AttendanceCalendar from '@/components/reports/attendance-calendar';
import { type ReportEmailProp } from '@/components/reports/email-report-dialog';
import EmployeeReportDialog from '@/components/reports/employee-report-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useCurrency } from '@/hooks/use-currency';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, EmployeeDay, EmployeeOption, EmployeeSummary } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Mail, UsersRound, WifiOff } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Employee Performance', href: '/employee-performance' }];

// The props a control change reloads. `employees`, `years` and `email` are
// deliberately NOT in it: they do not change with the month or the employee, and
// re-sending them on every step of the month is pure weight.
const RELOAD_PROPS = ['employee', 'days', 'summary', 'filters', 'online'];

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

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
        <div role="alert" className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            <WifiOff className="mt-0.5 size-4 shrink-0" />
            <p>
                <span className="font-medium">This report is read from the business database.</span> It covers every branch, because an employee can
                work at more than one — so there is no partial version of it to show while this device is offline. Reconnect and it will load.
            </p>
        </div>
    );
}

function StatCard({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</div>
                <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
                {hint && <div className="text-muted-foreground mt-1 text-xs">{hint}</div>}
            </CardContent>
        </Card>
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
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
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

                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Previous month"
                            onClick={() => reload({ month: filters.prev_month, year: filters.prev_year })}
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <Select value={String(filters.month)} onValueChange={(value) => reload({ month: value })}>
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
                        <Select value={String(filters.year)} onValueChange={(value) => reload({ year: value })}>
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
                            onClick={() => reload({ month: filters.next_month, year: filters.next_year })}
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>

                    {/* Enabled even when email is not set up yet: a disabled
                        button with no explanation is the worse failure, and the
                        dialog is where the explanation lives. Offline is the one
                        case that does disable it, because there is no report to
                        attach — and the reason is on screen right below. */}
                    <Button
                        variant="outline"
                        className="ml-auto"
                        disabled={!online}
                        title={online ? undefined : 'The business database cannot be reached, so there is no report to send.'}
                        onClick={() => setEmailOpen(true)}
                    >
                        <Mail className="size-4" />
                        Email report
                    </Button>
                </div>

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
                            sales appear on the next day. Net sales are gross minus returns and defects on this employee's own sales.
                        </p>

                        <AttendanceCalendar days={days} />

                        <div className="text-muted-foreground grid gap-1 text-sm sm:grid-cols-3">
                            <div>Gross sales: {money(summary.gross_sales)}</div>
                            <div>Returns: {money(summary.returns)}</div>
                            <div>Defects: {money(summary.defects)}</div>
                        </div>

                        {/* Where the Hours card's total was worked. Open shifts
                            are in neither, so this always foots to it. */}
                        {summary.hours_by_branch.length > 0 && (
                            <p className="text-muted-foreground text-sm">
                                Hours by branch: {summary.hours_by_branch.map((branch) => `${branch.code} ${branch.hours.toFixed(2)}h`).join(' · ')}
                            </p>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
