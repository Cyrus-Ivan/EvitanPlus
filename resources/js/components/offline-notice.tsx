import { WifiOff } from 'lucide-react';

/**
 * Shown on a screen that edits data shared by every branch — the catalog, staff
 * accounts, business settings, CSV import.
 *
 * Those live in the business database, so changing one needs a connection; this
 * device has none right now. The wording has one job beyond saying so: make
 * clear the till is *not* broken. Selling, returns and receipts are unaffected,
 * and someone reading a banner mid-shift needs to know that in the first line.
 *
 * Advisory only. The refusal itself is server-side (App\Support\Sync\OnlineWrite),
 * so a connection that drops between this render and a submit is still caught.
 */
export function OfflineNotice({ what }: { what: string }) {
    return (
        <div role="alert" className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
            <WifiOff className="mt-0.5 size-4 shrink-0" />
            <p>
                <span className="font-medium">Selling is unaffected — this device is just offline.</span> {what} is shared across all your branches,
                so changes have to be saved to the business database. Viewing works as normal; reconnect to make changes.
            </p>
        </div>
    );
}
