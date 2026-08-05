<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending stock adjustments answered by a person, and the transfer that needs
 * answering twice (§10).
 *
 * ## What changed, and why the shape had to
 *
 * 000017 built this table for one narrow case: an adjustment aimed at ANOTHER
 * branch, decided automatically by that branch's next sync cycle against its own
 * reconciled stock. Everything else — a recount here, a CSV import here — wrote
 * a ledger entry the moment somebody pressed the button.
 *
 * That last part is the bug. Stock that arrived on paper and stock that arrived
 * on the shelf are different things, and no arithmetic can tell them apart; only
 * a person standing at the branch can. So every stock movement now lands here
 * first and waits for somebody at the affected branch to agree, approve part of
 * it, or turn it down with a reason.
 *
 * The new columns are what that costs:
 *
 * - `reference_type` — how the movement was asked for. The same vocabulary the
 *   ledger's own column uses, so an accepted row carries it straight across:
 *   `manual_adjustment | csv_import | transfer`. Comment-only, like the ledger's
 *   three, rather than a CHECK: the value set is app vocabulary and adding to it
 *   should not need a migration.
 * - `reference_id` — the record that caused the request, when one exists. Also
 *   carried straight across to the ledger. Only the CSV import sets it, pointing
 *   at its `import_history` row so a stock movement can be traced back to the
 *   file it came from; an adjustment asked for by hand has nothing more specific
 *   to point at than the queue row itself, which is what the resolver falls back
 *   to. No FK, because the target table depends on `reference_type`.
 * - `counterparty_branch_id` — the OTHER branch, when there is one. The issuing
 *   branch for an adjustment sent here; the destination for a transfer's sending
 *   leg; the sender for its receiving leg. Null when a branch is adjusting
 *   itself, which is most rows.
 * - `parent_id` — a transfer's receiving leg points at the sending leg it was
 *   spawned from. Null everywhere else, including on the sending leg.
 * - `approved_quantity` — what was actually agreed, which is how a PARTIAL
 *   approval is recorded. Same sign as `quantity`, never larger in magnitude,
 *   and never zero (that is a rejection). Deliberately not a fourth `status`:
 *   deriving "partial" from two integers costs one nullable column, where a new
 *   status word would mean dropping and re-adding a CHECK constraint and
 *   teaching four other places a word they can already work out.
 * - `resolved_by` — who agreed. `issued_by` says who asked, and once a person
 *   rather than a cycle answers, those stop being the same user.
 *
 * And `rejection_reason` becomes `resolution_reason`, because it now carries the
 * reason for a PARTIAL approval too — "only 2 of the 3 arrived intact" is not a
 * rejection, and a column that says it is would be a word meaning two things.
 *
 * ## This one is breaking, and the floor moves with it
 *
 * Unlike 000020 this is NOT additive, and the reason is not the rename. A build
 * from before this change still runs its old step 2, which walks every pending
 * row aimed at it and AUTO-ACCEPTS the ones that fit — which is exactly the
 * behaviour this migration exists to remove. Left able to sync, one stale till
 * would silently approve every adjustment and every CSV import aimed at its
 * branch, and would accept a transfer's sending leg without ever spawning the
 * receiving leg: stock leaves one branch and arrives nowhere.
 *
 * So MIN_APP_VERSION rises to 1.1.0 alongside SCHEMA_VERSION 21, and the version
 * gate freezes those builds until they are replaced. Migrate the schema BEFORE
 * installing the new build: stamping the floor first sends every old till to
 * `version_blocked` at step 0, so none of them reaches step 2 during the
 * rollout. They keep selling offline throughout.
 *
 * Grants: `BranchGrants::UPDATE_COLUMNS` widens from four columns to six for
 * this table (`approved_quantity`, `resolved_by`). INSERT is table-level and was
 * already granted in M8, so the five new columns need no grant of their own.
 * DELETE remains granted on nothing, here and everywhere.
 *
 * `inventory_ledger` gains `counterparty_branch_code` in the same migration —
 * see the column comment below for why a code and not an id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_update_queue', function (Blueprint $table) {
            // Allowed: manual_adjustment | csv_import | transfer. Defaulted so
            // the rows 000017 already created keep the only meaning they had.
            $table->string('reference_type')->default('manual_adjustment')->after('quantity');
            $table->uuid('reference_id')->nullable()->after('reference_type');
            $table->foreignUuid('counterparty_branch_id')->nullable()->after('branch_id')
                ->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('parent_id')->nullable()->after('id')
                ->constrained('inventory_update_queue')->restrictOnDelete();
            $table->integer('approved_quantity')->nullable()->after('quantity');
            $table->foreignUuid('resolved_by')->nullable()->after('issued_by')
                ->constrained('users')->restrictOnDelete();

            $table->renameColumn('rejection_reason', 'resolution_reason');
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            // The other branch involved, by CODE and not by id, because this
            // column exists to be READ on a till that is offline. `branches` is
            // Postgres-only and is not pulled (Puller::TABLES), so a device can
            // never turn a branch id into a name on its own — and Stock History
            // is a Cashier-level screen that has to work with no connection. A
            // code is short, stable and already used this way by the receipt
            // serial (MNL-0000000001). Null for sales and same-branch work.
            $table->string('counterparty_branch_code')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropColumn('counterparty_branch_code');
        });

        Schema::table('inventory_update_queue', function (Blueprint $table) {
            $table->renameColumn('resolution_reason', 'rejection_reason');

            $table->dropConstrainedForeignId('resolved_by');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('counterparty_branch_id');
            $table->dropColumn(['reference_type', 'reference_id', 'approved_quantity']);
        });
    }
};
