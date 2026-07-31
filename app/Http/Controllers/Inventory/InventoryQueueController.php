<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IssueStockAdjustmentRequest;
use App\Models\Instance;
use App\Models\User;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\QueueProcessor;
use App\Support\Sync\RowMapper;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock adjustments issued against ANOTHER branch (§10, M8).
 *
 * ## Why this screen is online-only, and why that is not a limitation
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
 * It never accepts, rejects, or writes a ledger entry. A queue row is answered by
 * the TARGET branch's own device, during step 2 of its sync cycle
 * ({@see QueueProcessor}), because that device holds the one
 * copy of the facts that includes its own unsynced sales. This side only issues
 * and displays.
 *
 * ## And what still belongs on the other screen
 *
 * An adjustment aimed at THIS branch stays a direct online write through
 * {@see StockAdjustmentController}: the device can see its own backlog, so
 * `StockLedger` validates it against reconciled stock immediately and refuses a
 * negative outcome on the spot. Sending it through the queue would make a shelf
 * recount wait for a cycle to buy nothing.
 */
class InventoryQueueController extends Controller
{
    /** Enough history to review; this is not an audit log. */
    private const LIMIT = 200;

    public function __construct(
        private readonly OnlineRead $read,
        private readonly OnlineWrite $online,
    ) {}

    public function index(): Response
    {
        $branchId = $this->branchId();

        $data = rescue(fn (): array => $this->read->run(function (Connection $remote) use ($branchId): array {
            return [
                'branches' => $this->roster($remote, $branchId),
                'rows' => $this->queued($remote),
            ];
        }, 'Branch stock updates'), ['branches' => [], 'rows' => []], report: false);

        return Inertia::render('inventory/stock-updates', [
            'branches' => $data['branches'],
            'queued' => $this->describe($data['rows'], $data['branches']),
            // One flag for both halves: everything on this screen came from the
            // business database, so if the read failed there is nothing to show
            // and nothing to issue.
            'online' => $data['branches'] !== [],
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

        $this->issue($data, (string) $request->user()?->getKey());

        $audit->record(
            action: 'inventory.stock.queued',
            auditable: $variant,
            description: sprintf('Queued a stock adjustment of %+d for another branch.', $data['quantity']),
            new: [
                'branch_id' => $data['branch_id'],
                'quantity' => $data['quantity'],
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Adjustment queued. That branch will apply it on its next sync.',
        ]);
    }

    /**
     * Issue a rejected adjustment again, unchanged.
     *
     * A new row rather than a reopened one: `status` and `resolved_at` are tied by
     * a check constraint, the rejection is a fact that happened, and the queue is
     * the record of what was asked for and what came of it. Re-deciding a resolved
     * row would erase the first answer.
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

        $this->issue([
            'branch_id' => (string) $original->branch_id,
            'variant_id' => (string) $original->variant_id,
            'quantity' => (int) $original->quantity,
            'notes' => $original->notes,
        ], (string) request()->user()?->getKey());

        $audit->record(
            action: 'inventory.stock.queued',
            auditable: Variant::query()->find($original->variant_id),
            description: sprintf('Re-issued a rejected stock adjustment of %+d.', (int) $original->quantity),
            new: ['branch_id' => (string) $original->branch_id, 'reissued_from' => $queued],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Adjustment queued again. That branch will apply it on its next sync.',
        ]);
    }

    /**
     * Write one pending row upstream.
     *
     * @param  array{branch_id: string, variant_id: string, quantity: int, notes: string|null}  $data
     */
    private function issue(array $data, string $issuedBy): void
    {
        $this->online->run(function () use ($data, $issuedBy): void {
            $this->online->afterMirroring(function (Connection $remote) use ($data, $issuedBy): void {
                $remote->table('inventory_update_queue')->insert([
                    'id' => (string) Str::uuid(),
                    'branch_id' => $data['branch_id'],
                    'variant_id' => $data['variant_id'],
                    'quantity' => $data['quantity'],
                    'notes' => $data['notes'] ?? null,
                    'status' => 'pending',
                    'issued_by' => $issuedBy,
                    // Postgres's clock, not PHP's: these are `timestamptz`, and
                    // the ordering the target walks the queue in depends on them.
                    'created_at' => $remote->raw('CURRENT_TIMESTAMP'),
                    'updated_at' => $remote->raw('CURRENT_TIMESTAMP'),
                ]);
            });
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
     * @return list<object>
     */
    private function queued(Connection $remote): array
    {
        return $remote->table('inventory_update_queue')
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
     * @param  list<object>  $rows
     * @param  list<array{id: string, code: string, name: string, is_this_branch: bool}>  $branches
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

        // Names only, and `withTrashed()`: the person who issued an adjustment
        // may since have been archived, and the row still has to say who asked.
        $users = User::query()
            ->withTrashed()
            ->whereIn('id', array_map(fn (object $row): string => (string) $row->issued_by, $rows))
            ->pluck('name', 'id');

        $branchNames = collect($branches)->keyBy('id');

        return array_map(function (object $row) use ($variants, $users, $branchNames): array {
            $variant = $variants->get((string) $row->variant_id);

            return [
                'id' => (string) $row->id,
                'branch' => $branchNames->get((string) $row->branch_id)['code'] ?? 'Unknown branch',
                'item' => $variant?->item->name ?? 'Unknown product',
                'variant' => $variant?->name,
                'quantity' => (int) $row->quantity,
                'notes' => $row->notes,
                'status' => (string) $row->status,
                'rejection_reason' => $row->rejection_reason,
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
