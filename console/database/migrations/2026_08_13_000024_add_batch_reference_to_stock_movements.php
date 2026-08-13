<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name of a stock batch that a person can say out loud (§4).
 *
 * Schema 23 gave a batch an identity — `batch_id`, a uuid — and it does
 * everything an identity has to: it groups a Pending Stocks card and it pairs a
 * transfer's two legs. What it cannot do is appear on a screen. Two people at two
 * branches looking at the same delivery had no shared word for it, because
 * `3f9c2a17-8b4e-…` is not a word anybody reads off a card or repeats down a
 * phone.
 *
 * `batch_reference` is that word: the issuing branch's code, then a running
 * serial — `MNL-B-000001`. The uuid stays the identity and nothing keys off this;
 * it is what a person reads, and what Stock History filters by so "what else moved
 * with this?" is one click.
 *
 * ## Why the shape, and why it is stored
 *
 * The same shape and the same reason as a receipt number
 * (`App\Support\Sales\TransactionNumberGenerator`): branches mint independently,
 * so the branch prefix is the only thing making two serials unique business-wide.
 * `-B-` keeps it from being misread as a receipt number, since both appear in
 * this app. And it is STORED rather than composed at print time, so the value on
 * the card, in the ledger and in a URL is one string with one spelling.
 *
 * The prefix names the branch that ASKED, not the branch that must answer: an
 * Owner at head office can arrange a transfer between two branches that are
 * neither of them theirs (§4, schema 22).
 *
 * ## Why it is on the ledger too
 *
 * `batch_id`'s reason exactly. The queue row is answered and stops being shown;
 * the ledger is where a movement lives afterwards, and Stock History has to
 * render the batch with NO CONNECTION — the same argument that put
 * `counterparty_branch_code` on the ledger as a code rather than an id. Indexed
 * on both, because both are read by it.
 *
 * ## Not unique, and no foreign key
 *
 * Every row of one batch carries the same value, so uniqueness could not be
 * expressed here even though the value is unique per batch. What keeps it so is
 * the mint: `App\Support\Inventory\StockBatch` takes a transaction-level advisory
 * lock on the prefix before reading the max, so two tills at one branch issuing
 * at the same instant cannot be handed one serial. And there is no batch table to
 * point a key at — a batch is an identity, not a table.
 *
 * ## Additive, so the floor does not move
 *
 * Nullable on both tables and read by nothing that decides anything. A 1.1.0
 * build against a 24 schema simply writes no reference: its cards and its ledger
 * rows print what they printed before, and the filter finds nothing rather than
 * the wrong thing. Degraded, not wrong — the line between a raise and a column
 * older builds may ignore. MIN_APP_VERSION stays at 1.1.0.
 *
 * Grants: none, for the third time in a row and for the same reason. INSERT and
 * SELECT on both tables are table-level; only `BranchGrants::UPDATE_COLUMNS` is
 * enumerated per column, and this one is written once, at insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_update_queue', function (Blueprint $table) {
            // Beside the uuid it is the readable half of.
            $table->string('batch_reference', 32)->nullable()->after('batch_id');
            $table->index('batch_reference');
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->string('batch_reference', 32)->nullable()->after('batch_id');
            // Read by the Stock History batch filter, at every branch scope.
            $table->index('batch_reference');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_update_queue', function (Blueprint $table) {
            $table->dropIndex(['batch_reference']);
            $table->dropColumn('batch_reference');
        });

        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropIndex(['batch_reference']);
            $table->dropColumn('batch_reference');
        });
    }
};
