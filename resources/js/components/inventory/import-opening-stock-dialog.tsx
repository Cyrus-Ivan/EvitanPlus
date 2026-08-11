import InputError from '@/components/input-error';
import { OfflineNotice } from '@/components/offline-notice';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { OpeningStockPreview } from '@/types';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, Download, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

/**
 * Set opening stock for this branch from a spreadsheet.
 *
 * Offered only while the branch has never sold anything — the page decides that
 * from `openingWindow`, and the server decides it twice more. A device off the
 * setup wizard has every variant at zero: for a new business because nothing
 * seeds a catalog, for a new branch because a branch that has never traded has
 * no stock rows to hydrate. Without this, getting a real catalog off zero was
 * one line per variant through the batch dialog, capped at a hundred a time.
 *
 * ## Every quantity is a total, not a change
 *
 * A line says what should be on the shelf, and the movement written is the
 * difference. So the file can be uploaded again after a correction and only the
 * difference moves — which is why, unlike the item import, there is nothing here
 * to confirm before applying. The preview is for reading the arithmetic, not for
 * agreeing to it.
 *
 * ## It is not the item importer's sibling by accident
 *
 * Same two-post shape and the same reasons: check writes nothing, the file is
 * re-uploaded to be applied rather than parked on the server, and the apply is
 * all-or-nothing over the file it is actually applying.
 */
export default function ImportOpeningStockDialog({
    open,
    onOpenChange,
    online,
    scope,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    online: boolean;
    // The branch the page is currently showing stock for, sent with both posts
    // and checked by the server. Always `local` in practice — the button does
    // not exist under any other scope — which is exactly what makes sending it
    // worth doing: it states the target rather than leaving the server to assume
    // one, so a request built anywhere else is refused instead of silently
    // landing on this branch. See OpeningStockImportRequest.
    scope: string;
}) {
    const form = useForm<{ file: File | null }>({ file: null });
    const { data, setData, errors, processing, reset, clearErrors } = form;

    const [preview, setPreview] = useState<OpeningStockPreview | null>(null);
    const [checking, setChecking] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (open) {
            setPreview(null);
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

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
        body.append('scope', scope);

        try {
            const response = await fetch(route('opening-stock.preview'), {
                method: 'POST',
                body,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                // 422 carries field errors (not a CSV, too big); 403 carries a
                // plain `message` — the window having closed under us, or the
                // scope naming a branch this import cannot write. Both are
                // written server-side rather than guessed from the status here,
                // so there is one copy of each sentence.
                const payload = await response.json().catch(() => null);
                form.setError('file', payload?.errors?.file?.[0] ?? payload?.message ?? 'The file could not be read.');

                return;
            }

            setPreview((await response.json()) as OpeningStockPreview);
        } finally {
            setChecking(false);
        }
    };

    /** Apply it for real. */
    const apply = () => {
        form.transform((current) => ({ ...current, scope }));
        form.post(route('opening-stock.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setPreview(null);
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
                onOpenChange(false);
            },
        });
    };

    const blocked = (preview?.errors.length ?? 0) > 0;
    const moves = preview?.moves ?? 0;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl">
                <DialogHeader className="shrink-0 border-b px-4 py-4 pr-12 sm:px-6">
                    <DialogTitle>Import opening stock</DialogTitle>
                    <DialogDescription>
                        Set the starting count for this branch from a spreadsheet of SKUs and quantities. Available until this branch makes its first
                        sale.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 min-w-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6">
                    {!online && <OfflineNotice what="Opening stock import" />}

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
                            <Button type="submit" variant="outline" disabled={!data.file || checking || processing}>
                                {checking && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Check file
                            </Button>
                            {/* A plain anchor: the template is a streamed
                                download and must not go through Inertia. */}
                            <Button variant="ghost" asChild>
                                <a href={route('opening-stock.template')}>
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
                                <span className="font-medium">{moves}</span> would move · <span className="font-medium">{preview.unchanged}</span>{' '}
                                already correct
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
                                                <span className="font-mono text-xs">{error.row === null ? 'File' : `Row ${error.row}`}</span> —{' '}
                                                {error.messages.join(' ')}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : (
                                preview.lines.length > 0 && (
                                    <div className="max-h-72 overflow-y-auto rounded-md border">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>SKU</TableHead>
                                                    <TableHead>Product</TableHead>
                                                    <TableHead className="text-right">Now</TableHead>
                                                    <TableHead className="text-right">File says</TableHead>
                                                    <TableHead className="text-right">Movement</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {preview.lines.map((line) => (
                                                    <TableRow key={line.sku}>
                                                        <TableCell className="font-mono text-xs">{line.sku}</TableCell>
                                                        <TableCell>
                                                            {line.product}
                                                            {line.variant ? ` · ${line.variant}` : ''}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">{line.current}</TableCell>
                                                        <TableCell className="text-right tabular-nums">{line.quantity}</TableCell>
                                                        <TableCell className="text-muted-foreground text-right tabular-nums">
                                                            {line.delta === 0 ? '—' : line.delta > 0 ? `+${line.delta}` : line.delta}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )
                            )}
                        </div>
                    )}

                    <details className="rounded-lg border">
                        <summary className="cursor-pointer px-3 py-2 text-sm font-medium">Column guide</summary>
                        <div className="text-muted-foreground space-y-2 border-t px-3 py-3 text-sm">
                            <p>
                                Two columns: <span className="text-foreground font-mono text-xs">sku</span> and{' '}
                                <span className="text-foreground font-mono text-xs">quantity</span>. Any other column is ignored, so the same
                                spreadsheet you imported products from works here once you add a quantity column.
                            </p>
                            <ul className="list-inside list-disc space-y-1">
                                <li>Every SKU must already exist. Add products under Items first.</li>
                                <li>A quantity is the total that should be on the shelf, not an amount to add.</li>
                                <li>Whole numbers from 0 upward. A row that matches the current count moves nothing.</li>
                                <li>One row per SKU — a SKU listed twice is an error, not a sum.</li>
                            </ul>
                        </div>
                    </details>
                </div>

                <DialogFooter className="shrink-0 border-t px-4 py-3 sm:px-6">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button disabled={!preview || blocked || processing || !online} onClick={apply}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {preview ? `Set stock for ${moves} product${moves === 1 ? '' : 's'}` : 'Import'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
