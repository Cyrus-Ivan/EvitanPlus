<?php

namespace App\Support\Inventory;

use App\Exceptions\NegativeInventoryException;
use App\Models\Instance;
use App\Models\InventoryLedger;
use App\Models\User;
use App\Models\Variant;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\Puller;
use App\Support\Sync\RemoteStock;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Answering a pending stock adjustment (docs/glossary.md §4 "resolution").
 *
 * A person at the affected branch says what actually happened: all of it arrived
 * (approve), some of it did (partial approve), or none of it did (reject, with a
 * reason). Only the first two move stock, and this class is the ONE place a
 * `type = 'stock_adjustment'` ledger row is now written — before this there were
 * three writers (the CSV import, the manual adjust screen, and the sync cycle's
 * step 2), which is exactly the shape single-writer-per-table exists to prevent.
 *
 * Until schema 21 the sync cycle answered these by arithmetic, accepting
 * anything that fit. That answered the wrong question. Whether stock CAN come
 * off the shelf is a sum; whether it DID is not, and the till holding the
 * quantity is not the thing that knows.
 *
 * ## One read, then one write, and the read is not optional
 *
 * The client posts ids and outcomes and nothing else. Everything that decides
 * what actually happens — which branch the row is aimed at, how much was asked
 * for, what kind of movement it is — is re-read from Postgres, filtered to THIS
 * branch's still-pending rows.
 *
 * That filter is load-bearing rather than defensive. {@see OnlineWrite} performs
 * its local writes BEFORE any connection exists and mirrors them afterwards,
 * stamping `branch_id` from `instance` — so a device permitted to resolve a row
 * aimed at another branch would write that branch's stock movement onto its own
 * ledger, at its own branch. The stock would land in the wrong shop. The old
 * step 2 could not make this mistake because the cycle handed it the branch id
 * it was already running as; a controller taking ids from a form can.
 *
 * ## Approving writes locally AND upstream, unlike the old step 2
 *
 * Step 2 wrote the ledger entry upstream only and let the pull bring it down,
 * because it ran inside a cycle that was about to pull anyway. A person pressing
 * Approve is not, and waiting up to five minutes to see the stock they just
 * agreed to would make the button look broken. So this is an ordinary
 * {@see OnlineWrite}: local ledger row and snapshot first, mirrored up in the
 * same act — the pattern the manual adjust screen has used since M7.
 *
 * That does not reintroduce a second writer. The rule is about a class of row
 * having one authoring path, not one direction of travel: the row is created
 * once, here, and copied. Nothing double-counts it either, and each leg is worth
 * naming because each could have:
 *
 *  - the outbox stays empty — `InventoryLedger::shouldPush()` is false for
 *    adjustments, so `StockReconciler`'s unsynced sum sees nothing;
 *  - {@see Puller} re-reads the row on the next cycle and `insertOrIgnore`s it;
 *  - {@see RemoteStock} recomputes from Postgres's own ledger with a `sum()`,
 *    which converges however many times it runs.
 *
 * ## The ledger id IS the queue row's id
 *
 * Kept from M8, where it was insurance against a cycle dying mid-resolution. It
 * now does a second job: two people pressing Approve on the same row at once
 * collide on the primary key, and `OnlineWrite`'s append-only conflict clause
 * turns the loser into a no-op instead of a second stock movement.
 *
 * ## Negative stock is refused here, and the old formula is gone
 *
 * `StockLedger::record()` already refuses to drive the LOCAL snapshot below
 * zero, and that is now strictly enough. Step 2 needed
 * `upstream baseline + Σ unsynced local` because it ran before this branch's own
 * writes were guaranteed up; the approver is the branch, reading its own ledger,
 * which already contains its unsynced sales. Local is the lower of the two
 * figures, so surviving it means surviving Postgres's own
 * `stock_snapshots_non_negative`.
 */
class AdjustmentResolver
{
    /** What a caller may ask for. */
    public const OUTCOMES = ['approve', 'partial', 'reject'];

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly OnlineRead $read,
        private readonly OnlineWrite $online,
        private readonly RemoteStock $stock,
    ) {}

    /**
     * Answer one or more pending rows.
     *
     * @param  array<string, array{outcome: string, quantity?: int|null, reason?: string|null}>  $decisions
     *                                                                                                       Keyed by queue row id.
     * @return int rows actually resolved — fewer than asked for when a row has
     *             since been answered elsewhere, or was never this branch's
     *
     * @throws ValidationException when an approved quantity is not a subset of
     *                             what was asked for, or when applying the batch
     *                             would drive a product below zero
     */
    public function resolve(array $decisions, User $actor): int
    {
        if ($decisions === []) {
            return 0;
        }

        $branchId = (string) Instance::current()?->branch_id;
        $rows = $this->authoritative(array_keys($decisions), $branchId);

        if ($rows->isEmpty()) {
            return 0;
        }

        $variants = Variant::query()
            ->with('item')
            ->whereIn('id', $rows->pluck('variant_id')->all())
            ->get()
            ->keyBy('id');

        $this->online->run(function () use ($rows, $decisions, $variants, $actor, $branchId): void {
            /** @var list<InventoryLedger> $entries */
            $entries = [];
            /** @var list<string> $fullyApproved */
            $fullyApproved = [];
            /** @var list<array{id: string, quantity: int|null, reason: string|null, accepted: bool}> $individual */
            $individual = [];
            /** @var list<array<string, mixed>> $onward */
            $onward = [];

            foreach ($rows as $row) {
                $decision = $decisions[(string) $row->id];
                $outcome = $decision['outcome'];
                $reason = $decision['reason'] ?? null;

                if ($outcome === 'reject') {
                    $individual[] = ['id' => (string) $row->id, 'quantity' => null, 'reason' => $reason, 'accepted' => false];

                    continue;
                }

                $approved = $outcome === 'partial'
                    ? $this->partialQuantity($row, $decision['quantity'] ?? null)
                    : (int) $row->quantity;

                $entries[] = $this->apply($row, $approved, $reason, $variants, $actor);

                if ($approved === (int) $row->quantity) {
                    $fullyApproved[] = (string) $row->id;
                } else {
                    $individual[] = ['id' => (string) $row->id, 'quantity' => $approved, 'reason' => $reason, 'accepted' => true];
                }

                if ($second = $this->secondLeg($row, $approved, $actor)) {
                    $onward[] = $second;
                }
            }

            if ($entries !== []) {
                $this->online->mirror(...$entries);
            }

            $variantIds = $rows->pluck('variant_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();

            $this->online->afterMirroring(function (Connection $remote) use (
                $fullyApproved, $individual, $onward, $actor, $branchId, $variantIds, $entries,
            ): void {
                $this->settle($remote, $fullyApproved, $individual, $actor);

                if ($onward !== []) {
                    // Timestamps from Postgres's own clock, not PHP's, for the
                    // same reason every other write to this table does: these are
                    // `timestamptz`, this app's convention elsewhere is a naive
                    // local string, and the order a branch works its queue in
                    // depends on them.
                    $remote->table('inventory_update_queue')->insert(array_map(
                        fn (array $leg): array => $leg + [
                            'created_at' => $remote->raw('CURRENT_TIMESTAMP'),
                            'updated_at' => $remote->raw('CURRENT_TIMESTAMP'),
                        ],
                        $onward,
                    ));
                }

                if ($entries !== []) {
                    // Postgres keeps no trigger on stock_snapshots, so the rows
                    // just sent change the ledger sum without changing the cached
                    // total. Recomputed from Postgres's OWN ledger, which is what
                    // makes the next cycle's baseline include these adjustments
                    // rather than revert them.
                    $this->stock->recompute($remote, $branchId, $variantIds);
                }
            });
        }, 'Stock');

        return $rows->count();
    }

    /**
     * Approve every row currently waiting on this branch.
     *
     * The bulk case exists because a CSV import is one action that produces
     * hundreds of pending rows, and answering them one at a time is not a
     * workflow. It is a full approval of each: partials and rejections are
     * per-row judgements and stay per-row.
     */
    public function approveAll(User $actor): int
    {
        $branchId = (string) Instance::current()?->branch_id;

        $ids = $this->read->run(
            fn (Connection $remote): array => $remote->table('inventory_update_queue')
                ->where('branch_id', $branchId)
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all(),
            'Pending stock adjustments',
        );

        return $this->resolve(
            array_fill_keys($ids, ['outcome' => 'approve']),
            $actor,
        );
    }

    /**
     * A transfer's receiving leg, spawned by approving its sending leg.
     *
     * ## Why a transfer is two rows and not one
     *
     * Stock leaving one shop and arriving at another are two separate physical
     * claims, made by two different people, and either can be false on its own.
     * The sender agrees that three went out of the door; the receiver agrees that
     * two came in. A single row could only record one of those, and would make
     * whichever branch did not answer it the author of the other's stock.
     *
     * So this fires exactly once, at the moment the sending branch confirms:
     * `reference_type = 'transfer'` with no parent yet. The row it creates is for
     * what the sender ACTUALLY sent, not what was originally asked for — three
     * requested, two confirmed out of the door, and the receiving branch is asked
     * about two.
     *
     * ## The shortfall is written off, deliberately
     *
     * If the receiver then approves only one, the business is a unit down: the
     * sender's stock left and it never arrived. Nothing bounces back — the unit
     * was crushed in transit, and inventing a compensating row at the sender
     * would put stock on a shelf that does not hold it. The receiver's reason,
     * carried onto their ledger row, is the record of where it went.
     *
     * @return array<string, mixed>|null
     */
    private function secondLeg(stdClass $row, int $approved, User $actor): ?array
    {
        if ((string) $row->reference_type !== 'transfer' || $row->parent_id !== null) {
            return null;
        }

        return [
            'id' => (string) Str::uuid(),
            'parent_id' => (string) $row->id,
            'branch_id' => (string) $row->counterparty_branch_id,
            // Pointing back the way it came, so the receiving branch's ledger row
            // can say which shop the stock arrived from.
            'counterparty_branch_id' => (string) $row->branch_id,
            'variant_id' => (string) $row->variant_id,
            // The sending leg is negative; what arrives is the same magnitude the
            // other way.
            'quantity' => abs($approved),
            'reference_type' => 'transfer',
            'reference_id' => null,
            'notes' => $row->notes,
            'status' => 'pending',
            // The person who put the stock in the van, which is who the receiving
            // branch is answering — not whoever first typed the transfer.
            'issued_by' => (string) $actor->getKey(),
        ];
    }

    /**
     * Re-read the rows from Postgres, filtered to what this branch may answer.
     *
     * Oldest first, for the reason §10 has always given: two subtractions that
     * each fit alone may not fit together, so which one fails has to be decided
     * by age rather than by whatever order the ids arrived in.
     *
     * @param  list<string>  $ids
     * @return Collection<int, stdClass>
     */
    private function authoritative(array $ids, string $branchId): Collection
    {
        return $this->read->run(
            fn (Connection $remote): Collection => $remote->table('inventory_update_queue as q')
                ->leftJoin('branches as b', 'b.id', '=', 'q.counterparty_branch_id')
                ->where('q.branch_id', $branchId)
                ->where('q.status', 'pending')
                ->whereIn('q.id', $ids)
                ->orderBy('q.created_at')
                ->orderBy('q.id')
                ->get(['q.*', 'b.code as counterparty_branch_code']),
            'Pending stock adjustments',
        );
    }

    /**
     * Write the agreed movement as a real ledger entry on this device.
     *
     * @param  Collection<string, Variant>  $variants
     */
    private function apply(stdClass $row, int $approved, ?string $reason, Collection $variants, User $actor): InventoryLedger
    {
        $variant = $variants->get((string) $row->variant_id);

        if ($variant === null) {
            throw ValidationException::withMessages([
                'adjustments' => 'That product is not on this device, so the adjustment cannot be applied here.',
            ]);
        }

        try {
            return $this->ledger->record(
                variant: $variant,
                quantity: $approved,
                type: 'stock_adjustment',
                saleType: null,
                referenceType: (string) $row->reference_type,
                // The queue row itself, unless something more specific was named
                // when it was asked for — a CSV import points at its import_history
                // row, which is how a movement traces back to the file it came from.
                referenceId: $row->reference_id ?? (string) $row->id,
                performedBy: $actor,
                notes: $this->notes($row, $approved, $reason),
                id: (string) $row->id,
                counterpartyBranchCode: $row->counterparty_branch_code,
            );
        } catch (NegativeInventoryException) {
            // Named, because the operator's next move is to partially approve
            // this one row and a message about "the batch" tells them nothing.
            throw ValidationException::withMessages([
                'adjustments' => sprintf(
                    'Approving %+d for %s would take it below zero, and nothing in this batch has been applied. '
                    .'Approve part of it instead, or sell-through first.',
                    $approved,
                    $variant->item->name,
                ),
            ]);
        }
    }

    /**
     * What the ledger row says about itself.
     *
     * A partial approval is the one case where the request and the movement
     * disagree, so the row has to carry both numbers — otherwise the history
     * shows two arriving and nothing anywhere says three were sent.
     */
    private function notes(stdClass $row, int $approved, ?string $reason): ?string
    {
        $parts = array_filter([
            $row->notes,
            $approved === (int) $row->quantity ? null : sprintf(
                'Partially approved: %+d of %+d asked for.',
                $approved,
                (int) $row->quantity,
            ),
            $approved === (int) $row->quantity ? null : $reason,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * A partial approval must be a strict subset of what was asked for.
     *
     * Same direction, smaller, and not nothing: approving +5 of a −3, or −4 of a
     * −3, would let a branch invent stock it was never sent, and approving zero
     * is a rejection wearing the wrong word.
     */
    private function partialQuantity(stdClass $row, ?int $quantity): int
    {
        $requested = (int) $row->quantity;

        if ($quantity === null || $quantity === 0
            || ($quantity <=> 0) !== ($requested <=> 0)
            || abs($quantity) > abs($requested)) {
            throw ValidationException::withMessages([
                'adjustments' => sprintf(
                    'A partial approval has to be part of what was asked for — between %+d and %+d.',
                    $requested > 0 ? 1 : -1,
                    $requested,
                ),
            ]);
        }

        return $quantity;
    }

    /**
     * Record the answers on the queue rows themselves.
     *
     * Full approvals go in ONE statement, because that is the shape approve-all
     * takes and a CSV import can make it three hundred rows wide;
     * `approved_quantity` and `ledger_entry_id` are column self-references, so
     * the statement needs no per-row values. Partials and rejections carry a
     * sentence each and are individual by nature.
     *
     * `and status = 'pending'` on every one: if somebody else answered the row
     * between the read and here, theirs stands.
     *
     * Nothing sets `updated_at`, and it must not. The branch role holds
     * column-level UPDATE on exactly six columns; a seventh in the SET list is
     * `permission denied for table inventory_update_queue`, which reads as a
     * missing grant and is in fact the matrix being exactly right.
     *
     * @param  list<string>  $fullyApproved
     * @param  list<array{id: string, quantity: int|null, reason: string|null, accepted: bool}>  $individual
     */
    private function settle(Connection $remote, array $fullyApproved, array $individual, User $actor): void
    {
        if ($fullyApproved !== []) {
            $remote->table('inventory_update_queue')
                ->whereIn('id', $fullyApproved)
                ->where('status', 'pending')
                ->update([
                    'status' => 'accepted',
                    'resolved_at' => $remote->raw('CURRENT_TIMESTAMP'),
                    'resolved_by' => $actor->getKey(),
                    'approved_quantity' => $remote->raw('quantity'),
                    'ledger_entry_id' => $remote->raw('id'),
                ]);
        }

        foreach ($individual as $row) {
            $remote->table('inventory_update_queue')
                ->where('id', $row['id'])
                ->where('status', 'pending')
                ->update([
                    'status' => $row['accepted'] ? 'accepted' : 'rejected',
                    'resolved_at' => $remote->raw('CURRENT_TIMESTAMP'),
                    'resolved_by' => $actor->getKey(),
                    'resolution_reason' => $row['reason'],
                    'approved_quantity' => $row['quantity'],
                    'ledger_entry_id' => $row['accepted'] ? $row['id'] : null,
                ]);
        }
    }
}
