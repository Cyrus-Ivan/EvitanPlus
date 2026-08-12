import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
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

/**
 * Email the report on screen, as a PDF attachment.
 *
 * The defaults are ticked on open and can be unticked or removed, and an extra
 * address can be added — all for THIS send only. Nothing here writes back to the
 * configured list, which is the Owner's to manage in Business Settings.
 *
 * The filters are passed through verbatim so the emailed document is the one on
 * screen, scope and all. The server re-derives them anyway (and re-authorizes
 * the scope), so nothing here is trusted.
 */
export default function EmailReportDialog({
    open,
    onOpenChange,
    email,
    period,
    date,
    scope,
    chartBy,
    periodLabel,
    scopeLabel,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    email: ReportEmailProp;
    period: string;
    date: string;
    scope: string;
    chartBy: string;
    periodLabel: string;
    scopeLabel: string;
}) {
    const [rows, setRows] = useState<Row[]>([]);
    const [draft, setDraft] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);

    const form = useForm<{ recipients: string[] }>({ recipients: [] });
    const { errors, processing, clearErrors } = form;

    // Reset on open, so a previous send's edits and errors never leak into the
    // next one. This page sets no persistent layout, but the dialog itself
    // stays mounted between opens.
    useEffect(() => {
        if (!open) return;
        setRows(email.recipients.map((address) => ({ address, checked: true })));
        setDraft('');
        setDraftError(null);
        clearErrors();
    }, [open, email.recipients, clearErrors]);

    const checked = rows.filter((row) => row.checked).map((row) => row.address);

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
                    <DialogTitle>Email this report</DialogTitle>
                    <DialogDescription>
                        {scopeLabel} · {periodLabel}. The full report goes out as a PDF attachment.
                    </DialogDescription>
                </DialogHeader>

                {!email.configured ? (
                    <div className="space-y-3 text-sm">
                        <p>Report email is not set up yet, so there is nowhere to send this.</p>
                        {email.canConfigure ? (
                            <p>
                                <Link href="/business-settings#report-email" className="font-medium underline underline-offset-4">
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
