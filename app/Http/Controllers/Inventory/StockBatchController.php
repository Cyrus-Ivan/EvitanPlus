<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockBatchRequest;
use App\Models\Instance;
use App\Models\Variant;
use App\Support\Audit\AuditRecorder;
use App\Support\Http\FilterText;
use App\Support\Inventory\AdjustmentQueue;
use App\Support\Inventory\StockBatch;
use App\Support\Inventory\StockLedger;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use App\Support\Sync\RemoteStock;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A STOCK BATCH: many movements asked for as one act (docs/glossary.md §4, M13).
 *
 * Twelve products moving between two branches was already possible before this —
 * twelve times through the transfer dialog, producing twelve unrelated pending
 * rows and twelve copies of the same note for the receiving branch to read. That
 * is not what happened. One delivery left one shop, and `batch_id` is the name of
 * that one act: every row this controller writes carries it, Pending Stocks
 * groups a card by it, and the ledger keeps it so Stock History can still say
 * "these came off the shelf together" on a till with no connection.
 *
 * Since schema 24 the act has a second name beside the uuid — a batch reference
 * like `MNL-B-000001` ({@see StockBatch}), which is the half a person can read
 * off a card, write on a delivery note and filter Stock History by.
 *
 * ## It asks; it never moves stock
 *
 * Like every other issuing path since schema 21, this writes `pending` rows to
 * `inventory_update_queue` through {@see AdjustmentQueue} and stops. A person at
 * the affected branch turns them into ledger entries ({@see AdjustmentResolver}),
 * and a batch is answered as an ordinary set of decisions through the existing
 * `stock-updates.resolve` — there is no batch-shaped write path on the answering
 * side and deliberately so: the resolver re-reads every row filtered to its own
 * branch, and a shortcut past that is a shortcut past the only thing stopping one
 * branch writing another's stock.
 *
 * Nothing local is written at all, which is why there is no `DB::transaction`
 * here and why the whole thing is refused offline by {@see OnlineWrite}.
 *
 * ## A transfer batch writes only the SENDING legs
 *
 * One per line, negative. The receiving legs are spawned one at a time by
 * `AdjustmentResolver::secondLeg()` as the sender confirms what actually left,
 * and each inherits the `batch_id` so the receiving branch sees one card too.
 * Writing both halves up front would be asserting that stock arrived before
 * anyone had said it left.
 */
class StockBatchController extends Controller
{
    /** How many products the picker offers for one search. */
    private const SEARCH_LIMIT = 25;

    public function __construct(
        private readonly OnlineWrite $online,
        private readonly OnlineRead $read,
        private readonly AdjustmentQueue $queue,
        private readonly StockLedger $ledger,
    ) {}

    public function store(StoreStockBatchRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validated();

        // The uuid is known now; the readable name is minted inside the write,
        // where the business database is open. Both are read back off the object
        // once the write has committed.
        $batch = new StockBatch;

        $rows = $data['kind'] === 'transfer'
            ? $this->transferRows($data)
            : $this->adjustmentRows($data);

        $this->online->run(
            fn () => $this->queue->enqueue($rows, $request->user(), $batch),
            'A stock batch',
        );

        $audit->record(
            action: 'inventory.stock.batched',
            // No `auditable`: a batch is about many variants and none of them is
            // the subject. What it is about is in the payload.
            auditable: null,
            description: sprintf(
                'Asked for a stock %s covering %d product(s).',
                $data['kind'],
                count($rows),
            ),
            new: [
                'batch_id' => $batch->id,
                'batch_reference' => $batch->reference,
                'kind' => $data['kind'],
                'mode' => $data['mode'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'from_branch_id' => $data['from_branch_id'] ?? null,
                'to_branch_id' => $data['to_branch_id'] ?? null,
                'lines' => count($rows),
                'notes' => $data['notes'] ?? null,
            ],
        );

        // The batch is named here and nowhere else in the flow: this is the
        // moment somebody would write it on a delivery note, and the card that
        // carries it afterwards is at the OTHER branch.
        $named = $batch->reference === null ? '' : ' ('.$batch->reference.')';

        return back()->with('toast', [
            'type' => 'success',
            'message' => $data['kind'] === 'transfer'
                ? 'Transfer'.$named.' requested for '.count($rows).' product(s). The sending branch confirms it first, then the receiving branch.'
                : 'Adjustment'.$named.' requested for '.count($rows).' product(s). Somebody at that branch confirms it before stock moves.',
        ]);
    }

    /**
     * Products the batch dialog may put on a line, with the target branch's stock.
     *
     * JSON rather than a prop for the same reason as `stock-updates.branches`:
     * this feeds a dialog, and shipping the whole catalog with every Inventory
     * page render would cost every page view a payload the size of the business.
     *
     * The catalog search is LOCAL in every case — the catalog is mirrored
     * business-wide, so the local rows are the upstream rows. Only the stock
     * figure is scoped, and only when the branch asked for is not this one; that
     * is one online read over at most {@see SEARCH_LIMIT} ids, and it falls back
     * to no figure at all rather than to this device's, which would be another
     * branch's shelf reported as ours.
     */
    public function variants(Request $request): JsonResponse
    {
        $search = FilterText::orNull($request->query('search'));
        $branch = (string) $request->query('branch', '');

        $variants = Variant::query()
            ->active()
            ->join('items', 'items.id', '=', 'variants.item_id')
            ->whereNull('items.archived_at')
            ->with(['item:id,name'])
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->whereLikeAny(['variants.sku', 'variants.barcode', 'variants.name'], $search)
                ->orWhereLike('items.name', $search)))
            ->orderBy('items.name')
            ->orderBy('variants.created_at')
            // Load-bearing, as on the Inventory page: without it the join's
            // `items.id` lands on the model as `id` and every option carries an
            // item id where a variant id belongs.
            ->select('variants.*')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        $stock = $this->stockFor($branch, $variants->pluck('id')->map(fn ($id): string => (string) $id)->all());

        return response()->json([
            'variants' => $variants->map(fn (Variant $variant): array => [
                'id' => (string) $variant->getKey(),
                'item' => $variant->item?->name,
                'variant' => $variant->name,
                'sku' => $variant->sku,
                // Barcode is a SEARCH key (see the query below), not a shipped
                // field: the picker matches on it but renders item/variant/sku.
                // Null means "we could not find out", which the dialog says
                // rather than printing a zero somebody would act on.
                'stock' => $stock === null ? null : ($stock[(string) $variant->getKey()] ?? 0),
            ])->all(),
        ]);
    }

    /**
     * The stock figures to show beside each option, for whichever branch the
     * dialog is aimed at.
     *
     * @param  list<string>  $variantIds
     * @return array<string, int>|null Null when a scoped read was attempted and failed.
     */
    private function stockFor(string $branch, array $variantIds): ?array
    {
        if ($variantIds === []) {
            return [];
        }

        if ($branch === '' || $this->isThisBranch($branch)) {
            return Variant::query()
                ->whereIn('id', $variantIds)
                ->with('stockSnapshot')
                ->get()
                ->mapWithKeys(fn (Variant $variant): array => [
                    (string) $variant->getKey() => $variant->current_stock,
                ])
                ->all();
        }

        return rescue(
            fn (): array => $this->read->run(
                fn (Connection $remote): array => RemoteStock::read($remote, $branch, $variantIds),
                'Stock at that branch',
            ),
            null,
            report: false,
        );
    }

    /**
     * One pending row per line, all aimed at the same branch.
     *
     * `set` becomes `wanted - on hand`, which is why the request refuses it for
     * any branch but this one: the subtraction is only as true as the figure
     * under it. A line that would move nothing is refused rather than dropped —
     * silently skipping it would leave somebody looking for a row that is not
     * there, and `StockAdjustmentController` refuses a no-op for one variant on
     * exactly the same grounds.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function adjustmentRows(array $data): array
    {
        $variants = Variant::query()
            ->with('item')
            ->whereIn('id', array_column($data['lines'], 'variant_id'))
            ->get()
            ->keyBy(fn (Variant $variant): string => (string) $variant->getKey());

        $target = (string) $data['branch_id'];
        $rows = [];

        foreach ($data['lines'] as $index => $line) {
            $variant = $variants->get((string) $line['variant_id']);
            $quantity = (int) $line['quantity'];

            $delta = match ($data['mode']) {
                'add' => $quantity,
                'remove' => -$quantity,
                // Only reachable for this branch, so the on-hand figure is this
                // device's own and is the reconciled one it sells by.
                default => $quantity - ($variant === null ? 0 : $this->ledger->currentStock($variant)),
            };

            if ($delta === 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => sprintf(
                        '%s is already at %d, so this line would change nothing.',
                        $variant?->item->name ?? 'That product',
                        $quantity,
                    ),
                ]);
            }

            $rows[] = [
                'branch_id' => $target,
                // Who is asking, when the target is somebody else. Null when a
                // branch is recounting its own shelf — there is no counterparty.
                'counterparty_branch_id' => $target === $this->queue->thisBranch()
                    ? null
                    : $this->queue->thisBranch(),
                'variant_id' => (string) $line['variant_id'],
                'quantity' => $delta,
                'reference_type' => 'manual_adjustment',
                'notes' => $data['notes'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * One SENDING leg per line. See the class docblock for why only one half.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function transferRows(array $data): array
    {
        return array_map(fn (array $line): array => [
            'branch_id' => (string) $data['from_branch_id'],
            'counterparty_branch_id' => (string) $data['to_branch_id'],
            'variant_id' => (string) $line['variant_id'],
            // Negative: this leg is stock LEAVING. The operator types how many
            // are moving and never a sign — see StoreStockBatchRequest.
            'quantity' => -(int) $line['quantity'],
            'reference_type' => 'transfer',
            'notes' => $data['notes'] ?? null,
        ], $data['lines']);
    }

    private function isThisBranch(string $branch): bool
    {
        return $branch === (string) Instance::current()?->branch_id;
    }
}
