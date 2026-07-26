import { useConfirm } from '@/components/confirm-dialog';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { DatabaseBackup, HardDrive, LoaderCircle, RotateCw, Upload } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Database Backup', href: '/backup' }];

interface Backup {
    name: string;
    size: string;
    created_at: string;
}

function formatWhen(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

export default function Backup({ backups, isDesktop }: { backups: Backup[]; isDesktop: boolean }) {
    const confirm = useConfirm();
    const { flash } = usePage<SharedData>().props;
    const [processing, setProcessing] = useState(false);
    // Flash only lives for one response; keep the banner up until relaunch.
    const [restartRequired, setRestartRequired] = useState(false);
    if (flash.restart_required && !restartRequired) {
        setRestartRequired(true);
    }

    const restartApp = () => {
        setProcessing(true);
        router.post(route('app.restart'), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const createBackup = () => {
        setProcessing(true);
        router.post(route('backup.store'), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const exportExternal = () => {
        setProcessing(true);
        router.post(route('backup.external'), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const restoreExternal = async () => {
        if (
            await confirm({
                title: 'Restore from a file?',
                description:
                    'Choose a database file to restore. This replaces the current database. A safety backup of the current database is taken automatically before restoring. ' +
                    'Restoring an older backup permanently discards sales made after it was created, and their receipt numbers will be reissued to future sales. ' +
                    'After restoring, restart Evitan Plus to finish.',
                destructive: true,
                confirmLabel: 'Choose file…',
            })
        ) {
            setProcessing(true);
            router.post(route('backup.restore-external'), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
        }
    };

    const desktopHint = isDesktop ? undefined : 'Available in the desktop app';

    const restoreBackup = async (backup: Backup) => {
        if (
            await confirm({
                title: `Restore "${backup.name}"?`,
                description:
                    'This replaces the current database with the selected backup. A safety backup of the current database is taken automatically before restoring. ' +
                    'Restoring an older backup permanently discards sales made after it was created, and their receipt numbers will be reissued to future sales. ' +
                    'After restoring, restart Evitan Plus to finish.',
                destructive: true,
                confirmLabel: 'Restore',
            })
        ) {
            setProcessing(true);
            router.post(route('backup.restore'), { filename: backup.name }, { preserveScroll: true, onFinish: () => setProcessing(false) });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Database Backup" />
            <div className="flex min-h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Database Backup</h1>
                        <p className="text-muted-foreground text-sm">Back up the SQLite database, or restore it from a previous backup.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" onClick={restoreExternal} disabled={processing || !isDesktop} title={desktopHint}>
                            <Upload className="size-4" /> Restore from file
                        </Button>
                        <Button variant="outline" onClick={exportExternal} disabled={processing || !isDesktop} title={desktopHint}>
                            <HardDrive className="size-4" /> Back up to external drive
                        </Button>
                        <Button onClick={createBackup} disabled={processing}>
                            {processing ? <LoaderCircle className="size-4 animate-spin" /> : <DatabaseBackup className="size-4" />} Create backup
                        </Button>
                    </div>
                </div>

                {restartRequired && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm"
                    >
                        <p>Database restored. Restart Evitan Plus to finish — stock counts are re-checked against the ledger on launch.</p>
                        {isDesktop && (
                            <Button size="sm" variant="outline" onClick={restartApp} disabled={processing}>
                                <RotateCw className="size-4" /> Restart now
                            </Button>
                        )}
                    </div>
                )}

                {backups.length === 0 ? (
                    <EmptyState
                        icon={DatabaseBackup}
                        title="No backups yet"
                        description="Create your first backup to protect your business data."
                        action={
                            <Button onClick={createBackup} disabled={processing}>
                                Create backup
                            </Button>
                        }
                    />
                ) : (
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Backup</TableHead>
                                    <TableHead>Size</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {backups.map((backup) => (
                                    <TableRow key={backup.name}>
                                        <TableCell className="font-medium tabular-nums">{backup.name}</TableCell>
                                        <TableCell className="tabular-nums">{backup.size}</TableCell>
                                        <TableCell className="tabular-nums">{formatWhen(backup.created_at)}</TableCell>
                                        <TableCell className="text-right">
                                            <Button variant="ghost" size="sm" onClick={() => restoreBackup(backup)} disabled={processing}>
                                                Restore
                                            </Button>
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
