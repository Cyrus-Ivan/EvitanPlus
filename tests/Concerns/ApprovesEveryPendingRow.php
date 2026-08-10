<?php

namespace Tests\Concerns;

use App\Models\Instance;
use App\Models\User;
use App\Support\Inventory\AdjustmentResolver;
use App\Support\Sync\OnlineRead;
use Illuminate\Database\Connection;

/**
 * "Approve everything waiting on this branch", for tests whose subject is what a
 * stock movement DOES once confirmed rather than the confirming.
 *
 * A test helper and not a production path, which is the whole reason it lives
 * here. `AdjustmentResolver::approveAll()` used to do this, back when the Pending
 * Stocks page was exactly "every pending row at this branch" and a server-side
 * sweep meant the same thing as the button that called it. The page filters now,
 * so the button sends the ids it is actually showing (M11.8) and the sweep has
 * no caller left in the app — but a great many tests still want the shortcut
 * they had before schema 21, when a movement happened at the moment it was asked
 * for.
 */
trait ApprovesEveryPendingRow
{
    /**
     * Answer every row waiting on this branch, as $actor would from the page.
     *
     * @return int rows resolved
     */
    private function approveEverythingPending(User $actor): int
    {
        $ids = array_map(fn (object $row): string => (string) $row->id, $this->pendingRows());

        return app(AdjustmentResolver::class)->resolve(
            array_fill_keys($ids, ['outcome' => 'approve']),
            $actor,
        );
    }

    /**
     * Every row still waiting on this branch, oldest first.
     *
     * Exposed on its own because "nothing was asked for" is now a claim tests
     * make directly — a CSV import stopped queueing anything in M13, and the only
     * way to say so is to look.
     *
     * @return list<object>
     */
    private function pendingRows(): array
    {
        $branchId = (string) Instance::current()?->branch_id;

        return app(OnlineRead::class)->run(
            fn (Connection $remote): array => $remote->table('inventory_update_queue')
                ->where('branch_id', $branchId)
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->all(),
            'Pending stock adjustments',
        );
    }
}
