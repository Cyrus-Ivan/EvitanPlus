import { Alert } from '@/components/ui/alert';
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
 *
 * Distinct from the scope-fallback notices on the read-only screens, which say
 * the opposite thing: nothing there is being changed, and the risk is a
 * correct-looking table covering fewer branches than its heading claims. Those
 * share this component's CHROME (`Alert variant="warning"`) and deliberately not
 * its wording.
 */
export function OfflineNotice({ what }: { what: string }) {
    return (
        <Alert variant="warning">
            <WifiOff />
            <p>
                <span className="font-medium">Selling is unaffected — this device is just offline.</span> {what} is shared across all your branches,
                so changes have to be saved to the business database. Viewing works as normal; reconnect to make changes.
            </p>
        </Alert>
    );
}
