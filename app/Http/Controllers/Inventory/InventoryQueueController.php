<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Concerns\OffersBranchScope;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pending Stocks (§10, M8; reworked M11.8).
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
    use OffersBranchScope;

    /** Enough work to review; this is not an audit log. */
    private const LIMIT = 200;

    /** The shared branch scope vocabulary. See normalizeScope(). */
    private const SCOPE_LOCAL = 'local';

    private const SCOPE_ALL = 'all';

    /** A request is a transfer, or it is not. See normalizeKind(). */
    private const KIND_TRANSFER = 'transfer';

    private const KIND_ADJUSTMENT = 'adjustment';

    public function __construct(
        private readonly OnlineRead $read,
        private readonly OnlineWrite $online,
        private readonly AdjustmentQueue $queue,
    ) {}

    /**
     * Unfinished work, as requests rather than rows (M11.8).
     *
     * Props rather than a fetch, which is what makes the write paths free: every
     * one of them already ends in `back()`, so answering a row re-renders this
     * page with the queue as it now stands. Nothing on this device is read here —
     * the online read is the whole of the page — so there is no render-path cost
     * to spread out, which is the one thing that would argue for JSON (and does,
     * for {@see branches()}: that roster hangs off a dialog on the screen a
     * cashier lives on).
     *
     * ## One list, and what it contains is decided by "is anybody still waiting"
     *
     * The query is every `pending` row in the business, and a REQUEST is a group
     * of rows sharing `parent_id ?? id` — which is one row for an adjustment and
     * two for a transfer. A group is here because at least one of its rows is
     * pending, and that single rule produces the whole of what the page shows:
     *
     * - an answered adjustment leaves;
     * - a transfer whose sender has confirmed stays, with the sender's answer
     *   shown, until the receiver answers too;
     * - a transfer refused by its sender leaves, because refusing it spawns no
     *   receiving leg and so leaves nobody waiting.
     *
     * Answered work is not hidden behind a toggle any more, it is somewhere else:
     * every answer now writes a ledger row (a rejection writes one of zero — see
     * {@see AdjustmentResolver}), and branch-scoped Stock History is where a
     * branch reads the outcome of what it asked another branch for. This page had
     * carried a "Sent to other branches" section purely because that was
     * impossible; it is possible now, and the section is gone.
     *
     * ## Oldest first, unchanged
     *
     * That is the order the work should be done in, and two subtractions that each
     * fit alone may not fit together.
     */
    public function index(Request $request): Response
    {
        $branchId = $this->branchId();
        $scope = $this->normalizeScope($request->query('scope'));
        $kind = $this->normalizeKind($request->query('kind'));
        $filters = ['scope' => $scope, 'kind' => $kind];

        // The `null` is the rescue's fallback, not this closure's: a read that
        // failed is a device with no connection, which is a different answer from
        // a queue with nothing in it.
        $data = rescue(fn (): array => $this->read->run(function (Connection $remote) use ($kind): array {
            $pending = $this->pending($remote, $kind);

            return [
                'branches' => BranchRoster::read($remote),
                'rows' => [...$pending, ...$this->parents($remote, $pending)],
            ];
        }, 'Branch stock updates'), null, report: false);

        if ($data === null) {
            return Inertia::render('inventory/stock-updates', [
                'requests' => [],
                'branches' => [],
                'online' => false,
                'filters' => $filters,
            ]);
        }

        $requests = $this->describe($data['rows'], $data['branches'], $branchId);

        return Inertia::render('inventory/stock-updates', [
            'requests' => $this->inScope($requests, $scope === self::SCOPE_LOCAL ? $branchId : $scope),
            // Every branch but this one: the picker's first entry is already this
            // branch, as `local` (see OffersBranchScope). Reused from the read
            // just made rather than fetched again.
            'branches' => $this->pickerBranches($this->read, array_values($data['branches']), true),
            'online' => true,
            'filters' => $filters,
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
     *
     * ## "Approve all" comes through here too, and no longer has a route of its own
     *
     * It used to be a server-side sweep of every pending row at this branch,
     * which was right when the page showed exactly that. The page now filters, so
     * a sweep would answer rows nobody could see — the button says "all" and the
     * only honest reading of it is "all of what is on screen". The client sends
     * those ids as ordinary approvals ({@see ResolveAdjustmentRequest} caps a
     * submit at 500, and a page holds at most 200).
     *
     * That is no less safe than the sweep it replaces: this path re-reads every
     * id from Postgres filtered to this branch's still-pending rows before it
     * writes anything, so a stale list resolves fewer rows rather than wrong ones.
     */
    public function resolve(ResolveAdjustmentRequest $request, AdjustmentResolver $resolver, AuditRecorder $audit): RedirectResponse
    {
        $decisions = $request->decisions();
        $resolved = $resolver->resolve($decisions, $request->user());

        return $this->resolved($resolved, count($decisions), $audit, $request->user());
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
     * Every row in the business still waiting on somebody.
     *
     * Business-wide and not branch-scoped: which branch's work a person is
     * looking at is the `scope` filter's job, applied after grouping, because a
     * transfer's involvement spans two rows and SQL would have to see both to
     * decide it.
     *
     * @return list<object>
     */
    private function pending(Connection $remote, ?string $kind): array
    {
        return $remote->table('inventory_update_queue')
            ->where('status', 'pending')
            // A kind IS `reference_type = 'transfer'` or the absence of it, so
            // this one filter can stay in SQL. Both legs of a transfer carry the
            // same value, so it never splits a group in half.
            ->when($kind === self::KIND_TRANSFER, fn ($query) => $query->where('reference_type', 'transfer'))
            ->when($kind === self::KIND_ADJUSTMENT, fn ($query) => $query->where('reference_type', '!=', 'transfer'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->all();
    }

    /**
     * The already-answered sending legs behind any pending receiving legs.
     *
     * The only rows a group can be missing. A transfer's receiving leg exists
     * ONLY because its sending leg was approved ({@see AdjustmentResolver}), so a
     * pending child always has a resolved parent, and a pending parent has no
     * child at all. Nothing else in the queue has a sibling.
     *
     * Fetched because the card is about the transfer rather than about one leg of
     * it: the branch being asked to receive stock needs to see how much the
     * sender confirmed actually left, who confirmed it and when.
     *
     * @param  list<object>  $pending
     * @return list<object>
     */
    private function parents(Connection $remote, array $pending): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (object $row): ?string => $row->parent_id === null ? null : (string) $row->parent_id,
            $pending,
        ))));

        if ($ids === []) {
            return [];
        }

        return $remote->table('inventory_update_queue')->whereIn('id', $ids)->get()->all();
    }

    /**
     * Group the rows into requests and put names on them, from the LOCAL mirror.
     *
     * The catalog and every user are mirrored on this device already, so the
     * remote query stays one flat select with no joins — and a request whose
     * variant or issuer has since been archived still renders, because both
     * lookups include the archived.
     *
     * Branch names come from {@see BranchRoster} rather than the picker's roster,
     * because that one hides disabled branches: a branch disabled after it was
     * sent an adjustment cannot be offered a new one, but the row it was already
     * sent still has to say whose it is.
     *
     * ## The grouping key, and why it grew a third term in M13
     *
     * `batch_id ?? parent_id ?? id`, read outside in:
     *
     * - a STOCK BATCH is one card, however many products and however many legs
     *   it holds — the twelve rows of one delivery are one event to the person
     *   confirming it, and twelve cards repeating one note is the page failing to
     *   say that;
     * - failing that, a transfer's two legs meet under the sending leg's id;
     * - failing that, a row is a group of one.
     *
     * Because a batch's legs may now span many products, the product moved from
     * the request onto the LEG. A card names its branches and its note once, and
     * every line underneath names what it is about.
     *
     * @param  list<object>  $rows
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @return list<array<string, mixed>>
     */
    private function describe(array $rows, array $branches, string $branchId): array
    {
        if ($rows === []) {
            return [];
        }

        $variants = Variant::query()
            ->with('item')
            ->whereIn('id', array_map(fn (object $row): string => (string) $row->variant_id, $rows))
            ->get()
            ->keyBy('id');

        // Name AND role, and `withTrashed()`: the person who issued a request —
        // or answered it — may since have been archived, and the card still has
        // to say who they were. Both columns in one lookup; `resolved_by` is null
        // until somebody answers.
        $users = User::query()
            ->withTrashed()
            ->whereIn('id', array_filter(array_merge(
                array_map(fn (object $row): string => (string) $row->issued_by, $rows),
                array_map(fn (object $row): ?string => $row->resolved_by === null ? null : (string) $row->resolved_by, $rows),
            )))
            ->get(['id', 'name', 'role'])
            ->keyBy('id')
            ->all();

        /** @var array<string, list<object>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $groups[(string) ($row->batch_id ?? $row->parent_id ?? $row->id)][] = $row;
        }

        $requests = [];

        foreach ($groups as $id => $legs) {
            // Lines keep the order they were asked in, and within a line the
            // sending leg comes before the receiving one. Ordering by line first
            // is what keeps a batch's send/receive pairs adjacent instead of
            // interleaved; ordering by role within it puts the leg that has an
            // answer above the leg that is waiting on it.
            //
            // `parents()` can only ever return a sending leg, so a group whose
            // first row has a parent is one whose parent was filtered out by
            // `kind` — impossible today, and cheap to be right about.
            $order = array_flip(array_values(array_unique(array_map(
                fn (object $leg): string => (string) ($leg->parent_id ?? $leg->id),
                $legs,
            ))));

            usort($legs, fn (object $a, object $b): int => [
                $order[(string) ($a->parent_id ?? $a->id)], $a->parent_id === null ? 0 : 1,
            ] <=> [
                $order[(string) ($b->parent_id ?? $b->id)], $b->parent_id === null ? 0 : 1,
            ]);

            $request = $this->request($id, $legs, $branches, $variants, $users, $branchId);

            if ($request !== null) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * One card's worth: the request, its branches, and a leg per answer needed.
     *
     * @param  list<object>  $legs
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @param  Collection<string, Variant>  $variants
     * @param  array<string, User>  $users
     * @return array<string, mixed>|null
     */
    private function request(string $id, array $legs, array $branches, Collection $variants, array $users, string $branchId): ?array
    {
        $first = $legs[0] ?? null;

        if ($first === null) {
            return null;
        }

        $issuer = $users[(string) $first->issued_by] ?? null;
        $transfer = (string) $first->reference_type === 'transfer';

        // What each line was originally asked for, so a receiving leg can show
        // it beside what the sender actually confirmed. Keyed by line, which is
        // the sending leg's id — the same key the legs were just ordered by.
        $asked = [];

        foreach ($legs as $leg) {
            if ($leg->parent_id === null) {
                $asked[(string) $leg->id] = (int) $leg->quantity;
            }
        }

        $lines = count(array_unique(array_map(
            fn (object $leg): string => (string) ($leg->parent_id ?? $leg->id),
            $legs,
        )));

        return [
            'id' => $id,
            'kind' => $transfer ? self::KIND_TRANSFER : self::KIND_ADJUSTMENT,
            // How it was asked for: manual_adjustment | csv_import | transfer.
            'source' => (string) $first->reference_type,
            // Non-null when these rows were asked for as ONE act. `size` counts
            // LINES, not legs: a transfer of twelve products is twelve, whether
            // it is showing twelve sending legs or twenty-four of both kinds.
            'batch' => $first->batch_id === null ? null : [
                'id' => (string) $first->batch_id,
                // What the card PRINTS — `MNL-B-000001`, the name whoever issued
                // this batch was told when they issued it. Null on a batch
                // written before schema 24, which the card falls back from.
                'reference' => $first->batch_reference === null ? null : (string) $first->batch_reference,
                'size' => $lines,
            ],
            // One note for the whole card. Every row of a batch carries the same
            // string, and each leg of a transfer carries the requester's — so the
            // first leg's is the request's, whichever shape this is.
            'notes' => $first->notes,
            'issued_by' => [
                // The queue is read from Postgres and the names come from this
                // device's mirror, so the two can legitimately disagree for one
                // cycle — a user created at another branch minutes ago.
                'name' => $issuer === null ? 'Unknown user' : $issuer->name,
                'role' => $issuer?->role->value,
                // Null on rows written before schema 22, which is what the column
                // being nullable means here.
                'branch' => $this->branchRef($first->issued_by_branch_id ?? null, $branches),
                'at' => $this->localTime($first->created_at),
            ],
            // A transfer names both ends; an adjustment names the one branch whose
            // stock is going to move. Both come off the first leg, which for a
            // transfer is the sending one.
            'sender' => $transfer ? $this->branchRef($first->branch_id, $branches) : null,
            'receiver' => $transfer ? $this->branchRef($first->counterparty_branch_id, $branches) : null,
            'target' => $transfer ? null : $this->branchRef($first->branch_id, $branches),
            'legs' => array_map(
                fn (object $leg): array => $this->leg($leg, $transfer, $branches, $variants, $users, $branchId, $asked),
                $legs,
            ),
        ];
    }

    /**
     * One answer somebody owes, or has given, about one product.
     *
     * The product lives here rather than on the request because a stock batch's
     * legs span many of them (M13) — a card is one act, and a leg is one line of
     * it.
     *
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @param  Collection<string, Variant>  $variants
     * @param  array<string, User>  $users
     * @param  array<string, int>  $asked  What each line was first asked for, by line id.
     * @return array<string, mixed>
     */
    private function leg(
        object $leg,
        bool $transfer,
        array $branches,
        Collection $variants,
        array $users,
        string $branchId,
        array $asked,
    ): array {
        $resolver = $leg->resolved_by === null ? null : ($users[(string) $leg->resolved_by] ?? null);
        $variant = $variants->get((string) $leg->variant_id);

        return [
            'id' => (string) $leg->id,
            // Which LINE of the request this is a leg of — the sending leg's id,
            // for both halves of a transfer. A card with many products has to
            // pair each send with its own receive, and `parent_id` is not on the
            // wire: this is that pairing, already made.
            'line' => (string) ($leg->parent_id ?? $leg->id),
            'role' => match (true) {
                ! $transfer => 'target',
                $leg->parent_id === null => 'send',
                default => 'receive',
            },
            'item' => $variant?->item->name ?? 'Unknown product',
            'variant' => $variant?->name,
            'branch' => $this->branchRef($leg->branch_id, $branches),
            // Signed, and always this LINE's sending leg: what was originally
            // asked for. A receiving leg's own `quantity` is what the sender
            // confirmed instead, which is a different number on purpose.
            'requested' => $asked[(string) ($leg->parent_id ?? $leg->id)] ?? (int) $leg->quantity,
            'quantity' => (int) $leg->quantity,
            'approved_quantity' => $leg->approved_quantity === null ? null : (int) $leg->approved_quantity,
            'status' => (string) $leg->status,
            'resolution_reason' => $leg->resolution_reason,
            'resolved_by' => $resolver === null ? null : [
                'name' => $resolver->name,
                'role' => $resolver->role->value,
            ],
            'resolved_at' => $this->localTime($leg->resolved_at),
            // Whether THIS device may answer it. The same rule the resolver
            // enforces authoritatively — it re-reads every row filtered to this
            // branch — restated here only so the buttons appear in the right
            // place. Role is deliberately not part of it: see resolve().
            'actionable' => (string) $leg->branch_id === $branchId && (string) $leg->status === 'pending',
        ];
    }

    /**
     * A branch id as the {id, code, name} the cards print.
     *
     * @param  array<string, array{id: string, code: string, name: string}>  $branches
     * @return array{id: string, code: string, name: string}|null
     */
    private function branchRef(mixed $id, array $branches): ?array
    {
        if ($id === null) {
            return null;
        }

        return $branches[(string) $id] ?? ['id' => (string) $id, 'code' => '??', 'name' => 'Unknown branch'];
    }

    /**
     * Keep only the requests one branch is part of.
     *
     * "Part of" is deliberately wide: the branch that asked, the branch whose
     * stock moves, and both ends of a transfer. Anything narrower would hide a
     * transfer from the branch that arranged it, or an adjustment from the branch
     * that asked for it — which are exactly the people who want to know.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return list<array<string, mixed>>
     */
    private function inScope(array $requests, string $scope): array
    {
        if ($scope === self::SCOPE_ALL) {
            return $requests;
        }

        return array_values(array_filter($requests, function (array $request) use ($scope): bool {
            /** @var list<array<string, mixed>> $legs */
            $legs = $request['legs'];

            $involved = array_merge(
                [$request['issued_by']['branch']['id'] ?? null, $request['receiver']['id'] ?? null],
                array_map(fn (array $leg): ?string => $leg['branch']['id'] ?? null, $legs),
            );

            return in_array($scope, $involved, true);
        }));
    }

    /**
     * `local` (this branch), `all`, or a branch id — the shared branch scope
     * vocabulary (docs/glossary.md), as Reports, Login History and Stock History
     * use it.
     *
     * No role gate, unlike those three. Nothing here is a figure: it is a list of
     * work that has not happened, every row of which was already visible to every
     * role on the page this replaces. What still bounds ANSWERING one is the
     * branch, and that is enforced in {@see AdjustmentResolver}, not here.
     */
    private function normalizeScope(mixed $value): string
    {
        $scope = (string) $value;

        if ($scope === '' || $scope === self::SCOPE_LOCAL || $this->isThisBranch($scope)) {
            return self::SCOPE_LOCAL;
        }

        return $scope === self::SCOPE_ALL || Str::isUuid($scope) ? $scope : self::SCOPE_LOCAL;
    }

    /** `transfer`, `adjustment`, or null for both. */
    private function normalizeKind(mixed $value): ?string
    {
        $kind = (string) $value;

        return in_array($kind, [self::KIND_TRANSFER, self::KIND_ADJUSTMENT], true) ? $kind : null;
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
