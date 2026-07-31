<?php

namespace App\Support\Sync;

use App\Exceptions\OfflineWriteException;
use Closure;
use Illuminate\Database\Connection;

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
}
