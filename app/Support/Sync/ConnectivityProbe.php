<?php

namespace App\Support\Sync;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The cheap check that decides whether a cycle is worth attempting
 * (MULTIBRANCH-BRAINSTORM.md §11.3, docs/glossary.md §5).
 *
 * ## It must never open Postgres
 *
 * This is the rule the whole class exists to obey. Branch devices connect
 * through Supabase's TRANSACTION pooler, where every connected CLIENT holds a
 * Supavisor client slot regardless of whether a query is running, and those
 * slots are capped per tenant in the low hundreds. A `select 1` on a timer from
 * every offline till would occupy one slot each, permanently — re-imposing the
 * exact ceiling that connecting per cycle exists to remove, and doing it worst
 * on the tills that are least able to sync.
 *
 * So reachability is answered over HTTPS, against the project's REST endpoint,
 * and the database is opened only once a cycle is actually going to run.
 *
 * ## Any HTTP response is a yes
 *
 * `/rest/v1/` without an apikey answers 401. That is a complete success for this
 * purpose: something on the far end spoke HTTP, so the link is up and Supabase
 * is serving. Only a transport failure — DNS, refused, timed out — is offline.
 * Treating 401 as "down" would make a healthy branch look disconnected.
 *
 * ## The project ref comes from the username
 *
 * Supavisor requires `<role>.<project-ref>` as the login name, which is why the
 * credentials JSON carries `connection.username` separately from `role.name`
 * (see {@see CredentialsDocument}). That suffix is the project ref, and
 * `https://<ref>.supabase.co` is its REST host.
 */
class ConnectivityProbe
{
    /** Short on purpose: this runs before a page render on the CSV import screen. */
    private const TIMEOUT_SECONDS = 2;

    public function __construct(private readonly CredentialVault $vault) {}

    /**
     * Is this device online right now, as far as anything that matters can tell?
     *
     * The convenience form for callers that only want a yes or no — the CSV
     * import screen, which is online-only (§17). A device with no stored
     * credential is offline: it has nothing to be online *to*.
     */
    public function online(): bool
    {
        try {
            $connection = $this->vault->open();
        } catch (Throwable) {
            return false;
        }

        return $connection !== null && $this->reachable($connection);
    }

    /**
     * Is the business database's project reachable?
     *
     * Returns true when it answered, and ALSO when the endpoint cannot be
     * derived — a direct, non-pooler host has no project ref, and the honest
     * response to "I cannot tell" is to let the cycle try and let its own
     * failure classify itself. A probe that guessed "offline" there would leave
     * such a device unable to sync at all, with nothing in the logs but a
     * network error that never happened.
     *
     * @param  array<string, mixed>  $connection  The JSON's `connection` block.
     */
    public function reachable(array $connection): bool
    {
        $endpoint = $this->endpoint($connection);

        if ($endpoint === null) {
            return true;
        }

        try {
            // BOTH timeouts, and the connect one is the point. `timeout()` sets
            // only Guzzle's total-response budget; `connect_timeout` keeps
            // Laravel's default of TEN SECONDS, so the two-second budget this
            // class advertises was two seconds for a server that answers and ten
            // for one that does not. The shop failure that matters is a router
            // associated but black-holing packets — no refusal, no DNS error,
            // just silence — which is exactly the case that hit the connect
            // timeout. And this runs on a render path (the Inventory page, and
            // the roster read behind every scope picker), so a page a cashier
            // lives on was blocking ten seconds every load with the network half
            // up.
            Http::connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->head($endpoint);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The REST endpoint for this credential's project, or null if the username
     * carries no project ref.
     *
     * @param  array<string, mixed>  $connection
     */
    public function endpoint(array $connection): ?string
    {
        $username = (string) ($connection['username'] ?? '');

        if (! str_contains($username, '.')) {
            return null;
        }

        $ref = substr($username, strrpos($username, '.') + 1);

        if (preg_match('/^[a-z0-9]{16,}$/', $ref) !== 1) {
            return null;
        }

        return "https://{$ref}.supabase.co/rest/v1/";
    }
}
