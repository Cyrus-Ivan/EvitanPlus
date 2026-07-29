import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useSync } from '@/hooks/use-sync';
import { cn } from '@/lib/utils';
import { type SyncSnapshot } from '@/types';
import { AlertTriangle, Check, RefreshCw, ShieldAlert, WifiOff } from 'lucide-react';

/**
 * How far behind the business database this branch is, and why (M6).
 *
 * Four states rather than two, because a revoked credential, a build the schema
 * refuses and a dead internet connection look identical from behind the counter
 * and need three different responses — and only one of them is fixed by waiting.
 * The wording is aimed at the person standing at the till, not at a developer:
 * what is happening, and whether they need to do anything.
 *
 * Nothing here is a warning about selling. Every state below is one in which the
 * till carries on taking sales — that is the whole point of the offline-first
 * design, and the indicator would be actively harmful if it read as an outage.
 */

type Presentation = {
    icon: typeof Check;
    label: string;
    detail: string;
    tone: string;
};

function present(sync: SyncSnapshot): Presentation {
    const backlog = sync.unsynced_count;
    const waiting = backlog === 1 ? '1 sale waiting to sync' : `${backlog} sales waiting to sync`;

    switch (sync.state) {
        case 'unauthorized':
            return {
                icon: ShieldAlert,
                label: 'Not connected',
                detail: `This branch's access to the business database was withdrawn, so nothing can sync. ${waiting}. Selling is unaffected — contact whoever set this device up.`,
                tone: 'text-destructive',
            };
        case 'version_blocked':
            return {
                icon: AlertTriangle,
                label: 'Update required',
                detail: `The business database has moved ahead of this app, so syncing is paused in both directions until this device is updated. ${waiting}. Selling is unaffected.`,
                tone: 'text-amber-600 dark:text-amber-500',
            };
        case 'online':
            // Online AND stuck: the connection is fine and the data is being
            // refused, so waiting fixes nothing and nobody would know to call
            // for help. This is the state that used to read "Offline".
            if (sync.error_kind === 'conflict') {
                return {
                    icon: AlertTriangle,
                    label: 'Sync stuck',
                    detail: `Connected, but the business database is refusing this device's records, so ${waiting.toLowerCase()} and the number will not fall on its own. Selling is unaffected — this needs whoever set this device up.`,
                    tone: 'text-destructive',
                };
            }

            return backlog === 0
                ? {
                      icon: Check,
                      label: 'Synced',
                      detail: 'Everything on this device has reached the business database.',
                      tone: 'text-muted-foreground',
                  }
                : { icon: RefreshCw, label: `${backlog}`, detail: `${waiting}. They will go up on the next sync.`, tone: 'text-muted-foreground' };
        default:
            return {
                icon: WifiOff,
                label: backlog === 0 ? 'Offline' : `${backlog}`,
                detail:
                    backlog === 0
                        ? 'No connection to the business database. Selling works as normal and anything rung up will sync when the connection returns.'
                        : `Offline. ${waiting} — they will go up on their own when the connection returns.`,
                tone: 'text-muted-foreground',
            };
    }
}

function formatLastSync(value: string | null): string {
    if (!value) {
        return 'Never synced from this device.';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : `Last synced ${date.toLocaleString()}.`;
}

export function SyncIndicator() {
    const { sync, busy, run } = useSync();

    // Null on an install whose sync tables do not exist yet. Rendering nothing
    // is right: there is no branch to be behind.
    if (!sync) {
        return null;
    }

    const { icon: Icon, label, detail, tone } = present(sync);

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={run}
                        disabled={busy}
                        aria-label={`${label} — sync now`}
                        className={cn('gap-1.5 font-normal', tone)}
                    >
                        <Icon className={cn('size-4', busy && 'animate-spin')} />
                        <span className="text-xs">{label}</span>
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="max-w-xs">
                    <p>{detail}</p>
                    <p className="text-muted-foreground mt-1 text-xs">{formatLastSync(sync.last_sync_at)} Click to sync now.</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
