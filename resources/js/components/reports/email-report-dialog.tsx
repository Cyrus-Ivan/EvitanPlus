import { BranchScopeSelect } from '@/components/filters/branch-scope-select';
import Field from '@/components/forms/field';
import { DetailDialog, FormDialog } from '@/components/forms/form-dialog';
import { EmailNotConfigured, RecipientPicker, useRecipients } from '@/components/reports/recipient-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { closeOnSuccess } from '@/hooks/use-dialog-form';
import { periodWindowLabel } from '@/lib/report-period';
import { BranchScope, ReportPeriod, RosterBranch } from '@/types';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface ReportEmailProp {
    /** Whether a sender is configured at all. False means nothing can be sent. */
    configured: boolean;
    /** The stored default recipients, which this dialog pre-fills and never writes back to. */
    recipients: string[];
    /** Whether this viewer may go and set it up (Owner). */
    canConfigure: boolean;
}

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
 * configured list, which is the Owner's to manage in Business Settings. That
 * half is {@see RecipientPicker}, shared with the employee report dialog.
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
    const recipients = useRecipients(open, email.recipients);
    const [period, setPeriod] = useState<ReportPeriod>(defaultPeriod);
    const [date, setDate] = useState(defaultDate);
    const [scope, setScope] = useState<BranchScope>(defaultScope);

    const form = useForm<{ recipients: string[] }>({ recipients: [] });
    const { errors, processing, clearErrors } = form;

    // Reset on open, so a previous send's overridden period, date and scope never
    // leak into the next one. This page sets no persistent layout, but the dialog
    // itself stays mounted between opens.
    useEffect(() => {
        if (!open) return;
        setPeriod(defaultPeriod);
        setDate(defaultDate);
        setScope(defaultScope);
        clearErrors();
    }, [open, defaultPeriod, defaultDate, defaultScope, clearErrors]);

    const checked = recipients.checked;

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

    const description = `${scopeLabel} · ${periodWindowLabel(period, date)}. The full report goes out as a PDF attachment.`;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform(() => ({
            recipients: checked,
            period,
            date,
            ...(scope !== 'local' ? { scope, by: chartBy } : {}),
        }));

        form.post(route('reports.email'), closeOnSuccess(onOpenChange));
    };

    // Nothing to submit when there is nowhere to send, so it is not a form at
    // all — it opens to explain, and closes with Escape or the X.
    if (!email.configured) {
        return (
            <DetailDialog open={open} onOpenChange={onOpenChange} title="Email a report" description={description}>
                <EmailNotConfigured canConfigure={email.canConfigure} />
            </DetailDialog>
        );
    }

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Email a report"
            description={description}
            submitLabel={checked.length === 0 ? 'Pick at least one recipient' : `Send to ${checked.length}`}
            processing={processing}
            submitDisabled={checked.length === 0}
            onSubmit={submit}
        >
            <div className="grid gap-3 sm:grid-cols-[9rem_1fr]">
                <Field label="Period" htmlFor="email-report-period">
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
                </Field>
                <Field label="Date in that period" htmlFor="email-report-date">
                    <Input
                        id="email-report-date"
                        type="date"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                        max={new Date().toLocaleDateString('en-CA')}
                    />
                </Field>
            </div>
            <p className="text-muted-foreground -mt-2 text-xs">
                Any date inside the period you want; it snaps to the whole day, week, month or year. The page behind this dialog does not move.
            </p>

            {/* Which branches this send covers. Owner-only, and online-only — the
                server refuses anything but 'local' for anyone else, and falls back
                to this branch's figures if it cannot reach the business database. */}
            {canConsolidate && (
                <div className="grid gap-1.5">
                    {/* Not a <Field>: no htmlFor, because the control labels its
                        own trigger with the same words. */}
                    <Label>Branches</Label>
                    <BranchScopeSelect scope={scope} branches={branches} onChange={setScope} label="Branches reported on" />
                </div>
            )}

            <RecipientPicker recipients={recipients} errors={errors as Record<string, string | undefined>} idPrefix="recipient" />
        </FormDialog>
    );
}
