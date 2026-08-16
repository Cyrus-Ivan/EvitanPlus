import Field from '@/components/forms/field';
import { DetailDialog, FormDialog } from '@/components/forms/form-dialog';
import InputError from '@/components/forms/input-error';
import type { ReportEmailProp } from '@/components/reports/email-report-dialog';
import { EmailNotConfigured, RecipientPicker, useRecipients } from '@/components/reports/recipient-picker';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { closeOnSuccess } from '@/hooks/use-dialog-form';
import { MONTHS } from '@/lib/calendar';
import type { EmployeeOption } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

// Mirrors EmailEmployeePerformanceRequest::MAX_USERS. The server is the
// authority; this only stops the button before a round trip that would fail.
const MAX_EMPLOYEES = 20;

/**
 * Email one month of employee performance, as one PDF per employee.
 *
 * The employee list is the one thing this dialog has that the page does not: the
 * page is one employee at a time on purpose, but the question is usually asked
 * of a team at the end of a month. Each selected employee gets their own
 * attachment — a performance report is about a person, and it is forwarded to
 * that person.
 *
 * The recipients default to the configured list, ticked, and can be unticked,
 * removed or added to — all for THIS send only. Nothing here writes back to the
 * saved list, which is the Owner's to manage in Business Settings. That half is
 * {@see RecipientPicker}, shared with the sales report dialog.
 *
 * There is no branch to choose. The report always covers the whole business, and
 * the server reads it from the business database or refuses — the same document
 * the page is showing.
 */
export default function EmployeeReportDialog({
    open,
    onOpenChange,
    email,
    employees,
    defaultEmployeeId,
    defaultMonth,
    defaultYear,
    years,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    email: ReportEmailProp;
    employees: EmployeeOption[];
    defaultEmployeeId: string | null;
    defaultMonth: number;
    defaultYear: number;
    years: number[];
}) {
    const recipients = useRecipients(open, email.recipients);
    const [selected, setSelected] = useState<string[]>([]);
    const [month, setMonth] = useState(defaultMonth);
    const [year, setYear] = useState(defaultYear);

    // Both keys are declared so their server-side errors are typed; the payload
    // itself is built by transform() on submit.
    const form = useForm<{ recipients: string[]; users: string[] }>({ recipients: [], users: [] });
    const { errors, processing, clearErrors } = form;

    // Reset on open, so a previous send's selection never leaks into the next
    // one. The dialog stays mounted between opens.
    useEffect(() => {
        if (!open) return;
        // Seeded with the employee on screen: the common send is "this one".
        setSelected(defaultEmployeeId ? [defaultEmployeeId] : []);
        setMonth(defaultMonth);
        setYear(defaultYear);
        clearErrors();
    }, [open, defaultEmployeeId, defaultMonth, defaultYear, clearErrors]);

    const checked = recipients.checked;

    const toggleEmployee = (id: string, on: boolean) => {
        setSelected(on ? [...selected, id] : selected.filter((value) => value !== id));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform(() => ({
            recipients: checked,
            users: selected,
            month,
            year,
        }));

        form.post(route('employee-performance.email'), closeOnSuccess(onOpenChange));
    };

    const tooMany = selected.length > MAX_EMPLOYEES;
    const description = `All branches · ${MONTHS[month - 1]} ${year}. Each employee you pick gets their own PDF, all on one email.`;

    // Nothing to submit when there is nowhere to send, so it is not a form at
    // all — it opens to explain, and closes with Escape or the X.
    if (!email.configured) {
        return (
            <DetailDialog open={open} onOpenChange={onOpenChange} title="Email employee reports" description={description}>
                <EmailNotConfigured canConfigure={email.canConfigure} />
            </DetailDialog>
        );
    }

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Email employee reports"
            description={description}
            submitLabel={
                selected.length === 0
                    ? 'Pick at least one employee'
                    : checked.length === 0
                      ? 'Pick at least one recipient'
                      : `Send ${selected.length} to ${checked.length}`
            }
            processing={processing}
            submitDisabled={checked.length === 0 || selected.length === 0 || tooMany}
            onSubmit={submit}
            className="max-h-[90vh] overflow-y-auto"
        >
            {/* Not the shared <MonthYearPicker>: that one steps through months with
                arrows because it drives a calendar grid. This picks a month to
                report on, has no adjacent month to step to, and its year list is
                the years there is anything to report. */}
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Month" htmlFor="email-employee-month">
                    <Select value={String(month)} onValueChange={(value) => setMonth(Number(value))}>
                        <SelectTrigger id="email-employee-month">
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
                </Field>
                <Field label="Year" htmlFor="email-employee-year">
                    <Select value={String(year)} onValueChange={(value) => setYear(Number(value))}>
                        <SelectTrigger id="email-employee-year">
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
                </Field>
            </div>

            {/* Not a <Field>: that names ONE control, and a `<label for>` cannot
                name a list of checkboxes. The list carries its own aria-label. */}
            <div className="space-y-2">
                <Label>Employees</Label>
                <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3" role="group" aria-label="Employees">
                    {employees.map((employee) => (
                        <div key={employee.id} className="flex items-center gap-2">
                            <Checkbox
                                id={`employee-${employee.id}`}
                                checked={selected.includes(employee.id)}
                                onCheckedChange={(value) => toggleEmployee(employee.id, value === true)}
                            />
                            <label htmlFor={`employee-${employee.id}`} className="flex-1 truncate text-sm">
                                {employee.name}
                                <span className="text-muted-foreground"> · {employee.role}</span>
                                {employee.archived && <span className="text-muted-foreground"> · archived</span>}
                            </label>
                        </div>
                    ))}
                </div>
                <InputError message={errors.users} />
                {tooMany && <InputError message={`Pick at most ${MAX_EMPLOYEES} employees for one send.`} />}
            </div>

            <RecipientPicker recipients={recipients} errors={errors as Record<string, string | undefined>} idPrefix="report-recipient" />
        </FormDialog>
    );
}
