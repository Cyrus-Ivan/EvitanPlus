<?php

namespace App\Support\Sync;

use App\Models\SyncOutboxEntry;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * This branch's backlog of work going UP (MULTIBRANCH-BRAINSTORM.md §11.6,
 * docs/glossary.md §5).
 *
 * The outbox is **not** the inventory update queue. Two unrelated structures
 * moving in opposite directions: this holds rows the till originated and owes
 * Postgres; `inventory_update_queue` (M8) holds adjustments issued elsewhere
 * that the till owes an answer to. Reserve the bare word "queue" for that one.
 *
 * ## Pointers, not payloads
 *
 * An entry names a table and a row id. Every table it can name is immutable, so
 * the row is re-read at push time and cannot have changed since — which makes a
 * stored copy strictly worse than no copy: a second representation of one sale,
 * able only to drift.
 *
 * ## The count is derived
 *
 * `unsyncedCount()` is `count(*) where synced_at is null` and nothing else.
 * There is deliberately no counter column here or in `branch_status`: one
 * definition, in one place, with nothing to fall out of step with the rows it
 * describes.
 */
class Outbox
{
    /**
     * The group id in force for the current write, if any.
     *
     * Static rather than an instance field so it holds across the container
     * resolving this class more than once during a single sale. {@see group()}
     * always restores it, so it cannot leak past the closure that set it.
     */
    private static ?string $currentGroup = null;

    /**
     * Run $work with every row it creates sharing one push batch.
     *
     * A sale is a transaction, its items and its ledger deductions, and those
     * must land in Postgres together or not at all — a partially pushed sale
     * appears in consolidated reporting as a sale with missing lines, which is
     * indistinguishable from a sale that really had fewer lines.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public function group(Closure $work): mixed
    {
        $previous = self::$currentGroup;
        self::$currentGroup = (string) Str::uuid();

        try {
            return $work();
        } finally {
            self::$currentGroup = $previous;
        }
    }

    /**
     * Record that $model owes Postgres an insert.
     *
     * Enqueues unconditionally — there is no "is this device set up yet?" guard.
     * On a device that has never been through the wizard the only local write is
     * the wizard's own `system.setup_completed` audit row, and that row SHOULD
     * push on the first cycle: it is the record of this branch coming into
     * existence.
     */
    public function enqueue(Model $model): void
    {
        if (method_exists($model, 'shouldPush') && ! $model->shouldPush()) {
            return;
        }

        $rowId = (string) $model->getKey();

        DB::table('sync_outbox')->insertOrIgnore([
            'table_name' => $model->getTable(),
            'row_id' => $rowId,
            // Ungrouped rows are their own batch. A login or a standalone audit
            // entry has nothing to be atomic with.
            'group_id' => self::$currentGroup ?? $rowId,
            'attempts' => 0,
            'created_at' => now(),
        ]);
    }

    /** How far behind Postgres this device is. */
    public function unsyncedCount(): int
    {
        return SyncOutboxEntry::query()->whereNull('synced_at')->count();
    }

    /**
     * The next $limit groups to push, oldest first, as entries keyed by group.
     *
     * Two queries rather than one: the first picks the groups in strict sequence
     * order, the second takes every pending entry belonging to them. Selecting
     * entries directly with a LIMIT would cut a sale in half at the batch
     * boundary, which is the one thing the grouping exists to prevent.
     *
     * @return Collection<int, Collection<int, SyncOutboxEntry>>
     */
    public function pendingGroups(int $limit): Collection
    {
        $groups = SyncOutboxEntry::query()
            ->whereNull('synced_at')
            ->groupBy('group_id')
            ->orderByRaw('min(sequence)')
            ->limit($limit)
            ->pluck('group_id');

        if ($groups->isEmpty()) {
            return collect();
        }

        return SyncOutboxEntry::query()
            ->whereNull('synced_at')
            ->whereIn('group_id', $groups)
            ->orderBy('sequence')
            ->get()
            ->groupBy('group_id')
            ->toBase()
            ->map(fn (EloquentCollection $entries): Collection => $entries->toBase())
            // Restore the order the first query established; groupBy() keys by
            // first appearance, which is the same order, but sorting on the
            // minimum sequence says so rather than relying on it.
            ->sortBy(fn (Collection $entries) => $entries->min('sequence'))
            ->values();
    }

    /**
     * @param  list<int>  $sequences
     */
    public function markSynced(array $sequences): void
    {
        if ($sequences === []) {
            return;
        }

        SyncOutboxEntry::query()
            ->whereIn('sequence', $sequences)
            ->update(['synced_at' => now()]);
    }

    /**
     * Record a failed attempt without consuming the entries.
     *
     * The rows stay pending — a failed cycle leaves the backlog intact for the
     * next one (§11.4). `attempts` and `last_error` are there so a till stuck on
     * one poisonous row can be recognised as stuck rather than merely offline.
     *
     * @param  list<int>  $sequences
     */
    public function markFailed(array $sequences, string $error): void
    {
        if ($sequences === []) {
            return;
        }

        SyncOutboxEntry::query()
            ->whereIn('sequence', $sequences)
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => Str::limit($error, 1000),
                'last_attempt_at' => now(),
            ]);
    }
}
