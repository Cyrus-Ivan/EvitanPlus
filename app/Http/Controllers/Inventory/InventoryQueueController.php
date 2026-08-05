<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IssueStockAdjustmentRequest;
use App\Http\Requests\Inventory\IssueTransferRequest;
use App\Http\Requests\Inventory\ResolveAdjustmentRequest;
use App\Models\Instance;
use App\Models\User;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Inventory\AdjustmentQueue;
use App\Support\Inventory\AdjustmentResolver;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\RowMapper;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pending stock adjustments (§10, M8).
 *
 * `/inventory/stock-updates` is a page in its own right, reached from the
 * sidebar. It spent one milestone as a dialog on Stock History, on the reasoning
 * that a queue row is a movement that has not happened yet and belongs on top of
 * the history it is about to join — true, and beside the point once every stock
 * movement in the system starts here: this is the screen somebody has to visit
 * before stock moves at all, and a screen like that is not a modal on another one.
 *
 * ## Why all of it is online-only, and why that is not a limitation
 *
 * Everything it shows lives in Postgres and is deliberately not mirrored:
 * `inventory_update_queue` is coordination between the issuing user and a
 * branch, and `branches` is a roster the till has no business holding — it knows
 * which branch it *is*, from `instance`. Both are named in
 * `SchemaParityTest::POSTGRES_ONLY` with those reasons.
 *
 * So this is an {@see OnlineRead}: ask, render, keep nothing. That is not a
 * concession, because the operation itself cannot happen offline either. An
 * adjustment aimed at another branch has to be agreed centrally at the moment it
 * is made; queueing it locally would put the business one lost device away from a
 * stock movement only one till ever knew about.
 *
 * ## What this controller does NOT do
 *
 * It never writes a ledger entry. Answering a row moves stock, and that belongs
 * to {@see AdjustmentResolver} — one class, because it is now the only writer of
 * `type = 'stock_adjustment'` anywhere and single-writer-per-table is what keeps
 * last-write-wins out of this system. This controller issues, displays, and
 * hands decisions to that class.
 *
 * ## Every branch's rows go through here, including this one's
 *
 * Until schema 21 only an adjustment aimed at ANOTHER branch was queued; one
 * aimed at this device was a direct online write, on the grounds that a device
 * can see its own backlog and so needs no mediation. That reasoning was about
 * arithmetic, and arithmetic was never the hard part — a recount is a claim about
 * a shelf somebody has to go and look at. So the exception is gone, and
 * {@see StockAdjustmentController} now asks rather than writes.
 */
class InventoryQueueController extends Controller
{
    /** Enough history to review; this is not an audit log. */
    private const LIMIT = 200;

    public function __construct(
        private readonly OnlineRead $read,
        private readonly OnlineWrite $online,
        private readonly AdjustmentQueue $queue,
    ) {}

    /**
     * The pending list.
     *
     * Props rather than a fetch, which is what makes the write paths free: every
     * one of them already ends in `back()`, so answering a row re-renders this
     * page with the queue as it now stands. Nothing on this device is read here —
     * the online read is the whole of the page — so there is no render-path cost
     * to spread out, which is the one thing that would argue for JSON (and does,
     * for {@see branches()}: that roster hangs off a dialog on the screen a
     * cashier lives on).
     *
     * ## Two sections, because they answer two different questions
     *
     * - `inbox` — rows aimed at THIS branch and still pending. These are the ones
     *   somebody here has to answer, so they are ordered OLDEST FIRST: that is the
     *   order they should be worked, and two subtractions that fit one at a time
     *   may not fit together.
     * - `elsewhere` — rows aimed at other branches, newest first. Informational.
     *   This is the whole of what the old page showed, and it is the only place an
     *   issuer ever learns their adjustment was turned down: a rejection writes no
     *   ledger row, and could not reach them if it did, because `Puller` filters
     *   `inventory_ledger` to this branch.
     *
     * `?resolved=1` swaps the inbox for this branch's already-answered rows. They
     * are hidden by default — an answered row's movement is in Stock History, and
     * a list that keeps them is a list nobody can see the work in. On the URL and
     * not in client state, so `back()` from an answer returns to the list the
     * person was looking at.
     */
    public function index(Request $request): Response
    {
        $branchId = $this->branchId();
        $resolved = $request->boolean('resolved');

        // The `null` is the rescue's fallback, not this closure's: a read that
        // failed is a device with no connection, which is a different answer from
        // a queue with nothing in it.
        $data = rescue(fn (): array => $this->read->run(function (Connection $remote) use ($branchId, $resolved): array {
            return [
                'branches' => BranchRoster::read($remote),
                'inbox' => $this->inbox($remote, $branchId, $resolved),
                'elsewhere' => $this->elsewhere($remote, $branchId),
            ];
        }, 'Branch stock updates'), null, report: false);

        if ($data === null) {
            return Inertia::render('inventory/stock-updates', [
                'inbox' => [],
                'elsewhere' => [],
                'online' => false,
                'resolved' => $resolved,
            ]);
        }

        return Inertia::render('inventory/stock-updates', [
            'inbox' => $this->describe($data['inbox'], $data['branches']),
            'elsewhere' => $this->describe($data['elsewhere'], $data['branches']),
            'online' => true,
            'resolved' => $resolved,
        ]);
    }

    /**
     * The branch roster, for the target picker in the adjust dialog.
     *
     * A JSON route fetched when that dialog opens, rather than a prop on the
     * inventory page. `ConnectivityProbe` deliberately never opens Postgres, and
     * the inventory list is the screen a cashier lives on — putting a roster read
     * in its render would make every page view occupy a Supavisor client slot,
     * which is the exact pressure {@see BranchCycle} exists to
     * relieve.
     */
    public function branches(): JsonResponse
    {
        $branchId = $this->branchId();

        $branches = rescue(
            fn (): array => $this->read->run(
                fn (Connection $remote): array => $this->roster($remote, $branchId),
                'The branch list',
            ),
            [],
            report: false,
        );

        return response()->json(['branches' => $branches]);
    }

    /**
     * Queue an adjustment against another branch.
     *
     * Through {@see OnlineWrite} rather than {@see OnlineRead} even though
     * nothing local is written: that class already owns the probe, the version
     * gate and one owned connection, and a build below the schema's floor must
     * not write in either direction. `mirror()` cannot be used — it re-reads rows
     * from SQLite, and there is no local row here — so the insert is registered
     * with `afterMirroring()`, which runs inside the same cycle.
     */
    public function store(IssueStockAdjustmentRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validated();
        $variant = Variant::query()->with('item')->findOrFail($data['variant_id']);

        // The operator typed how many, and picked a direction. The queue column is
        // signed, so this is where the two become one number — the same division
        // of labour as StockAdjustmentController and transfer() below.
        $quantity = $data['mode'] === 'remove' ? -$data['quantity'] : $data['quantity'];

        $this->issue([...$data, 'quantity' => $quantity], $request->user());

        $audit->record(
            action: 'inventory.stock.queued',
            auditable: $variant,
            description: sprintf('Queued a stock adjustment of %+d for another branch.', $quantity),
            new: [
                'branch_id' => $data['branch_id'],
                'quantity' => $quantity,
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Adjustment queued. That branch will apply it on its next sync.',
        ]);
    }

    /**
     * Ask for a rejected adjustment again, unchanged.
     *
     * A new row rather than a reopened one: `status` and `resolved_at` are tied by
     * a check constraint, the rejection is a fact that happened, and the queue is
     * the record of what was asked for and what came of it. Re-deciding a resolved
     * row would erase the first answer — and that answer is the only account of it
     * anywhere, since a rejection writes no ledger row.
     */
    public function reissue(string $queued, AuditRecorder $audit): RedirectResponse
    {
        $original = $this->read->run(
            fn (Connection $remote): ?object => $remote->table('inventory_update_queue')
                ->where('id', $queued)
                ->where('status', 'rejected')
                ->first(),
            'Branch stock updates',
        );

        if ($original === null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That adjustment is no longer rejected, so there is nothing to re-issue.',
            ]);
        }

        // Carried through unchanged, and this is easy to get wrong: a re-issue
        // that hard-coded `manual_adjustment` would silently turn a rejected
        // TRANSFER leg into a plain adjustment aimed at the branch that turned it
        // down, with the counterparty pointing at whoever pressed the button.
        // A re-issue is the same request again, not a new kind of one.
        $this->online->run(function () use ($original): void {
            $this->queue->enqueue([[
                'branch_id' => (string) $original->branch_id,
                'counterparty_branch_id' => $original->counterparty_branch_id === null
                    ? null
                    : (string) $original->counterparty_branch_id,
                'parent_id' => $original->parent_id === null ? null : (string) $original->parent_id,
                'variant_id' => (string) $original->variant_id,
                'quantity' => (int) $original->quantity,
                'reference_type' => (string) $original->reference_type,
                'reference_id' => $original->reference_id === null ? null : (string) $original->reference_id,
                'notes' => $original->notes,
            ]], request()->user());
        }, 'Stock');

        $audit->record(
            action: 'inventory.stock.queued',
            auditable: Variant::query()->find($original->variant_id),
            description: sprintf('Asked again for a rejected stock adjustment of %+d.', (int) $original->quantity),
            new: ['branch_id' => (string) $original->branch_id, 'reissued_from' => $queued],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Asked again. That branch has to confirm it before anything moves.',
        ]);
    }

    /**
     * Move stock from one branch to another, in two halves.
     *
     * Only the FIRST half is written here: a pending row at the SENDING branch
     * for what is about to leave. Somebody there confirms the stock really went
     * out of the door, and confirming it spawns the second row at the receiving
     * branch ({@see AdjustmentResolver}) — for what was actually sent, which is
     * not always what was asked for.
     *
     * Both ends answer for themselves, and that is the whole design. Stock
     * leaving one shop and arriving at another are two physical claims made by
     * two different people, and either can be false on its own: the van left with
     * three and two came off it intact. A transfer written as one movement would
     * make whichever branch did not answer the author of the other's stock.
     */
    public function transfer(IssueTransferRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validated();
        $variant = Variant::query()->with('item')->findOrFail($data['variant_id']);

        $this->online->run(function () use ($data, $request): void {
            $this->queue->enqueue([[
                'branch_id' => $data['from_branch_id'],
                'counterparty_branch_id' => $data['to_branch_id'],
                'variant_id' => $data['variant_id'],
                // Negative: this leg is stock LEAVING. The operator types how
                // many are moving and never a sign — see IssueTransferRequest.
                'quantity' => -$data['quantity'],
                'reference_type' => 'transfer',
                'notes' => $data['notes'] ?? null,
            ]], $request->user());
        }, 'Stock');

        $audit->record(
            action: 'inventory.stock.transfer',
            auditable: $variant,
            description: sprintf('Asked to transfer %d between branches.', $data['quantity']),
            new: [
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'quantity' => $data['quantity'],
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Transfer requested. The sending branch confirms it first, then the receiving branch.',
        ]);
    }

    /**
     * Answer pending rows aimed at THIS branch.
     *
     * Open to every role, Cashier included, and that is the point rather than an
     * oversight. The question a resolution answers is "did this stock actually
     * arrive", and the person who knows is whoever is standing in the shop — a
     * permission floor here would mean stock waiting on somebody who is not
     * there. Issuing one is still Admin+, because deciding what SHOULD move is a
     * different question from reporting what did.
     */
    public function resolve(ResolveAdjustmentRequest $request, AdjustmentResolver $resolver, AuditRecorder $audit): RedirectResponse
    {
        $decisions = $request->decisions();
        $resolved = $resolver->resolve($decisions, $request->user());

        return $this->resolved($resolved, count($decisions), $audit, $request->user());
    }

    /**
     * Approve everything currently waiting on this branch.
     *
     * Exists because a CSV import is one action that produces hundreds of rows.
     * Guarded by a confirmation dialog on the client — it moves real stock, and
     * the count is the whole of what somebody is agreeing to.
     */
    public function resolveAll(Request $request, AdjustmentResolver $resolver, AuditRecorder $audit): RedirectResponse
    {
        $resolved = $resolver->approveAll($request->user());

        return $this->resolved($resolved, $resolved, $audit, $request->user());
    }

    /**
     * The shared reply, and the audit row that goes with it.
     *
     * $asked can exceed $resolved when a row was answered on another device
     * between this one reading the list and submitting — which is not an error,
     * but is worth saying rather than reporting a number nobody recognises.
     */
    private function resolved(int $resolved, int $asked, AuditRecorder $audit, User $actor): RedirectResponse
    {
        if ($resolved === 0) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Nothing was left to answer — those adjustments have already been dealt with.',
            ]);
        }

        // The queue row records WHAT was decided (status, approved_quantity,
        // resolution_reason); this records that a person decided it, on this
        // device, at this time — which the queue's own columns cannot say once a
        // row has been read from another branch.
        $audit->record(
            action: 'inventory.stock.resolved',
            description: sprintf('Answered %d pending stock adjustment(s).', $resolved),
            new: ['resolved' => $resolved],
            actor: $actor,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $resolved === $asked
                ? sprintf('%d stock adjustment(s) answered.', $resolved)
                : sprintf('%d of %d answered — the rest had already been dealt with.', $resolved, $asked),
        ]);
    }

    /**
     * Write one pending row upstream.
     *
     * `quantity` here is the SIGNED value the queue column stores, not the
     * positive one the request carried — {@see store()} applies the sign.
     *
     * @param  array{branch_id: string, variant_id: string, quantity: int, notes?: string|null}  $data
     */
    private function issue(array $data, User $issuedBy): void
    {
        $this->online->run(function () use ($data, $issuedBy): void {
            $this->queue->enqueue([[
                'branch_id' => $data['branch_id'],
                // The branch being asked cannot see who asked, and the ledger
                // entry it may write has to say so — this is what becomes
                // `counterparty_branch_code` on that entry.
                'counterparty_branch_id' => $this->queue->thisBranch(),
                'variant_id' => $data['variant_id'],
                'quantity' => $data['quantity'],
                'reference_type' => 'manual_adjustment',
                'notes' => $data['notes'] ?? null,
            ]], $issuedBy);
        }, 'Stock');
    }

    /**
     * @return list<array{id: string, code: string, name: string, is_this_branch: bool}>
     */
    private function roster(Connection $remote, string $branchId): array
    {
        return $remote->table('branches')
            // A disabled branch never syncs, so a row aimed at one would sit
            // pending until somebody re-enabled it. Not offerable.
            ->whereNull('disabled_at')
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'is_this_branch' => (string) $row->id === $branchId,
            ])
            ->all();
    }

    /**
     * Rows this branch has to answer — or, with $resolved, the ones it already has.
     *
     * @return list<object>
     */
    private function inbox(Connection $remote, string $branchId, bool $resolved): array
    {
        return $remote->table('inventory_update_queue')
            ->where('branch_id', $branchId)
            ->when($resolved,
                fn ($query) => $query->where('status', '!=', 'pending')->orderByDesc('resolved_at'),
                // Oldest first: see the pending() docblock.
                fn ($query) => $query->where('status', 'pending')->orderBy('created_at')->orderBy('id'),
            )
            ->limit(self::LIMIT)
            ->get()
            ->all();
    }

    /**
     * Rows aimed at some other branch, whatever their status.
     *
     * @return list<object>
     */
    private function elsewhere(Connection $remote, string $branchId): array
    {
        return $remote->table('inventory_update_queue')
            ->where('branch_id', '!=', $branchId)
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->all();
    }

    /**
     * Put names on the rows, from the LOCAL mirror.
     *
     * The catalog and every user are mirrored on this device already, so the
     * remote query stays one flat select with no joins — and a row whose variant
     * or issuer has since been archived still renders, because both lookups
     * include the archived.
     *
     * Branch names come from {@see BranchRoster} rather than the picker's roster,
     * because that one hides disabled branches: a branch disabled after it was
     * sent an adjustment cannot be offered a new one, but the row it was already
     * sent still has to say whose it is.
     *
     * @param  list<object>  $rows
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @return list<array<string, mixed>>
     */
    private function describe(array $rows, array $branches): array
    {
        if ($rows === []) {
            return [];
        }

        $variants = Variant::query()
            ->with('item')
            ->whereIn('id', array_map(fn (object $row): string => (string) $row->variant_id, $rows))
            ->get()
            ->keyBy('id');

        // Names only, and `withTrashed()`: the person who issued an adjustment —
        // or answered it — may since have been archived, and the row still has to
        // say who. Both columns in one lookup; `resolved_by` is null until
        // somebody answers.
        $users = User::query()
            ->withTrashed()
            ->whereIn('id', array_filter(array_merge(
                array_map(fn (object $row): string => (string) $row->issued_by, $rows),
                array_map(fn (object $row): ?string => $row->resolved_by === null ? null : (string) $row->resolved_by, $rows),
            )))
            ->pluck('name', 'id');

        return array_map(function (object $row) use ($variants, $users, $branches): array {
            $variant = $variants->get((string) $row->variant_id);

            return [
                'id' => (string) $row->id,
                'branch' => $branches[(string) $row->branch_id]['code'] ?? 'Unknown branch',
                'item' => $variant?->item->name ?? 'Unknown product',
                'variant' => $variant?->name,
                'quantity' => (int) $row->quantity,
                'approved_quantity' => $row->approved_quantity === null ? null : (int) $row->approved_quantity,
                'reference_type' => (string) $row->reference_type,
                // The branch on the other end, when there is one. Null on most
                // rows: a branch adjusting its own stock has no counterparty.
                'counterparty_branch' => $row->counterparty_branch_id === null
                    ? null
                    : ($branches[(string) $row->counterparty_branch_id]['code'] ?? 'Unknown branch'),
                'notes' => $row->notes,
                'status' => (string) $row->status,
                'resolution_reason' => $row->resolution_reason,
                'resolved_by' => $row->resolved_by === null
                    ? null
                    : ($users[(string) $row->resolved_by] ?? 'Unknown user'),
                // The queue is read from Postgres and the names come from this
                // device's mirror, so the two can legitimately disagree for one
                // cycle — a user created at another branch minutes ago.
                'issued_by' => $users[(string) $row->issued_by] ?? 'Unknown user',
                'created_at' => $this->localTime($row->created_at),
                'resolved_at' => $this->localTime($row->resolved_at),
            ];
        }, $rows);
    }

    /**
     * A `timestamptz` as the naive business-local string every other timestamp
     * on the client is — the same conversion {@see RowMapper}
     * applies to a pulled datetime column, for the same reason: the client parses
     * these as local time.
     */
    private function localTime(mixed $value): ?string
    {
        return $value === null
            ? null
            : Carbon::parse((string) $value)
                ->setTimezone((string) config('app.timezone'))
                ->format('Y-m-d H:i:s');
    }

    private function branchId(): string
    {
        return (string) Instance::current()?->branch_id;
    }
}
