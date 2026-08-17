<?php

namespace App\Support\Sync;

use App\Exceptions\VersionBlockedException;
use App\Models\Instance;
use App\Models\SyncStatus;
use App\Support\Inventory\AdjustmentResolver;
use Illuminate\Database\Connection;
use Throwable;

/**
 * One sync cycle, and the only thing in the app that runs one
 * (MULTIBRANCH-BRAINSTORM.md §11.2, docs/glossary.md §5).
 *
 *     0. version gate  →  1. push  →  2. pull  →  3. heartbeat
 *
 * The order is load-bearing: push runs first so the pulled baseline already
 * reflects this branch's own movements, and the heartbeat runs last so it
 * describes the cycle that just finished. M6 built steps 0 and 1, M7 step 2,
 * M10 step 3.
 *
 * ## There used to be a step between the push and the pull
 *
 * M8 answered this branch's pending stock adjustments here, automatically,
 * accepting any that fit its reconciled stock. Schema 21 removed it: a person at
 * the branch answers them now, because whether stock CAN come off the shelf is a
 * sum and whether it DID is not
 * ({@see AdjustmentResolver}). A resolution is an ordinary
 * online write and needs no cycle, so nothing replaced it — which is why the app
 * version floor rose with that schema. A build still running the old step 2
 * against a 21 schema would auto-approve every adjustment waiting for a human.
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
 * with no way to tell which. {@see Heartbeat} copies the same four values into
 * `branch_status` so the console monitor can tell them apart too — though only
 * two of them can ever reach it, for the reason set out there.
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
        private readonly Heartbeat $heartbeat,
    ) {}

    /**
     * Run a cycle and record what happened. Never throws.
     *
     * @return array{state: string, pushed: int, pulled: int, unsynced_count: int, error: string|null}
     */
    public function run(): array
    {
        // A tried-and-failed cycle is still a try, so the probe stamp is written
        // before the first way out rather than after it. This path used to return
        // above the stamp below, leaving "when did this device last attempt
        // anything?" unanswerable for precisely the device that most needs the
        // question answered.
        SyncStatus::current()->forceFill(['last_probe_at' => now()])->save();

        try {
            $connection = $this->vault->open();
        } catch (Throwable $e) {
            // The vault's own message, not a generic one. It distinguishes two
            // situations with completely different remedies: a device that was
            // never set up, and a device holding a credential sealed under a
            // DIFFERENT install's APP_KEY — which is what a copied SQLite file
            // looks like, and which "this device has no stored branch credential"
            // actively misdirects somebody away from.
            return $this->record(SyncStatus::OFFLINE, SyncStatus::KIND_AUTH, $e->getMessage());
        }

        if ($connection === null) {
            return $this->record(
                SyncStatus::OFFLINE,
                SyncStatus::KIND_AUTH,
                'This device has no stored branch credential. Run setup with the credentials file for this branch.',
            );
        }

        $reachable = $this->probe->reachable($connection);

        if (! $reachable) {
            return $this->record(SyncStatus::OFFLINE, SyncStatus::KIND_NETWORK, 'The business database is not reachable.');
        }

        $branchId = (string) Instance::current()?->branch_id;

        try {
            /** @var array{pushed: int, pulled: int} $moved */
            $moved = $this->cycle->run($connection, function (Connection $remote) use ($branchId): array {
                try {
                    // Step 0. Read live — a schema migrated since the credentials
                    // JSON was issued still carries the old numbers there (§9).
                    $versions = $this->gate->read($remote);
                    $this->gate->assert($versions['min_app_version']);

                    $this->rememberVersions($versions);

                    // Step 1. Before the pull, so the baseline read below already
                    // reflects this branch's own movements and the reconciliation
                    // has less to subtract.
                    //
                    // A failure here is HELD rather than thrown, and re-thrown
                    // after step 2. The push and the pull are independent
                    // directions, and letting a broken one take the other down
                    // with it is what turned a single unpushable row into a
                    // branch that stopped receiving anything at all: `markFailed`
                    // leaves the rows pending on purpose and `pendingGroups`
                    // orders by `min(sequence)`, so one group that can never
                    // commit — a serial collision after hardware was replaced, a
                    // check-constraint violation, a revoked column grant — sits
                    // at the head of the outbox forever, and every later cycle
                    // threw in exactly this spot. No prices, no staff, no
                    // settings, none of the adjustments other branches approved,
                    // indefinitely, while the state read `online` because a
                    // constraint violation is a conflict rather than a network
                    // fault.
                    //
                    // Pulling after a failed push is safe by construction:
                    // `StockReconciler` computes `baseline + Σ unsynced local
                    // movements`, and unsynced is precisely what a failed push
                    // leaves behind.
                    $pushed = 0;
                    $pushFailure = null;

                    try {
                        $pushed = $this->pusher->push($remote)['rows'];
                    } catch (Throwable $e) {
                        $pushFailure = $e;
                    }

                    // Step 2. The catalog, staff and settings deltas, then this
                    // branch's stock agreed against the business's figure. This
                    // is also what brings down the adjustments OTHER branches
                    // approved, which is the only way this device learns of them.
                    $pulled = array_sum($this->puller->pull($remote, $branchId));
                    $pulled += $this->stock->reconcile($remote, $branchId);

                    // The cycle did not succeed, and must not say it did.
                    if ($pushFailure !== null) {
                        throw $pushFailure;
                    }
                } catch (Throwable $e) {
                    // Step 3 on the way out, while there still IS a connection —
                    // and this is the beat that matters most. A `version_blocked`
                    // branch is precisely the one somebody is looking for after a
                    // migration raised the floor, and it is invisible to the
                    // console unless it says so itself.
                    $this->beat($remote, $branchId, $this->classify($e)[0], $e->getMessage(), synced: false);

                    // The outer catch still decides what the TILL records. One
                    // failure, classified once, reported to both sides.
                    throw $e;
                }

                // Step 3. Last, so it describes the cycle that just finished
                // rather than the one about to start.
                $this->beat($remote, $branchId, SyncStatus::ONLINE, null, synced: true);

                return ['pushed' => $pushed, 'pulled' => $pulled];
            });
        } catch (Throwable $e) {
            [$state, $kind] = $this->classify($e);

            return $this->record($state, $kind, $e->getMessage());
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
     * Step 3, and it may never be the reason a cycle failed.
     *
     * Swallows everything: a heartbeat is a report ABOUT the cycle, so letting
     * one throw would either mask the failure it was sent to describe or turn a
     * finished cycle into a failed one over a row nobody at the till can see.
     * The cost of losing a beat is one stale row in the monitor, which the age
     * of `last_seen_at` already makes visible.
     */
    private function beat(
        Connection $remote,
        string $branchId,
        string $state,
        ?string $error,
        bool $synced,
    ): void {
        try {
            $this->heartbeat->upsert($remote, $branchId, $state, $error, $synced);
        } catch (Throwable) {
            // Deliberately nothing. See above.
        }
    }

    /**
     * What went wrong, in the vocabulary both sides share.
     *
     * One decision with two readers — the local `sync_status` row and the
     * heartbeat — because a failure the till calls `unauthorized` and the
     * console calls `offline` is worse than either answer alone: the two screens
     * disagree and neither is obviously wrong.
     *
     * @return array{0: string, 1: string} state, error kind
     */
    private function classify(Throwable $e): array
    {
        if ($e instanceof VersionBlockedException) {
            return [SyncStatus::VERSION_BLOCKED, SyncStatus::KIND_VERSION];
        }

        if ($this->isUnauthorized($e)) {
            return [SyncStatus::UNAUTHORIZED, SyncStatus::KIND_AUTH];
        }

        // Connected, gated, and then refused: the branch is online and its
        // backlog is STUCK. Reporting `offline` here would tell the shop to wait
        // for a connection they already have, and nothing would ever change.
        return $this->isConflict($e)
            ? [SyncStatus::ONLINE, SyncStatus::KIND_CONFLICT]
            : [SyncStatus::OFFLINE, SyncStatus::KIND_NETWORK];
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
