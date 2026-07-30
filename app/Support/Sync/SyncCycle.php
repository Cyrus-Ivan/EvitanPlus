<?php

namespace App\Support\Sync;

use App\Exceptions\VersionBlockedException;
use App\Models\Instance;
use App\Models\SyncStatus;
use Illuminate\Database\Connection;
use Throwable;

/**
 * One sync cycle, and the only thing in the app that runs one
 * (MULTIBRANCH-BRAINSTORM.md §11.2, docs/glossary.md §5).
 *
 *     0. version gate  →  1. push  →  [2. queue — M8]  →  3. pull  →  [4. heartbeat — M10]
 *
 * The order is load-bearing, which is why the remaining step is named here as a
 * gap rather than left to be rediscovered: push runs first so the pulled
 * baseline already reflects this branch's own movements; the queue will run
 * after push so an adjustment validates against true reconciled stock; the
 * heartbeat runs last so it describes the cycle that just finished. M6 built
 * steps 0 and 1, M7 step 3.
 *
 * ## Nothing here may stop a sale
 *
 * That is the offline guarantee (§4), and a cycle is the code most able to break
 * it — it is the only part of the till that touches a network. So {@see run()}
 * has no failure path out: every outcome, including a credential that has been
 * revoked and a build the schema refuses, becomes a `sync_status` row and a
 * returned value. The till never learns that a cycle failed except by looking.
 *
 * ## Four outcomes, not two
 *
 * `offline`, `unauthorized` and `version_blocked` are indistinguishable at the
 * counter and need three different responses — wait, call the developer,
 * install a newer build. Collapsing them would leave the person standing there
 * with no way to tell which. M10 copies these same four values into
 * `branch_status` so the console monitor can tell them apart too.
 */
class SyncCycle
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ConnectivityProbe $probe,
        private readonly BranchCycle $cycle,
        private readonly VersionGate $gate,
        private readonly Pusher $pusher,
        private readonly Puller $puller,
        private readonly StockReconciler $stock,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Run a cycle and record what happened. Never throws.
     *
     * @return array{state: string, pushed: int, pulled: int, unsynced_count: int, error: string|null}
     */
    public function run(): array
    {
        $connection = $this->credentials();

        if ($connection === null) {
            return $this->record(
                SyncStatus::OFFLINE,
                SyncStatus::KIND_AUTH,
                'This device has no stored branch credential. Run setup with the credentials file for this branch.',
            );
        }

        $reachable = $this->probe->reachable($connection);

        SyncStatus::current()->forceFill(['last_probe_at' => now()])->save();

        if (! $reachable) {
            return $this->record(SyncStatus::OFFLINE, SyncStatus::KIND_NETWORK, 'The business database is not reachable.');
        }

        $branchId = (string) Instance::current()?->branch_id;

        try {
            /** @var array{pushed: int, pulled: int} $moved */
            $moved = $this->cycle->run($connection, function (Connection $remote) use ($branchId): array {
                // Step 0. Read live — a schema migrated since the credentials
                // JSON was issued still carries the old numbers there (§9).
                $versions = $this->gate->read($remote);
                $this->gate->assert($versions['min_app_version']);

                $this->rememberVersions($versions);

                // Step 1. Before the pull, so the baseline read below already
                // reflects this branch's own movements and the reconciliation
                // has less to subtract.
                $pushed = $this->pusher->push($remote)['rows'];

                // Step 2 — the inventory update queue — belongs here, between
                // the push and the pull: an adjustment can only be validated
                // against true reconciled stock once the movements that already
                // happened have landed. M8.

                // Step 3. The catalog, staff and settings deltas, then this
                // branch's stock agreed against the business's figure.
                $pulled = array_sum($this->puller->pull($remote, $branchId));
                $pulled += $this->stock->reconcile($remote, $branchId);

                return ['pushed' => $pushed, 'pulled' => $pulled];
            });
        } catch (VersionBlockedException $e) {
            return $this->record(SyncStatus::VERSION_BLOCKED, SyncStatus::KIND_VERSION, $e->getMessage());
        } catch (Throwable $e) {
            if ($this->isUnauthorized($e)) {
                return $this->record(SyncStatus::UNAUTHORIZED, SyncStatus::KIND_AUTH, $e->getMessage());
            }

            // Connected, gated, and then refused: the branch is online and its
            // backlog is STUCK. Reporting `offline` here would tell the shop to
            // wait for a connection they already have, and nothing would ever
            // change.
            return $this->isConflict($e)
                ? $this->record(SyncStatus::ONLINE, SyncStatus::KIND_CONFLICT, $e->getMessage())
                : $this->record(SyncStatus::OFFLINE, SyncStatus::KIND_NETWORK, $e->getMessage());
        }

        return $this->record(
            SyncStatus::ONLINE,
            null,
            null,
            pushed: $moved['pushed'],
            pulled: $moved['pulled'],
            synced: true,
        );
    }

    /**
     * The sealed credential, or null on a device that has never been set up.
     *
     * Swallows a decryption failure into "no credential": it means the SQLite
     * file arrived without the per-install APP_KEY that sealed it, and the
     * remedy is to run setup again — the same remedy as having none.
     *
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        try {
            return $this->vault->open();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keep `instance`'s recorded version pair current.
     *
     * M5 wrote it at hydration; a schema migrated since then has moved on, and
     * this is the only moment the device sees the real numbers. Recorded, never
     * compared — the gate tests app version against min app version and nothing
     * else (§13).
     *
     * @param  array{schema_version: int, min_app_version: string}  $versions
     */
    private function rememberVersions(array $versions): void
    {
        Instance::query()->where('id', Instance::ID)->update([
            'schema_version' => $versions['schema_version'],
            'min_app_version' => $versions['min_app_version'],
        ]);
    }

    /**
     * Is this failure the console having disabled, rotated or revoked us?
     *
     * Worth telling apart from a network fault because only one of the two is
     * fixed by waiting, and because a branch that has been cut off deliberately
     * should say so rather than blame the internet. Walks the chain: BranchCycle
     * wraps the driver's exception in a SetupFailedException whose message is
     * the developer's, not the driver's.
     */
    private function isUnauthorized(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());

            foreach ([
                'password authentication failed',   // rotated, or the wrong file
                'is not permitted to log in',       // branch:disable
                'role "',                           // branch:revoke — 'role "branch_x" does not exist'
                'sqlstate[28',                      // 28P01 / 28000, whatever the driver says
            ] as $needle) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Did we reach Postgres and get refused by the DATA rather than the network?
     *
     * Decided by SQLSTATE class, because that is the only thing that separates
     * the two reliably — a duplicate serial and a dropped cable both arrive here
     * as a Throwable, and the message text is the driver's, not ours:
     *
     *   23xxx  integrity constraint violation — a duplicate serial, a check
     *          refusing a negative stock. The rows are wrong and will still be
     *          wrong in an hour.
     *   42xxx  syntax error or ACCESS RULE violation, which is what a missing
     *          GRANT looks like (42501). Worth surfacing as stuck rather than
     *          offline: the fix is `schema:reapply-grants`, and nobody would
     *          think to run it while the till says the internet is down.
     *
     * Everything else — 08xxx connection exceptions above all — stays a network
     * fault, which is the honest reading of a query that never completed.
     */
    private function isConflict(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/SQLSTATE\[(\d\d)/', $current->getMessage(), $matches) === 1
                && in_array($matches[1], ['23', '42'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{state: string, pushed: int, pulled: int, unsynced_count: int, error: string|null}
     */
    private function record(
        string $state,
        ?string $kind,
        ?string $error,
        int $pushed = 0,
        int $pulled = 0,
        bool $synced = false,
    ): array {
        $status = SyncStatus::current();

        $status->forceFill([
            'connection_state' => $state,
            'last_error_kind' => $kind,
            'last_error' => $error,
            // Held from the first cycle that failed this way, so "since when"
            // is answerable without keeping a history table.
            //
            // Keyed on the error KIND, not on the state: a `conflict` is
            // reported as `online` — the connection is fine — but it is still a
            // stuck branch and still needs a beginning. Keying on the state
            // would leave the one failure that never resolves itself as the one
            // failure with no start time.
            //
            // The null check is not belt-and-braces either: a device that has
            // never synced starts at `offline` by column default, so its first
            // real offline cycle IS a change even though the value did not move.
            'blocked_since' => $kind === null
                ? null
                : ($status->last_error_kind === $kind && $status->blocked_since !== null
                    ? $status->blocked_since
                    : now()),
        ]);

        if ($synced) {
            $status->last_sync_at = now();
        }

        $status->save();

        return [
            'state' => $state,
            'pushed' => $pushed,
            'pulled' => $pulled,
            'unsynced_count' => $this->outbox->unsyncedCount(),
            'error' => $error,
        ];
    }
}
