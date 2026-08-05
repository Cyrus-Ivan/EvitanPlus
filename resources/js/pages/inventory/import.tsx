import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import ImportGuideDialog from '@/components/inventory/import-guide-dialog';
import { OfflineNotice } from '@/components/offline-notice';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ChevronRight, Download, FileSpreadsheet, Upload } from 'lucide-react';
import { Fragment, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Import / Export', href: '/inventory/import' },
];

interface ImportError {
    row: number | null;
    messages: string[];
}

interface ImportHistoryRow {
    id: string;
    filename: string | null;
    mode: string;
    status: 'success' | 'failed' | 'partial';
    total_rows: number | null;
    success_count: number | null;
    error_count: number | null;
    errors: ImportError[] | null;
    created_at: string;
    user: string | null;
}

const MODE_LABELS: Record<string, string> = {
    replace_stock_count: 'Replace stock count',
    add_to_current_stock: 'Add to current stock',
    mixed: 'Mixed',
};

function StatusBadge({ status }: { status: ImportHistoryRow['status'] }) {
    if (status === 'success') {
        return <Badge>Success</Badge>;
    }
    if (status === 'partial') {
        return <Badge variant="secondary">Partial</Badge>;
    }
    return <Badge variant="destructive">Failed</Badge>;
}

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

export default function InventoryImport({ history, online }: { history: Paginated<ImportHistoryRow>; online: boolean }) {
    const confirm = useConfirm();
    const fileInput = useRef<HTMLInputElement>(null);
    const [expandedId, setExpandedId] = useState<string | null>(null);

    const form = useForm<{ file: File | null }>({ file: null });

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (!form.data.file) {
            return;
        }

        if (
            await confirm({
                title: 'Import this CSV?',
                description:
                    'New categories, products and variants will be created. For a row matching an existing SKU, only price, cost, low-stock threshold and category are updated — name, barcode, unit and description are fixed after creation. Stock does NOT move: each row becomes a pending stock adjustment for somebody at this branch to confirm under Pending stock adjustments on Stock History. If any row is invalid the entire import is rejected and nothing is saved.',
                confirmLabel: 'Import',
            })
        ) {
            form.post(route('inventory.import.store'), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    form.reset('file');
                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            });
        }
    };

    const toggleExpanded = (id: string) => setExpandedId((prev) => (prev === id ? null : id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import / Export" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Import / Export</h1>
                        <p className="text-muted-foreground text-sm">Bulk-load or update your catalog from a CSV, or export the current catalog.</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <ImportGuideDialog />
                        <Button variant="outline" asChild>
                            <a href={route('inventory.import.template')}>
                                <FileSpreadsheet className="size-4" /> Download template
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={route('inventory.export')}>
                                <Download className="size-4" /> Export CSV
                            </a>
                        </Button>
                    </div>
                </div>

                <form onSubmit={submit} className="rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Upload CSV</h2>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Each row carries its own mode column: <span className="font-medium">replace_stock_count</span> sets the exact on-hand count,{' '}
                        <span className="font-medium">add_to_current_stock</span> adjusts it by the given amount.
                    </p>
                    <p className="text-muted-foreground mt-1 text-sm">
                        The catalog is written straight away; the stock is not. Every row's change waits under{' '}
                        <span className="font-medium">Pending stock adjustments</span> on Stock History until somebody here confirms it — use{' '}
                        <span className="font-medium">Approve all</span> to accept the whole file at once. A new product stays at 0 until then.
                    </p>
                    {/*
                        An import creates catalog rows, which belong to the whole
                        business. Since M6.5 it writes them straight to the
                        business database (CsvInventoryImporter runs as an online
                        write), which is what "online-only" was always supposed to
                        mean — M6 gated the screen on connectivity believing that
                        alone was enough, and being online is not the same as
                        arriving. Its STOCK changes are pending adjustments as of
                        schema 21 and move nothing until confirmed.
                    */}
                    {!online && (
                        <div className="mt-3">
                            <OfflineNotice what="Your catalog and stock" />
                        </div>
                    )}
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <Input
                            ref={fileInput}
                            type="file"
                            accept=".csv,text/csv"
                            className="w-80"
                            disabled={!online}
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                        />
                        <Button type="submit" disabled={!online || !form.data.file || form.processing}>
                            <Upload className="size-4" /> Import
                        </Button>
                    </div>
                    <InputError message={form.errors.file} className="mt-2" />
                </form>

                <div>
                    <h2 className="mb-2 text-sm font-semibold">Upload history</h2>
                    {history.data.length === 0 ? (
                        <EmptyState
                            icon={FileSpreadsheet}
                            title="No imports yet"
                            description="Upload a CSV to migrate or update your inventory in bulk."
                        />
                    ) : (
                        <div className="rounded-xl border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10" />
                                        <TableHead>File</TableHead>
                                        <TableHead>Mode</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Rows</TableHead>
                                        <TableHead>By</TableHead>
                                        <TableHead>When</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {history.data.map((entry) => {
                                        const hasErrors = (entry.errors?.length ?? 0) > 0;
                                        const isExpanded = expandedId === entry.id;
                                        return (
                                            <Fragment key={entry.id}>
                                                <TableRow>
                                                    <TableCell className="w-10">
                                                        {hasErrors && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-7"
                                                                aria-label={isExpanded ? 'Hide errors' : 'Show errors'}
                                                                aria-expanded={isExpanded}
                                                                onClick={() => toggleExpanded(entry.id)}
                                                            >
                                                                <ChevronRight
                                                                    className={`size-4 transition-transform ${isExpanded ? 'rotate-90' : ''}`}
                                                                />
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-medium">{entry.filename ?? '—'}</TableCell>
                                                    <TableCell>{MODE_LABELS[entry.mode] ?? entry.mode}</TableCell>
                                                    <TableCell>
                                                        <StatusBadge status={entry.status} />
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {entry.success_count ?? 0} / {entry.total_rows ?? 0}
                                                    </TableCell>
                                                    <TableCell>{entry.user ?? '—'}</TableCell>
                                                    <TableCell className="tabular-nums">{formatWhen(entry.created_at)}</TableCell>
                                                </TableRow>
                                                {isExpanded && hasErrors && (
                                                    <TableRow className="hover:bg-transparent">
                                                        <TableCell colSpan={7} className="bg-muted/30">
                                                            <ul className="space-y-1 py-1 text-sm">
                                                                {entry.errors!.map((error, index) => (
                                                                    <li key={index}>
                                                                        <span className="font-medium">
                                                                            {error.row ? `Row ${error.row}` : 'File'}:
                                                                        </span>{' '}
                                                                        {error.messages.join(' ')}
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
                    <div className="mt-4">
                        <Pagination links={history.links} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
