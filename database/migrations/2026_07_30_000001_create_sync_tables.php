<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The outbox and the sync status singleton — M6's half of the local sync
     * tables (MULTIBRANCH-BRAINSTORM.md §11.6). `sync_cursor` is M7's, and is
     * deliberately not created here: a table nothing reads is indistinguishable
     * from one nobody remembered to fill.
     *
     * Both are LOCAL-ONLY (docs/glossary.md §3): no Postgres counterpart, and
     * allowlisted in SchemaParityTest::SQLITE_LOCAL_ONLY, whose docblock has
     * been waiting for them by name since M4.
     */
    public function up(): void
    {
        Schema::create('sync_outbox', function (Blueprint $table) {
            // Autoincrement, not a UUID: the outbox needs STRICT FIFO and a UUID
            // gives no order. Rows push in the order they were written, which is
            // also FK order — a transaction is created before its items.
            $table->bigIncrements('sequence');

            // A POINTER, not a payload. Every local-write table is immutable, so
            // the row can be re-read at push time and cannot have changed since;
            // a copied payload would be a second representation that can only
            // ever drift out of agreement with the first.
            $table->string('table_name');
            $table->uuid('row_id');

            // One sale = one atomic push batch (transaction + its items + its
            // ledger entries). A partially-pushed sale would show up in
            // consolidated reporting as a sale with missing lines.
            $table->uuid('group_id');

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();

            // NULL means unsynced, and `count(*) where synced_at is null` is the
            // one definition of unsynced_count (§11.6). There is no counter
            // column anywhere, on either side, so there is nothing to drift.
            $table->timestamp('synced_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The push reads pending rows in sequence order; this is that query.
            $table->index(['synced_at', 'sequence']);

            // Enqueueing the same row twice is a no-op rather than a second push
            // of the same insert. Cheap insurance against a write path that ever
            // ends up firing `created` more than once.
            $table->unique(['table_name', 'row_id']);
        });

        Schema::create('sync_status', function (Blueprint $table) {
            $table->integer('id')->primary();

            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_probe_at')->nullable();

            // online | offline | unauthorized | version_blocked.
            //
            // Four values rather than two, because at the till all three failure
            // kinds look identical and only ONE of them is fixed by waiting: a
            // revoked credential, a schema migrated ahead of this build, and a
            // dead internet connection need three different phone calls. M10
            // copies this straight into branch_status for the same reason.
            $table->string('connection_state')->default('offline');

            // auth | version | network — the kind, for the UI to speak plainly;
            // last_error is the raw message, for the developer.
            $table->string('last_error_kind')->nullable();
            $table->text('last_error')->nullable();

            // When the current blocked state began, so "since 9:14am" is
            // answerable without keeping history.
            $table->timestamp('blocked_since')->nullable();

            $table->timestamps();
        });

        // Singleton by trigger, not by check: SQLite cannot add a check
        // constraint after CREATE TABLE and Laravel's blueprint has no check().
        // Same guarantee at the moment that matters — a second row cannot be
        // inserted. Matches the instance/instance_secret pattern (M5).
        DB::statement('create trigger sync_status_singleton before insert on sync_status
            when (new.id <> 1) begin select raise(abort, \'sync_status holds exactly one row\'); end');

        // The highest serial POSTGRES already holds for this branch, read once
        // at hydration.
        //
        // A device replacing an EXISTING branch's hardware would generate a
        // serial restarting at 1 and collide with rows already in Postgres. The
        // push is insert-only, so that collision would be SILENT: the sale
        // simply never arrives. These two columns are what stop a swapped till
        // from quietly losing its first day's takings. (Written before M11.6,
        // when the hydrate skipped history entirely and these were the ONLY
        // answer; it now copies this branch's transactions and sale_returns too,
        // so the local max usually answers as well.)
        Schema::table('instance', function (Blueprint $table) {
            $table->string('transaction_number_floor')->nullable();
            $table->string('return_number_floor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance', function (Blueprint $table) {
            $table->dropColumn(['transaction_number_floor', 'return_number_floor']);
        });

        Schema::dropIfExists('sync_status');
        Schema::dropIfExists('sync_outbox');
    }
};
