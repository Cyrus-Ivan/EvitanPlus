import InputError from '@/components/input-error';
import type { ReportEmailProp } from '@/components/reports/email-report-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { EmployeeOption } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, X } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

/** One recipient row: an address and whether it goes on this send. */
interface Row {
    address: string;
    checked: boolean;
}

const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Mirrors EmailEmployeePerformanceRequest::MAX_USERS. The server is the
// authority; this only stops the button before a round trip that would fail.
const MAX_EMPLOYEES = 20;

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

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
 * saved list, which is the Owner's to manage in Business Settings.
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
    const [rows, setRows] = useState<Row[]>([]);
    const [draft, setDraft] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);
    const [selected, setSelected] = useState<string[]>([]);
    const [month, setMonth] = useState(defaultMonth);
    const [year, setYear] = useState(defaultYear);

    // Both keys are declared so their server-side errors are typed; the payload
    // itself is built by transform() on submit.
    const form = useForm<{ recipients: string[]; users: string[] }>({ recipients: [], users: [] });
    const { errors, processing, clearErrors } = form;

    // Reset on open, so a previous send's edits and errors never leak into the
    // next one. The dialog stays mounted between opens.
    useEffect(() => {
        if (!open) return;
        setRows(email.recipients.map((address) => ({ address, checked: true })));
        setDraft('');
        setDraftError(null);
        // Seeded with the employee on screen: the common send is "this one".
        setSelected(defaultEmployeeId ? [defaultEmployeeId] : []);
        setMonth(defaultMonth);
        setYear(defaultYear);
        clearErrors();
    }, [open, email.recipients, defaultEmployeeId, defaultMonth, defaultYear, clearErrors]);

    const checked = rows.filter((row) => row.checked).map((row) => row.address);

    const toggleEmployee = (id: string, on: boolean) => {
        setSelected(on ? [...selected, id] : selected.filter((value) => value !== id));
    };

    const addDraft = () => {
        const address = draft.trim();
        if (address === '') return;
        if (!EMAIL_SHAPE.test(address)) {
            setDraftError('Enter a valid email address.');
            return;
        }
        if (rows.some((row) => row.address.toLowerCase() === address.toLowerCase())) {
            setDraftError('That address is already on the list.');
            return;
        }
        setRows([...rows, { address, checked: true }]);
        setDraft('');
        setDraftError(null);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform(() => ({
            recipients: checked,
            users: selected,
            month,
            year,
        }));

        form.post(route('employee-performance.email'), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const tooMany = selected.length > MAX_EMPLOYEES;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Email employee reports</DialogTitle>
                    <DialogDescription>
                        All branches · {MONTHS[month - 1]} {year}. Each employee you pick gets their own PDF, all on one email.
                    </DialogDescription>
                </DialogHeader>

                {!email.configured ? (
                    <div className="space-y-3 text-sm">
                        <p>Report email is not set up yet, so there is nowhere to send this.</p>
                        {email.canConfigure ? (
                            <p>
                                <Link href="/business-settings/report-email" className="font-medium underline underline-offset-4">
                                    Set up report email
                                </Link>{' '}
                                — you will need the sending account's server address and password.
                            </p>
                        ) : (
                            <p className="text-muted-foreground">Ask the owner to set it up in Business Settings.</p>
                        )}
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="email-employee-month">Month</Label>
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
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="email-employee-year">Year</Label>
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
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Employees</Label>
                            <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border p-3">
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

                        <div className="space-y-2">
                            {rows.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    There are no default recipients yet. Add an address below to send this report.
                                </p>
                            )}
                            {rows.map((row, index) => (
                                <div key={row.address} className="grid gap-1">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={`report-recipient-${index}`}
                                            checked={row.checked}
                                            onCheckedChange={(value) =>
                                                setRows(rows.map((r, i) => (i === index ? { ...r, checked: value === true } : r)))
                                            }
                                        />
                                        <label htmlFor={`report-recipient-${index}`} className="flex-1 truncate text-sm">
                                            {row.address}
                                        </label>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${row.address}`}
                                            onClick={() => setRows(rows.filter((_, i) => i !== index))}
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                    {/* The server keys errors by position in the list it was
                                        sent, which is the checked rows only. */}
                                    <InputError message={errors[`recipients.${checked.indexOf(row.address)}` as keyof typeof errors] as string} />
                                </div>
                            ))}
                        </div>

                        <div className="flex items-start gap-2">
                            <div className="grid flex-1 gap-1">
                                <Input
                                    type="email"
                                    value={draft}
                                    onChange={(e) => {
                                        setDraft(e.target.value);
                                        setDraftError(null);
                                    }}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            addDraft();
                                        }
                                    }}
                                    placeholder="Add another address"
                                    aria-label="Add another address"
                                />
                                <InputError message={draftError ?? undefined} />
                            </div>
                            <Button type="button" variant="outline" onClick={addDraft}>
                                <Plus className="size-3.5" />
                                Add
                            </Button>
                        </div>

                        <InputError message={errors.recipients} />
                        <p className="text-muted-foreground text-xs">
                            Everyone listed will see the other recipients. Changes here apply to this send only — the saved list is unchanged.
                        </p>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing || checked.length === 0 || selected.length === 0 || tooMany}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                {selected.length === 0
                                    ? 'Pick at least one employee'
                                    : checked.length === 0
                                      ? 'Pick at least one recipient'
                                      : `Send ${selected.length} to ${checked.length}`}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
