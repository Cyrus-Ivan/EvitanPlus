<?php

namespace App\Support\Sync;

use App\Exceptions\SetupFailedException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place the shipped app opens a Postgres connection
 * (MULTIBRANCH-BRAINSTORM.md §11.5).
 *
 * ## Why a cycle owns its connection
 *
 * Branch devices connect through Supabase's TRANSACTION pooler (6543), which
 * multiplexes server backends across clients — but every connected CLIENT still
 * occupies a Supavisor client slot, and those are capped per tenant in the low
 * hundreds. A till that connects at boot and holds it all day therefore
 * re-imposes exactly the ceiling the port choice removed, one layer further out.
 * The port fixes backend pressure; only the connection LIFETIME fixes client-slot
 * pressure.
 *
 * So: connect, run the cycle, disconnect — in a `finally`, so a failed or partial
 * cycle releases the slot too. Occupancy then tracks the branches syncing at this
 * instant rather than the branches that exist.
 *
 * Setup's hydration is a cycle like any other, which is why it runs through here.
 * M6's push, M7's pull and M8's queue processing all get the same treatment, and
 * nothing else in the app may call `DB::connection('pgsql')` directly.
 *
 * ## Merge, do not replace
 *
 * The credentials JSON carries eight keys; `config('database.connections.pgsql')`
 * has twelve. Assigning the block wholesale drops `charset`, `prefix`,
 * `prefix_indexes` and `url`, and would silently discard any driver `options` a
 * later fix adds — the suites would stay green while every shipped till lost
 * them. tests/Feature/BranchConnectionDefaultsTest.php pins that the JSON block
 * is a strict subset, which is what makes this merge the correct operation and
 * not a defensive habit.
 *
 * ## No session state may survive a cycle
 *
 * No SET, no LISTEN, no temp tables, no advisory locks, no transaction spanning
 * steps. The search_path a branch role needs is pinned server-side by
 * `ALTER ROLE ... SET search_path`, which Postgres applies at backend startup and
 * therefore reaches every pooled backend — which is why the JSON's
 * `search_path` is null and gets assigned as such.
 *
 * ## The connect below cannot be given a timeout from here — do not try again
 *
 * A connect to a host that silently drops packets takes about THIRTY SECONDS (TCP
 * SYN retries), and neither lever that looks like it should bound that does
 * anything. Measured on the shipped static runtime: `connect_timeout` in the DSN
 * and the `PGCONNECT_TIMEOUT` environment variable both left it at 30.03s, and
 * `connect_timeout=2` and `connect_timeout=10` produced times identical to each
 * other and to no setting at all. It is not that the key fails to arrive —
 * PDO_PGSQL demonstrably forwards the DSN to libpq, since a bogus key raises
 * libpq's own `invalid connection option` — it simply has no effect. (Laravel's
 * `PostgresConnector::getDsn()` would not emit the key anyway; that is the second
 * obstacle, not the first.)
 *
 * It is left unbounded because it is nearly unreachable rather than because it is
 * harmless: {@see OnlineRead} and {@see OnlineWrite} both consult the probe before
 * calling in here, and the probe is now floored by a {@see NetworkLink} check. So
 * arriving at that 30 seconds requires Supabase's REST endpoint answering while
 * Postgres black-holes — a genuine split-brain at the far end, not a shop with bad
 * WiFi. Bounding it properly would mean a custom connector via `DB::extend()`.
 */
class BranchCycle
{
    public const CONNECTION = 'pgsql';

    /**
     * Open a connection with $credentials, run $work, and always disconnect.
     *
     * @template TReturn
     *
     * @param  array<string, mixed>  $credentials  The JSON's `connection` block.
     * @param  Closure(Connection): TReturn  $work
     * @return TReturn
     *
     * @throws SetupFailedException When the connection itself cannot be made.
     */
    public function run(array $credentials, Closure $work): mixed
    {
        /** @var array<string, mixed> $base */
        $base = config('database.connections.'.self::CONNECTION);

        config(['database.connections.'.self::CONNECTION => array_merge($base, $credentials)]);

        // Drop any connection resolved under the previous (empty) credentials,
        // so the config above is what the driver actually gets.
        DB::purge(self::CONNECTION);

        try {
            $connection = DB::connection(self::CONNECTION);

            // Force the PDO open here rather than inside the caller's closure,
            // so an unreachable host or a disabled role is reported as what it
            // is instead of as a failure of whatever query ran first.
            try {
                $connection->getPdo();
            } catch (Throwable $e) {
                throw new SetupFailedException(
                    'Could not connect to the business database: '.$e->getMessage(),
                    previous: $e,
                );
            }

            return $work($connection);
        } finally {
            // In a finally, and this is the whole point of the class: a cycle
            // that throws must release its Supavisor client slot exactly like
            // one that succeeds.
            //
            // purge() rather than disconnect(): disconnect closes the PDO but
            // leaves the Connection object registered, still holding the
            // credentials it was built with. The next resolve would then quietly
            // reconnect with them — past the restored config below, and past
            // whatever the caller thought it was configuring.
            DB::purge(self::CONNECTION);

            config(['database.connections.'.self::CONNECTION => $base]);
        }
    }
}
