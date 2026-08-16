import InputError from '@/components/forms/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Link } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/** One row: an address and whether it goes on this send. */
interface Row {
    address: string;
    checked: boolean;
}

// Deliberately loose. The server validates properly; this only catches the
// obvious slip before a round trip, and a regex that tried to be RFC-correct
// would reject real addresses to no benefit.
const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export interface Recipients {
    rows: Row[];
    checked: string[];
    draft: string;
    draftError: string | null;
    setDraft: (value: string) => void;
    addDraft: () => void;
    toggle: (index: number, on: boolean) => void;
    remove: (index: number) => void;
}

/**
 * Who this one send goes to.
 *
 * The configured list is the STARTING POINT, not the answer: it is ticked on
 * open, and can be unticked, removed or added to for this send only. Nothing
 * here writes back — the saved list is the Owner's to manage in Business
 * Settings, and a report emailed once to an accountant should not quietly add
 * them to every report forever.
 *
 * Re-seeded on every open, because both dialogs stay mounted between opens and
 * a previous send's edits would otherwise be the next send's defaults.
 */
export function useRecipients(open: boolean, defaults: string[]): Recipients {
    const [rows, setRows] = useState<Row[]>([]);
    const [draft, setDraft] = useState('');
    const [draftError, setDraftError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setRows(defaults.map((address) => ({ address, checked: true })));
        setDraft('');
        setDraftError(null);
    }, [open, defaults]);

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

    return {
        rows,
        checked: rows.filter((row) => row.checked).map((row) => row.address),
        draft,
        draftError,
        setDraft: (value: string) => {
            setDraft(value);
            setDraftError(null);
        },
        addDraft,
        toggle: (index, on) => setRows(rows.map((row, i) => (i === index ? { ...row, checked: on } : row))),
        remove: (index) => setRows(rows.filter((_, i) => i !== index)),
    };
}

/**
 * The list itself, plus the box for adding one more.
 *
 * Shared by the two dialogs that email a PDF — the sales report and the employee
 * reports — which had carried this markup twice, verbatim, down to the footnote
 * about recipients seeing each other.
 *
 * `idPrefix` exists because both dialogs can be mounted on the same page in
 * principle, and two checkboxes sharing an `id` means clicking one label toggles
 * the other's box.
 */
export function RecipientPicker({
    recipients,
    errors,
    idPrefix,
}: {
    recipients: Recipients;
    /** The form's error bag, read for the positional `recipients.N` keys. */
    errors: Record<string, string | undefined>;
    idPrefix: string;
}) {
    const { rows, checked, draft, draftError, setDraft, addDraft, toggle, remove } = recipients;

    return (
        <>
            <div className="space-y-2">
                {rows.length === 0 && (
                    <p className="text-muted-foreground text-sm">There are no default recipients yet. Add an address below to send this report.</p>
                )}
                {rows.map((row, index) => (
                    <div key={row.address} className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <Checkbox id={`${idPrefix}-${index}`} checked={row.checked} onCheckedChange={(value) => toggle(index, value === true)} />
                            <label htmlFor={`${idPrefix}-${index}`} className="flex-1 truncate text-sm">
                                {row.address}
                            </label>
                            <Button type="button" variant="ghost" size="icon" aria-label={`Remove ${row.address}`} onClick={() => remove(index)}>
                                <X className="size-4" />
                            </Button>
                        </div>
                        {/* The server keys errors by position in the list it was
                            sent, which is the checked rows only. */}
                        <InputError message={errors[`recipients.${checked.indexOf(row.address)}`]} />
                    </div>
                ))}
            </div>

            <div className="flex items-start gap-2">
                <div className="grid flex-1 gap-1">
                    <Input
                        type="email"
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            // Enter adds the address rather than submitting the
                            // form around it — which would send the report to
                            // everyone EXCEPT the address just typed.
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
        </>
    );
}

/**
 * What a send dialog shows when no sender is configured: there is nowhere to
 * send this, and here is who can fix that.
 *
 * The Owner gets the link; anybody else gets told who to ask, because the
 * settings page would refuse them and a link that 403s is worse than no link.
 */
export function EmailNotConfigured({ canConfigure }: { canConfigure: boolean }) {
    return (
        <div className="space-y-3 text-sm">
            <p>Report email is not set up yet, so there is nowhere to send this.</p>
            {canConfigure ? (
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
    );
}
