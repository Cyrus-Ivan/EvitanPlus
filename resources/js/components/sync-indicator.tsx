import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useSync, useSyncTriggers } from '@/hooks/use-sync';
import { cn } from '@/lib/utils';
import { type SyncSnapshot } from '@/types';
import { AlertTriangle, LoaderCircle, ShieldAlert, WifiOff } from 'lucide-react';

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
 *
 * ## Why the healthy state names the ACTION and not a condition
 *
 * It used to read "Synced", which is a claim this device cannot make. The count
 * behind it is the OUTBOX (`SyncStatus::snapshot()`), so it describes the push
 * direction alone: a till whose last pull is hours stale, or which has never
 * completed a cycle at all, has nothing of its own waiting and so read "Synced"
 * the whole time. A button that says "Sync" promises nothing and cannot be
 * wrong — and the ring beside it is the same circle whether it is standing still
 * or spinning as a cycle runs.
 *
 * The other three labels stay words, because none of them is a false claim:
 * "Offline", "Update required", "Sync stuck" and "Not connected" each tell the
 * person something true and different, and only the icon's colour would carry
 * it otherwise.
 */

type Presentation = {
    icon: typeof LoaderCircle;
    label: string;
    detail: string;
    tone: string;
    // The condition, in a few words, for the button's accessible name only —
    // the visible label is a verb now, so nothing else says it out loud.
    status: string;
};

function present(sync: SyncSnapshot): Presentation {
    const backlog = sync.unsynced_count;
    // "changes", not "sales": the outbox carries logins, returns and audit rows
    // too, so a device that has only been logged into shows a backlog of 1 — and
    // on the login screen (M11.5) that is the LIKELY case, not the edge one.
    const waiting = backlog === 1 ? '1 change waiting to sync' : `${backlog} changes waiting to sync`;

    switch (sync.state) {
        case 'unauthorized':
            return {
                icon: ShieldAlert,
                label: 'Not connected',
                detail: `This branch's access to the business database was withdrawn, so nothing can sync. ${waiting}. Selling is unaffected — contact whoever set this device up.`,
                tone: 'text-destructive',
                status: 'not connected',
            };
        case 'version_blocked':
            // The error carries BOTH version numbers — it is
            // VersionBlockedException's message — and "which version do I need?"
            // is the first thing anyone asks at a frozen till. Without it the
            // person has to phone the developer to learn a number the device
            // already knows.
            return {
                icon: AlertTriangle,
                label: 'Update required',
                detail: `The business database has moved ahead of this app, so syncing is paused in both directions until this device is updated. ${waiting}. Selling is unaffected.${sync.error ? ` ${sync.error}` : ''}`,
                tone: 'text-amber-600 dark:text-amber-500',
                status: 'update required',
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
                    status: 'sync stuck',
                };
            }

            // The count rides the label rather than replacing it, so the word
            // the person clicks never moves and the backlog stays readable
            // without hovering.
            return backlog === 0
                ? {
                      icon: LoaderCircle,
                      label: 'Sync',
                      detail: 'Everything on this device has reached the business database.',
                      tone: 'text-muted-foreground',
                      status: 'nothing waiting',
                  }
                : {
                      icon: LoaderCircle,
                      label: `Sync · ${backlog}`,
                      detail: `${waiting}. They will go up on the next sync.`,
                      tone: 'text-muted-foreground',
                      status: waiting,
                  };
        default:
            return {
                icon: WifiOff,
                label: backlog === 0 ? 'Offline' : `Offline · ${backlog}`,
                detail:
                    backlog === 0
                        ? 'No connection to the business database. Selling works as normal and anything rung up will sync when the connection returns.'
                        : `Offline. ${waiting} — they will go up on their own when the connection returns.`,
                tone: 'text-muted-foreground',
                status: backlog === 0 ? 'offline' : `offline, ${waiting.toLowerCase()}`,
            };
    }
}

/**
 * When the last cycle completed, short enough to sit on the button's second line.
 *
 * On screen rather than behind a hover, because it is the honest half of what
 * "Synced" used to promise: the label can only say a cycle is not running, and
 * this says when one last finished. A person deciding whether to worry needs the
 * second fact, and nobody hovers a header to look for it.
 *
 * Same day drops the date — a till is read during its own trading day, where
 * "2:14 PM" is the whole answer and the date is noise. `toLocaleTimeString`
 * follows the device's locale, as every other timestamp this app prints does.
 */
function formatLastSync(value: string | null): string {
    if (!value) {
        return 'Never synced';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const time = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    const sameDay = date.toDateString() === new Date().toDateString();

    return sameDay ? `Last synced ${time}` : `Last synced ${date.toLocaleDateString([], { month: 'short', day: 'numeric' })}, ${time}`;
}

export function SyncIndicator() {
    const { sync, busy, run } = useSync();

    // The app's single mount point for the sync triggers (M7). It lives here
    // because this component is rendered by the app header on every
    // authenticated screen and by the auth layout on the login screen (M11.5),
    // so "the app is open" and "the triggers are armed" are the same condition.
    // Those two mounts never coexist, which is what stops two components each
    // starting a backstop timer.
    // Called before the early return below: hooks may not be conditional, and a
    // device with no sync tables has nothing to trigger anyway.
    useSyncTriggers();

    // Null on an install whose sync tables do not exist yet. Rendering nothing
    // is right: there is no branch to be behind.
    if (!sync) {
        return null;
    }

    const { icon: stateIcon, label, detail, tone, status } = present(sync);

    // A cycle in flight is a cycle in flight, whatever the last one reported: the
    // ring takes over from the state's own icon and spins for exactly as long as
    // the request is open. Tied to `busy` alone and never to a non-zero backlog —
    // an animation for work that is merely PENDING would be the same kind of lie
    // the label just stopped telling.
    const Icon = busy ? LoaderCircle : stateIcon;

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    {/* One raised control, both lines inside it. The timestamp
                        used to float underneath as loose text, which made the
                        whole thing read as a label with a hover state rather
                        than the button it is. No `size`: every preset is a
                        fixed height, and two lines have to set their own. */}
                    <Button
                        variant="outline"
                        // Wrapped, and NOT `onClick={run}`. This is the app's
                        // only caller that refreshes the page as well as the
                        // indicator, and passing the flag by hand is the only
                        // honest way to say so: bare `onClick={run}` would hand
                        // `run` the click MouseEvent as its first argument,
                        // which is truthy, so it would do the right thing
                        // entirely by accident and invert the moment anything
                        // wrapped this handler.
                        onClick={() => run(true)}
                        disabled={busy}
                        aria-label={busy ? 'Syncing now' : `Sync now — ${status}`}
                        className={cn('h-auto gap-2 px-3 py-1.5 font-normal shadow-sm', tone)}
                    >
                        <Icon className={cn('size-4', busy && 'animate-spin')} />
                        <span className="flex flex-col items-start leading-tight">
                            <span className="text-xs font-medium">{label}</span>
                            <span className="text-muted-foreground text-[11px] font-normal">{formatLastSync(sync.last_sync_at)}</span>
                        </span>
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="max-w-xs">
                    <p>{detail}</p>
                    <p className="text-muted-foreground mt-1 text-xs">Click to sync now.</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
