<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the person who asked for a stock movement was standing (§10, M11.8).
 *
 * The queue has always recorded WHO asked (`issued_by`) and never WHERE FROM,
 * and the Pending Stocks card has to show both: a branch reading a request needs
 * to know which shop it came from, not only which colleague.
 *
 * ## It cannot be derived, and four of the five cases look like it can
 *
 * For a recount or a CSV import the issuer is at `branch_id`; for an adjustment
 * aimed at another branch they are at `counterparty_branch_id`; for a transfer's
 * receiving leg they are at the parent's `branch_id`. Every one of those is a
 * different rule, and the fifth case breaks all of them: `IssueTransferRequest`
 * takes `from_branch_id` and `to_branch_id` as free choices, so an Owner at head
 * office can arrange a move between two OTHER branches. Nothing in the row then
 * remembers head office was involved at all, and the closest guess — the sending
 * branch — is wrong silently, which is the worst way for it to be wrong.
 *
 * So it is stored. The app's `AdjustmentQueue` stamps it from `instance` on
 * every enqueue, the same way the push stamps `branch_id`. Named in prose and
 * not with a `@see`: pint turns one of those into a `use` statement, and the
 * console may not reference the app it provisions for (`BuildExclusionTest`).
 *
 * ## Additive, so the floor does not move
 *
 * Nullable, and no existing behaviour reads it: a build from before this change
 * inserts rows without it and they are merely anonymous, not wrong. Rows that
 * predate the migration show "—" for the same reason. MIN_APP_VERSION stays at
 * 1.1.0 — see `Provisioning\Support\SchemaVersion` for why raising it
 * needlessly freezes every branch of every business.
 *
 * Grants: none. INSERT and SELECT on this table are table-level (M8), so they
 * already cover a column that did not exist when they were granted. Only
 * `BranchGrants::UPDATE_COLUMNS` is enumerated per column, and nothing updates
 * this one — it is written once, at insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_update_queue', function (Blueprint $table) {
            // Beside `issued_by`, because the two are one fact: a person at a
            // place. Null on every row written before schema 22, and on any
            // written by a device whose `instance` has no branch.
            $table->foreignUuid('issued_by_branch_id')->nullable()->after('issued_by')
                ->constrained('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_update_queue', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by_branch_id');
        });
    }
};
