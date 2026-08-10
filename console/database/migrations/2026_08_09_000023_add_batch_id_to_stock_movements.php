<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stock batch: many movements asked for as one act (§4, M13).
 *
 * Until now every pending stock adjustment was its own event. A person moving
 * twelve products between two branches issued twelve unrelated rows, and the
 * branch answering them got twelve near-identical cards carrying twelve copies
 * of the same note. That is not what happened: one delivery left one shop.
 *
 * `batch_id` is the name of that one act. It is written once, at insert, by
 * whatever asked for the movements, and it is what Pending Stocks groups a card
 * by — the key widens from `parent_id ?? id` to `batch_id ?? parent_id ?? id`,
 * so a batch collapses into one card and everything issued singly is unaffected.
 *
 * ## Why it is on the ledger too, and not only on the queue
 *
 * The queue row is deleted by nothing but it is *answered*, and once answered the
 * page stops showing it. The ledger is where a movement lives afterwards, and
 * "these three came off the shelf together" is a fact about the movement rather
 * than about the request for it. Putting the same id on both means Stock History
 * can say so on a till with no connection, which is the same reason
 * `counterparty_branch_code` is on the ledger as a code.
 *
 * ## No foreign key
 *
 * A batch is an identity, not a table. There is no row to point at and nothing
 * to cascade: the id is minted by the app when the batch is issued, and its only
 * job is to make two rows equal to each other. Indexed on both tables because
 * both are read by it.
 *
 * ## Additive, so the floor does not move
 *
 * Nullable on both tables, and no existing behaviour reads it. A 1.1.0 build let
 * loose against a 23 schema inserts rows without it: its cards do not group, and
 * a transfer batch it approves spawns receiving legs that do not carry the batch
 * forward. Degraded, and not wrong — nothing it writes is a lie, and nothing it
 * reads is misread. MIN_APP_VERSION stays at 1.1.0; see
 * `Provisioning\Support\SchemaVersion` for why raising it needlessly freezes
 * every branch of every business.
 *
 * Grants: none. INSERT and SELECT on both tables are table-level, so they
 * already cover a column that did not exist when they were granted. Only
 * `BranchGrants::UPDATE_COLUMNS` is enumerated per column, and nothing updates
 * this one — like `issued_by_branch_id` before it, it is written once, at insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_update_queue', function (Blueprint $table) {
            // Beside `parent_id`, because the two are the same kind of fact: one
            // says which row this is the other half of, the other says which set
            // it was issued with.
            $table->uuid('batch_id')->nullable()->after('parent_id');
            $table->index('batch_id');
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            // Beside `reference_id`, which is the other "what asked for this"
            // column. Null on every row written before schema 23, and on every
            // movement issued on its own.
            $table->uuid('batch_id')->nullable()->after('reference_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_update_queue', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
