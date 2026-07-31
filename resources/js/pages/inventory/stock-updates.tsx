import EmptyState from '@/components/empty-state';
import { OfflineNotice } from '@/components/offline-notice';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, QueuedAdjustment } from '@/types';
import { Head, router } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock Updates', href: '/inventory/stock-updates' },
];

const STATUS: Record<QueuedAdjustment['status'], { label: string; variant: 'secondary' | 'outline' | 'destructive'; hint: string }> = {
    pending: { label: 'Pending', variant: 'secondary', hint: 'Waiting for that branch to sync.' },
    accepted: { label: 'Applied', variant: 'outline', hint: 'That branch had the stock and made the entry.' },
    rejected: { label: 'Turned down', variant: 'destructive', hint: 'That branch could not spare the stock.' },
};

function formatWhen(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

/**
 * Stock adjustments issued against another branch (M8).
 *
 * A queued adjustment is not a ledger entry. It becomes one only if the branch
 * it targets accepts it, which that branch decides on its own next sync using
 * the one copy of its stock that includes its unsynced sales — so a row here is
 * a request, and the status column is its answer.
 *
 * Everything on this page is read live from the business database and none of it
 * is stored on this device, so with no connection there is genuinely nothing to
 * show. That is the same precondition as issuing one in the first place.
 */
export default function StockUpdates({ queued, online }: { queued: QueuedAdjustment[]; online: boolean }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Updates" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <p className="text-muted-foreground text-sm">
                        Stock adjustments sent to other branches. Issue one from the Adjust stock button on any product in Inventory.
                    </p>
                </div>

                {!online && <OfflineNotice what="Branch stock updates" />}

                {online && queued.length === 0 ? (
                    <EmptyState
                        icon={PackageCheck}
                        title="No branch stock updates"
                        description="Adjustments you send to another branch show up here with whether that branch applied them."
                    />
                ) : null}

                {queued.length > 0 && (
                    <div className="overflow-x-auto rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Branch</TableHead>
                                    <TableHead>Product</TableHead>
                                    <TableHead className="text-right">Change</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Issued by</TableHead>
                                    <TableHead>Issued</TableHead>
                                    <TableHead>Answered</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {queued.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium">{row.branch}</TableCell>
                                        {/* `whitespace-normal`, because TableCell is nowrap by
                                            default and a rejection reason is a sentence. Without
                                            it the table grows past the viewport and pushes the
                                            Re-issue button out of reach. */}
                                        <TableCell className="max-w-72 whitespace-normal">
                                            {row.item}
                                            {row.variant ? <span className="text-muted-foreground"> · {row.variant}</span> : null}
                                            {row.notes ? <p className="text-muted-foreground text-xs">{row.notes}</p> : null}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.quantity > 0 ? `+${row.quantity}` : row.quantity}
                                        </TableCell>
                                        <TableCell className="max-w-xs whitespace-normal">
                                            <Badge variant={STATUS[row.status].variant}>{STATUS[row.status].label}</Badge>
                                            <p className="text-muted-foreground mt-1 text-xs">{row.rejection_reason ?? STATUS[row.status].hint}</p>
                                        </TableCell>
                                        <TableCell>{row.issued_by}</TableCell>
                                        <TableCell className="text-muted-foreground text-xs">{formatWhen(row.created_at)}</TableCell>
                                        <TableCell className="text-muted-foreground text-xs">{formatWhen(row.resolved_at)}</TableCell>
                                        <TableCell className="text-right">
                                            {row.status === 'rejected' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => router.post(route('stock-updates.reissue', row.id), {}, { preserveScroll: true })}
                                                >
                                                    Re-issue
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
