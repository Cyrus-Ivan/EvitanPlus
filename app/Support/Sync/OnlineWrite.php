<?php

namespace App\Support\Sync;

use App\Exceptions\OfflineWriteException;
use App\Models\Instance;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A change to ONLINE-AUTHORITATIVE data, written to the business database and
 * the local mirror as one act (MULTIBRANCH-BRAINSTORM.md §5.1, §6;
 * docs/glossary.md §5 "online write").
 *
 * ## Why this exists at all
 *
 * §5.1 says the catalog, users and settings are "edited only online … the till
 * mirrors them for offline reads and never edits them locally", and §6 says
 * users are "managed online (in-app admin, when connected)". Both sentences
 * describe this class. Neither described the code: until M6.5 every one of those
 * screens wrote to local SQLite and stopped there, so a price changed at one
 * branch was invisible to every other branch and to the business database
 * forever. No phase owned the fix, because §5.1 asserted the situation was
 * already impossible — see MULTIBRANCH-ROADMAP.md M6.5.
 *
 * ## Not the outbox
 *
 * The outbox carries what a branch AUTHORS — its sales, its returns, its logins
 * — up on its own schedule, and works offline by design. Shared data is the
 * opposite case: every branch reads it, so a change has to be agreed centrally
 * at the moment it is made. Queueing one would give these tables two writers and
 * put the business one lost device away from a price that only one till knows
 * about. Hence: online, synchronous, or refused.
 *
 * ## The ordering, and why it is local-first
 *
 * The local SQLite transaction is the OUTER one and the local write happens
 * FIRST:
 *
 *   DB::transaction                        <- outer, local
 *     local writes (normal Eloquent)       <- ids, casts, guards, audit as usual
 *     BranchCycle::run                     <- opens Postgres, releases it after
 *       version gate
 *       mirror the same rows upward
 *
 * Any failure at either end rolls the local transaction back, so the device is
 * left exactly as it was and the remedy is always "fix what the message says and
 * try again". The one residual window is a local COMMIT that fails after
 * Postgres accepted the write, which leaves Postgres ahead — self-healing,
 * because the local mirror is the one store in this system that may be thrown
 * away and re-filled. `ConnectAndHydrate` accepts the identical window for the
 * identical reason.
 *
 * Local-first, not remote-first, purely to make that window as small as
 * possible: the ids and casts are settled before the network is involved, so the
 * only thing left after Postgres answers is the commit itself.
 *
 * ## What is mirrored
 *
 * Whatever the closure declares with {@see mirror()}. The rows themselves are
 * re-read from SQLite and converted by {@see RowMapper}, the same machinery the
 * push uses, so a column added to both schemas needs no edit here.
 */
class OnlineWrite
{
    /**
     * Tables an online write may carry that are APPEND-ONLY upstream.
     *
     * They get `on conflict do nothing` rather than `do update`, and the reason
     * is a Postgres detail worth stating because it presents as the wrong
     * problem: **`ON CONFLICT DO UPDATE` requires the UPDATE privilege**, so an
     * upsert against one of these fails with `permission denied for table
     * inventory_ledger` — which reads as a missing grant and is in fact the
     * grant matrix being exactly right. A ledger entry and an import record are
     * history; nothing may rewrite them, so no UPDATE is granted and none is
     * needed.
     *
     * A CSV import is the only online write that touches either.
     *
     * @var list<string>
     */
    private const APPEND_ONLY = ['inventory_ledger', 'import_history'];

    /** Rows recorded by the closure currently running. */
    private ?array $pending = null;

    /**
     * Work to run inside the cycle once the rows are up.
     *
     * @var list<Closure(Connection): void>
     */
    private array $afterMirroring = [];

    public function __construct(
        private readonly ConnectivityProbe $probe,
        private readonly CredentialVault $vault,
        private readonly BranchCycle $cycle,
        private readonly VersionGate $gate,
        private readonly RowMapper $rows,
    ) {}

    /**
     * Run $work as an online write.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work  Performs the LOCAL writes and calls mirror() on each row.
     * @param  string  $subject  What is being changed, for the offline message ("A product", "Staff").
     * @return TReturn
     *
     * @throws OfflineWriteException When this device cannot reach the business database.
     * @throws Throwable Anything $work throws, and anything the mirroring does — the
     *                   local transaction rolls back and the caller decides what it means.
     *                   Declared because callers legitimately catch their own domain
     *                   failures around this (CsvInventoryImporter and ImportRowException).
     */
    public function run(Closure $work, string $subject = 'This'): mixed
    {
        $connection = $this->credentials($subject);

        if (! $this->probe->reachable($connection)) {
            throw OfflineWriteException::forSubject($subject);
        }

        $branchId = $this->branchId();

        $previousRows = $this->pending;
        $previousAfter = $this->afterMirroring;

        $this->pending = [];
        $this->afterMirroring = [];

        try {
            return DB::transaction(function () use ($work, $connection, $branchId) {
                $result = $work();

                // Nothing declared: the closure changed nothing shared, which is
                // legitimate (an unchanged submit) and must not open a connection.
                if ($this->pending === [] && $this->afterMirroring === []) {
                    return $result;
                }

                $this->cycle->run($connection, function (Connection $remote) use ($branchId): void {
                    // A build below the schema's floor must not write in EITHER
                    // direction — a stale write is corruption the same way a
                    // stale push is (§13).
                    $this->gate->assert($this->gate->read($remote)['min_app_version']);

                    foreach ($this->pending as $table => $ids) {
                        $this->mirrorTable($remote, (string) $table, array_values(array_unique($ids)), $branchId);
                    }

                    foreach ($this->afterMirroring as $follow) {
                        $follow($remote);
                    }
                });

                return $result;
            });
        } finally {
            $this->pending = $previousRows;
            $this->afterMirroring = $previousAfter;
        }
    }

    /**
     * Run $work inside the cycle, after this write's rows have landed.
     *
     * One caller: the CSV import, which has to recompute Postgres's stock from
     * the ledger rows it just sent ({@see RemoteStock}). It cannot be done in
     * the closure — no connection is open yet — and it cannot be done before
     * mirroring, because the rows it sums would not be there.
     *
     * @param  Closure(Connection): void  $work
     */
    public function afterMirroring(Closure $work): void
    {
        if ($this->pending === null) {
            throw new RuntimeException('OnlineWrite::afterMirroring() was called outside an online write.');
        }

        $this->afterMirroring[] = $work;
    }

    /**
     * Declare that these rows belong in the business database.
     *
     * Ambient, like {@see Outbox::group()}, so a controller can record a row at
     * the point it writes it rather than assembling a list to hand back.
     */
    public function mirror(Model ...$models): void
    {
        if ($this->pending === null) {
            throw new RuntimeException('OnlineWrite::mirror() was called outside an online write.');
        }

        foreach ($models as $model) {
            $this->pending[$model->getTable()][] = (string) $model->getKey();
        }
    }

    /**
     * Is an online write possible right now?
     *
     * For the screens themselves, so they can say so before the operator types a
     * form rather than after.
     */
    public function possible(): bool
    {
        return $this->probe->online();
    }

    /**
     * `insert ... on conflict ("id") do update set ...`.
     *
     * Update semantics, unlike the push. These tables are deliberately NOT
     * immutable — a price changes, a product is archived, a user's role moves —
     * so `do update` is both legal and the entire point. `Pusher` needs the
     * opposite for the opposite reason, which is why the conflict clause is the
     * only thing the two do not share.
     *
     * `id` is the conflict target rather than a bare `do nothing`: naming it
     * means a collision on some OTHER unique index — a duplicate SKU, a second
     * Owner — raises instead of being quietly absorbed. Those are exactly the
     * constraints the business relies on.
     *
     * @param  list<string>  $ids
     */
    private function mirrorTable(Connection $remote, string $table, array $ids, string $branchId): void
    {
        $payload = $this->rows->outboundRows($remote, $table, $ids, $branchId);

        if ($payload === []) {
            return;
        }

        $query = $remote->table($table);

        $sql = $query->getGrammar()->compileInsert($query, $payload).' on conflict ("id") '.$this->onConflict($table, $payload[0]);

        $remote->insert($sql, Arr::flatten($payload, 1));
    }

    /**
     * The conflict action for $table: overwrite, or leave what is there.
     *
     * @param  array<string, mixed>  $sample  One outbound row, for its columns.
     */
    private function onConflict(string $table, array $sample = []): string
    {
        if (in_array($table, self::APPEND_ONLY, true)) {
            // Re-sending an identical history row is a no-op, and there is
            // nothing to update even if there were a privilege to do it with.
            return 'do nothing';
        }

        $assignments = implode(', ', array_map(
            fn (string $column): string => "\"{$column}\" = excluded.\"{$column}\"",
            array_values(array_filter(array_keys($sample), fn (string $column): bool => $column !== 'id')),
        ));

        return 'do update set '.$assignments;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OfflineWriteException
     */
    private function credentials(string $subject): array
    {
        $connection = $this->vault->open();

        if ($connection === null) {
            throw OfflineWriteException::forSubject($subject);
        }

        return $connection;
    }

    private function branchId(): string
    {
        $instance = Instance::current();

        if ($instance === null) {
            throw new RuntimeException('This device has no branch identity, so it cannot write as one.');
        }

        return (string) $instance->branch_id;
    }
}
