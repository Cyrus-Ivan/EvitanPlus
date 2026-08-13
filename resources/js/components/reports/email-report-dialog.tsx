import { BranchScopeSelect } from '@/components/branch-scope-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { periodWindowLabel } from '@/lib/report-period';
import { BranchScope, ReportPeriod, RosterBranch } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, X } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface ReportEmailProp {
    /** Whether a sender is configured at all. False means nothing can be sent. */
    configured: boolean;
    /** The stored default recipients, which this dialog pre-fills and never writes back to. */
    recipients: string[];
    /** Whether this viewer may go and set it up (Owner). */
    canConfigure: boolean;
}

/** One row: an address and whether it goes on this send. */
interface Row {
    address: string;
    checked: boolean;
}

const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** The same four the page's toggle group offers, spelled for a narrow select. */
const PERIOD_LABELS: [ReportPeriod, string][] = [
    ['daily', 'Day'],
    ['weekly', 'Week'],
    ['monthly', 'Month'],
    ['yearly', 'Year'],
];

/**
 * Email the report on screen, as a PDF attachment.
 *
 * The defaults are ticked on open and can be unticked or removed, and an extra
 * address can be added — all for THIS send only. Nothing here writes back to the
 * configured list, which is the Owner's to manage in Business Settings.
 *
 * The period, the date and the branch scope are the page's when the dialog opens
 * and are then this dialog's own: emailing last week, or another branch, must not
 * mean navigating the page there first.
 *
 * The picker being here does NOT make it a second authority on which branches a
 * viewer may read — normalizeScope() on the server still constrains and
 * authorizes every scope, and answers anyone it refuses with a 403. That is a
 * hard abort on this POST rather than a toast, which is why the control is hidden
 * from anyone that would happen to: `canConsolidate` is the same gate the page
 * uses to decide whether to offer its own.
 *
 * Everything is re-derived server-side anyway (and the scope re-authorized), so
 * nothing sent from here is trusted.
 */
export default function EmailReportDialog({
    open,
    onOpenChange,
    email,
    defaultPeriod,
    defaultDate,
    defaultScope,
    chartBy,
    branches,
    canConsolidate,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    email: ReportEmailProp;
    defaultPeriod: ReportPeriod;
    defaultDate: string;
    defaultScope: BranchScope;
    chartBy: string;
    branches: RosterBranch[];
    canConsolidate: boolean;
}) {
    const [rows, setRows] = useState<Row[]>([]);
    const [draft, setDraft] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);
    const [period, setPeriod] = useState<ReportPeriod>(defaultPeriod);
    const [date, setDate] = useState(defaultDate);
    const [scope, setScope] = useState<BranchScope>(defaultScope);

    const form = useForm<{ recipients: string[] }>({ recipients: [] });
    const { errors, processing, clearErrors } = form;

    // Reset on open, so a previous send's edits and errors never leak into the
    // next one — the overridden period, date and scope included. This page sets
    // no persistent layout, but the dialog itself stays mounted between opens.
    useEffect(() => {
        if (!open) return;
        setRows(email.recipients.map((address) => ({ address, checked: true })));
        setDraft('');
        setDraftError(null);
        setPeriod(defaultPeriod);
        setDate(defaultDate);
        setScope(defaultScope);
        clearErrors();
    }, [open, email.recipients, defaultPeriod, defaultDate, defaultScope, clearErrors]);

    const checked = rows.filter((row) => row.checked).map((row) => row.address);

    // What this send covers, named the way the page names it. The server
    // computes its own for the document and the subject line; this one only has
    // to agree with the picker above it.
    const scopeLabel =
        scope === 'local'
            ? 'This branch'
            : scope === 'all'
              ? 'All branches'
              : (() => {
                    const branch = branches.find((b) => b.id === scope);
                    return branch ? `${branch.code} — ${branch.name}` : 'One branch';
                })();

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
            period,
            date,
            ...(scope !== 'local' ? { scope, by: chartBy } : {}),
        }));

        form.post(route('reports.email'), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Email a report</DialogTitle>
                    <DialogDescription>
                        {scopeLabel} · {periodWindowLabel(period, date)}. The full report goes out as a PDF attachment.
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
                        <div className="grid gap-3 sm:grid-cols-[9rem_1fr]">
                            <div className="grid gap-1.5">
                                <Label htmlFor="email-report-period">Period</Label>
                                <Select value={period} onValueChange={(value) => setPeriod(value as ReportPeriod)}>
                                    <SelectTrigger id="email-report-period">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {PERIOD_LABELS.map(([value, label]) => (
                                            <SelectItem key={value} value={value}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="email-report-date">Date in that period</Label>
                                <Input
                                    id="email-report-date"
                                    type="date"
                                    value={date}
                                    onChange={(e) => setDate(e.target.value)}
                                    max={new Date().toLocaleDateString('en-CA')}
                                />
                            </div>
                        </div>
                        <p className="text-muted-foreground -mt-2 text-xs">
                            Any date inside the period you want; it snaps to the whole day, week, month or year. The page behind this dialog does not
                            move.
                        </p>

                        {/* Which branches this send covers. Owner-only, and
                            online-only — the server refuses anything but
                            'local' for anyone else, and falls back to this
                            branch's figures if it cannot reach the business
                            database. */}
                        {canConsolidate && (
                            <div className="grid gap-1.5">
                                {/* No htmlFor: the control labels its own
                                    trigger with the same words. */}
                                <Label>Branches</Label>
                                <BranchScopeSelect scope={scope} branches={branches} onChange={setScope} label="Branches reported on" />
                            </div>
                        )}

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
                                            id={`recipient-${index}`}
                                            checked={row.checked}
                                            onCheckedChange={(value) =>
                                                setRows(rows.map((r, i) => (i === index ? { ...r, checked: value === true } : r)))
                                            }
                                        />
                                        <label htmlFor={`recipient-${index}`} className="flex-1 truncate text-sm">
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
                            <Button type="submit" disabled={processing || checked.length === 0}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                {checked.length === 0 ? 'Pick at least one recipient' : `Send to ${checked.length}`}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
