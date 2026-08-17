<?php

namespace App\Support\Sync;

use App\Exceptions\OfflineWriteException;
use App\Exceptions\SetupFailedException;
use Closure;
use Illuminate\Database\Connection;
use PDOException;
use Throwable;

/**
 * A live query against the business database, serving one screen
 * (docs/glossary.md §5 "online read", M8).
 *
 * ## Why this is not a pull
 *
 * A {@see Puller} fills the local mirror so the till can read it offline
 * forever. This mirrors nothing: it asks Postgres a question, renders the
 * answer, and keeps none of it. The distinction decides whether a table needs a
 * local counterpart, a `sync_cursor` row and a line in the schema parity guard —
 * and for the two things M8 reads, the answer is deliberately no:
 *
 *  - **`branches`** — the roster. `SchemaParityTest::POSTGRES_ONLY` records why
 *    the till has no such table: "the till knows only which branch it is, never
 *    the roster". Its own identity is in `instance`, written once at setup.
 *  - **`inventory_update_queue`** — "coordination between the issuing user and a
 *    branch, not branch data", in the same list. Mirroring it would give a table
 *    with two writers on two devices a third copy on each of them.
 *
 * Both are Owner/Admin screens, and both are already online-only for a stronger
 * reason than this one: an adjustment targeting another branch cannot be *issued*
 * offline either.
 *
 * ## What it shares with an online write
 *
 * Everything except the writing. Same credential, same probe, same
 * {@see BranchCycle} owning the connection for the length of the call and
 * releasing its Supavisor client slot in a `finally`. It exists so that two
 * controllers do not each assemble those three pieces slightly differently.
 *
 * No version gate, and that is the one deliberate difference from
 * {@see OnlineWrite}: the gate exists so a build below the schema's floor cannot
 * WRITE stale data (§13). Reading a table it may not fully understand is how the
 * operator finds out what is going on, and refusing to render a screen would
 * take away the display of the very problem.
 */
class OnlineRead
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ConnectivityProbe $probe,
        private readonly BranchCycle $cycle,
    ) {}

    /**
     * Run $work against the business database.
     *
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $work
     * @param  string  $subject  What is being read, for the offline message.
     * @return TReturn
     *
     * @throws OfflineWriteException When this device cannot reach the business database.
     */
    public function run(Closure $work, string $subject = 'This'): mixed
    {
        $connection = $this->vault->open();

        if ($connection === null || ! $this->probe->reachable($connection)) {
            throw OfflineWriteException::forReading($subject);
        }

        return $this->cycle->run($connection, $work);
    }

    /**
     * Is an online read possible right now?
     *
     * For a controller that would rather render a screen saying so than throw —
     * the same courtesy {@see OnlineWrite::possible()} does for the edit screens.
     */
    public function possible(): bool
    {
        return $this->probe->online();
    }

    /**
     * Should this failure be logged, or is it just the network being the network?
     *
     * Every consolidated screen wraps its read in
     * `rescue(..., null, report: false)` and falls back to the branch-local view,
     * which is the right BEHAVIOUR — a shop with no connection still has to work
     * — and was the wrong logging policy, because `rescue()` catches `Throwable`.
     * A `TypeError`, a column that does not exist upstream, a grant the matrix
     * forgot: all of them presented to the operator as "the business database
     * could not be reached", and `report: false` meant none of them reached the
     * log either. There was no way to tell a broken feature from a broken router,
     * and no trace anywhere to find out.
     *
     * The `Carbon\Carbon` vs `Illuminate\Support\Carbon` trap this codebase
     * documents in two places is exactly that shape: a hint mismatch downstream
     * of `ReportPeriods::resolveWindow()` is a `TypeError` that silently degrades
     * every wider-scope request to branch-local, forever, in total silence.
     *
     * So an OUTAGE stays quiet and anything else is reported. The two shapes an
     * outage really arrives in are the app's own — the probe refusing before a
     * connection is opened, and {@see BranchCycle} failing to open one — plus a
     * connection dropping mid-query, which Postgres reports as **SQLSTATE class
     * 08**.
     *
     * Judging a `PDOException` by its class rather than its type is the part that
     * earns its keep: `42703 undefined column` and `42501 permission denied` are
     * also `PDOException`s, and they are exactly the bugs this predicate exists
     * to stop hiding. A dropped connection and a query the schema cannot answer
     * are not the same news.
     */
    public static function isOutage(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof OfflineWriteException || $cause instanceof SetupFailedException) {
                return true;
            }

            if ($cause instanceof PDOException && self::isConnectionState((string) $cause->getCode())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this SQLSTATE a connection problem rather than a rejected query?
     *
     * Class `08` is "connection exception" in the standard. `HY000` and an empty
     * code are PDO's own generic slots, which is where a transport-level failure
     * with no SQLSTATE of its own ends up — treated as an outage because a driver
     * that could not say anything more specific usually could not reach anything.
     */
    private static function isConnectionState(string $sqlState): bool
    {
        return $sqlState === '' || $sqlState === 'HY000' || str_starts_with($sqlState, '08');
    }

    /**
     * The `report:` argument every consolidated read passes to `rescue()`.
     *
     * A named thing rather than a closure repeated eleven times, so the policy
     * has one definition and the call sites stay readable.
     *
     * @return Closure(Throwable): bool
     */
    public static function reportUnlessOffline(): Closure
    {
        return static fn (Throwable $e): bool => ! self::isOutage($e);
    }
}
