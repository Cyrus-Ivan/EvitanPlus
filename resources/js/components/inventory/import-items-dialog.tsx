import InputError from '@/components/forms/input-error';
import SubmitButton from '@/components/forms/submit-button';
import ImportGuide from '@/components/inventory/import-guide';
import { OfflineNotice } from '@/components/sync/offline-notice';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDialogForm } from '@/hooks/use-dialog-form';
import type { ImportHistoryRow, ImportPreview, Paginated } from '@/types';
import { AlertTriangle, ChevronRight, Download } from 'lucide-react';
import { FormEventHandler, Fragment, useEffect, useRef, useState } from 'react';

type Tab = 'upload' | 'history';

const MODE_LABELS: Record<string, string> = {
    create: 'New products',
    update: 'Updates',
    upsert: 'New + updates',
    // Not from this dialog: an opening-stock file, imported from Inventory. The
    // two share one history because what happened to this device's data is more
    // useful in one list than split by which screen did it.
    opening_stock: 'Opening stock',
    unknown: '—',
};

function StatusBadge({ status }: { status: ImportHistoryRow['status'] }) {
    if (status === 'success') {
        return <Badge>Success</Badge>;
    }
    if (status === 'failed') {
        return <Badge variant="destructive">Failed</Badge>;
    }
    return <Badge variant="secondary">Partial</Badge>;
}

/**
 * Import products from a CSV (M13).
 *
 * This was the whole `/inventory/import` page — upload form, template link,
 * export link and the upload history. It is a modal on Items now, because
 * importing products is something you do TO the catalog rather than a place you
 * go. The export is gone with it: it existed to round-trip stock counts through
 * a spreadsheet, and stock is not in this file any more.
 *
 * ## The upload happens twice, and the first time writes nothing
 *
 * A file whose SKUs already exist is an edit to live products — prices and costs
 * every branch sells by — so the first post goes to `items.import.preview`,
 * which parses and validates and stops. What comes back is the warning: how many
 * products are new, and exactly which existing ones would change. Confirming
 * posts the same file again with `confirm_updates`.
 *
 * Re-uploading rather than parking the file server-side is deliberate: the
 * second parse is the authoritative one, so the apply stays all-or-nothing over
 * the file it is actually applying, and there is no temp file to expire. And the
 * confirmation is enforced by the importer, not by this component — a request
 * built by hand without it is refused exactly the same way.
 *
 * ## Why this one is not a {@see FormDialog}
 *
 * Its chrome is genuinely its own: a tab bar inside the header, a scrolling body
 * with the padding moved onto it, a footer that appears on one tab and not the
 * other, and a submit that is a button rather than the form's own — the `<form>`
 * in here belongs to "Check file", which writes nothing. Wrapping it would mean
 * a prop per difference. It shares the pieces that ARE shared: `SubmitButton`,
 * and `useDialogForm` for the reset-on-open.
 */
export default function ImportItemsDialog({ open, onOpenChange, online }: { open: boolean; onOpenChange: (open: boolean) => void; online: boolean }) {
    const form = useDialogForm<{ file: File | null; confirm_updates: boolean }>(open, () => ({ file: null, confirm_updates: false }));
    const { data, setData, errors, processing, reset, clearErrors } = form;

    const [tab, setTab] = useState<Tab>('upload');
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [checking, setChecking] = useState(false);
    const [history, setHistory] = useState<Paginated<ImportHistoryRow> | null>(null);
    const [expanded, setExpanded] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    // The tab and the preview are local state, so they clear on open of their own
    // accord; `useDialogForm` only knows about the form.
    useEffect(() => {
        if (open) {
            setTab('upload');
            setPreview(null);
        }
    }, [open]);

    // Past imports, fetched when the tab is first opened rather than shipped with
    // the Items page — most page views never open this dialog at all.
    useEffect(() => {
        if (tab !== 'history' || history !== null) {
            return;
        }

        let cancelled = false;

        fetch(route('items.imports'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((payload: { history?: Paginated<ImportHistoryRow> }) => {
                if (!cancelled && payload.history) {
                    setHistory(payload.history);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [tab, history]);

    const chooseFile = (file: File | null) => {
        setData('file', file);
        // A new file makes the previous answer meaningless.
        setPreview(null);
        clearErrors();
    };

    /** Ask what this file would do. Writes nothing. */
    const check: FormEventHandler = async (event) => {
        event.preventDefault();

        if (!data.file) {
            return;
        }

        setChecking(true);
        clearErrors();

        const body = new FormData();
        body.append('file', data.file);

        try {
            const response = await fetch(route('items.import.preview'), {
                method: 'POST',
                body,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                // A 422 here is the file envelope (not a CSV, too big), which the
                // ordinary error display already knows how to render.
                const payload = await response.json().catch(() => null);
                form.setError('file', payload?.errors?.file?.[0] ?? 'The file could not be read.');

                return;
            }

            setPreview((await response.json()) as ImportPreview);
        } finally {
            setChecking(false);
        }
    };

    /** Apply it for real. */
    const apply = () => {
        form.transform((current) => ({ ...current, confirm_updates: true }));
        form.post(route('items.import.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setPreview(null);
                setHistory(null);
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
                onOpenChange(false);
            },
        });
    };

    const updates = preview?.updates ?? [];
    const blocked = (preview?.errors.length ?? 0) > 0;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl">
                <DialogHeader className="shrink-0 border-b px-4 py-4 pr-12 sm:px-6">
                    <DialogTitle>Import items from CSV</DialogTitle>
                    <DialogDescription>
                        Load or update your catalog from one spreadsheet. Products only — stock is set under Inventory.
                    </DialogDescription>
                    <div className="flex gap-1 pt-2">
                        <Button variant={tab === 'upload' ? 'secondary' : 'ghost'} size="sm" onClick={() => setTab('upload')}>
                            Upload
                        </Button>
                        <Button variant={tab === 'history' ? 'secondary' : 'ghost'} size="sm" onClick={() => setTab('history')}>
                            Recent imports
                        </Button>
                    </div>
                </DialogHeader>

                <div className="min-h-0 min-w-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6">
                    {tab === 'upload' ? (
                        <>
                            {!online && <OfflineNotice what="CSV import" />}

                            <form onSubmit={check} className="space-y-3">
                                <div className="flex flex-wrap items-end gap-2">
                                    <div className="min-w-0 flex-1">
                                        <Input
                                            ref={fileInput}
                                            type="file"
                                            accept=".csv,text/csv"
                                            onChange={(event) => chooseFile(event.target.files?.[0] ?? null)}
                                            aria-label="CSV file"
                                        />
                                    </div>
                                    <SubmitButton variant="outline" processing={checking} disabled={!data.file || processing}>
                                        Check file
                                    </SubmitButton>
                                    {/* A plain anchor: the template is a streamed
                                        download and must not go through Inertia. */}
                                    <Button variant="ghost" asChild>
                                        <a href={route('items.import.template')}>
                                            <Download className="size-4" /> Template
                                        </a>
                                    </Button>
                                </div>
                                <InputError message={errors.file} />
                            </form>

                            {preview && (
                                <div className="space-y-3 rounded-lg border p-3">
                                    <p className="text-sm">
                                        <span className="font-medium">{preview.total}</span> row{preview.total === 1 ? '' : 's'} ·{' '}
                                        <span className="font-medium">{preview.creates}</span> new ·{' '}
                                        <span className="font-medium">{updates.length}</span> to update
                                    </p>

                                    {blocked ? (
                                        <div className="space-y-2">
                                            <p className="text-destructive flex items-center gap-1.5 text-sm font-medium">
                                                <AlertTriangle className="size-4 shrink-0" />
                                                This file cannot be imported. Nothing has been saved.
                                            </p>
                                            <ul className="space-y-1 text-sm">
                                                {preview.errors.map((error, index) => (
                                                    <li key={index} className="text-muted-foreground">
                                                        <span className="font-mono text-xs">{error.row === null ? 'File' : `Row ${error.row}`}</span>{' '}
                                                        — {error.messages.join(' ')}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ) : (
                                        updates.length > 0 && (
                                            <div className="border-destructive/30 bg-destructive/5 space-y-2 rounded-md border p-3">
                                                <p className="flex items-center gap-1.5 text-sm font-medium">
                                                    <AlertTriangle className="size-4 shrink-0" />
                                                    {updates.length} product{updates.length === 1 ? '' : 's'} already exist
                                                    {updates.length === 1 ? 's' : ''} and will be updated.
                                                </p>
                                                <p className="text-muted-foreground text-sm">
                                                    Their price, cost, low-stock alert and category will be replaced with what the file says, at every
                                                    branch. Stock is not touched.
                                                </p>
                                                <ul className="max-h-40 space-y-0.5 overflow-y-auto text-sm">
                                                    {updates.map((update) => (
                                                        <li key={update.sku} className="text-muted-foreground">
                                                            <span className="font-mono text-xs">{update.sku}</span> — {update.product}
                                                            {update.variant ? ` · ${update.variant}` : ''}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )
                                    )}
                                </div>
                            )}

                            <details className="rounded-lg border">
                                <summary className="cursor-pointer px-3 py-2 text-sm font-medium">Column guide</summary>
                                <div className="border-t px-3 py-3">
                                    <ImportGuide />
                                </div>
                            </details>
                        </>
                    ) : history === null ? (
                        <p className="text-muted-foreground text-sm">Loading…</p>
                    ) : history.data.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing has been imported yet.</p>
                    ) : (
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10" />
                                        <TableHead>File</TableHead>
                                        <TableHead>Kind</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Rows</TableHead>
                                        <TableHead>By</TableHead>
                                        <TableHead>When</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {history.data.map((entry) => {
                                        const rowErrors = entry.errors ?? [];
                                        const isExpanded = expanded === entry.id;

                                        return (
                                            // Keyed on the Fragment, not on the rows
                                            // inside it: a fragment returned from a
                                            // map is the element React reconciles,
                                            // and keys on its children do not count.
                                            <Fragment key={entry.id}>
                                                <TableRow>
                                                    <TableCell className="w-10">
                                                        {rowErrors.length > 0 && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-7"
                                                                aria-label={isExpanded ? 'Hide errors' : 'Show errors'}
                                                                aria-expanded={isExpanded}
                                                                onClick={() => setExpanded(isExpanded ? null : entry.id)}
                                                            >
                                                                <ChevronRight
                                                                    className={`size-4 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
                                                                />
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-medium">{entry.filename ?? 'upload'}</TableCell>
                                                    <TableCell>{MODE_LABELS[entry.mode] ?? entry.mode}</TableCell>
                                                    <TableCell>
                                                        <StatusBadge status={entry.status} />
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {entry.success_count ?? 0}/{entry.total_rows ?? 0}
                                                    </TableCell>
                                                    <TableCell>{entry.user ?? '—'}</TableCell>
                                                    <TableCell className="whitespace-nowrap">{entry.created_at}</TableCell>
                                                </TableRow>
                                                {isExpanded && (
                                                    <TableRow className="hover:bg-transparent">
                                                        <TableCell colSpan={7} className="bg-muted/30">
                                                            <ul className="space-y-1 text-sm">
                                                                {rowErrors.map((error, index) => (
                                                                    <li key={index} className="text-muted-foreground">
                                                                        <span className="font-mono text-xs">
                                                                            {error.row === null ? 'File' : `Row ${error.row}`}
                                                                        </span>{' '}
                                                                        — {error.messages.join(' ')}
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>

                {tab === 'upload' && (
                    <DialogFooter className="shrink-0 border-t px-4 py-3 sm:px-6">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        {/* type="button": it sits outside the "Check file" form,
                            but a bare <button> in a dialog still defaults to
                            submit if one is ever wrapped around it. */}
                        <SubmitButton type="button" processing={processing} disabled={!preview || blocked || !online} onClick={apply}>
                            {preview ? `Import ${preview.total} row${preview.total === 1 ? '' : 's'}` : 'Import'}
                        </SubmitButton>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
