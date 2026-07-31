<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mediated online stock adjustments (§10). Postgres-only — the till mirrors
 * the pending rows it must act on, but this table is not part of the app's
 * SQLite schema, so the parity guard excludes it by name.
 *
 * Why it exists: subtracting stock from a branch while online cannot be applied
 * as an authoritative ledger entry immediately, because the branch may be
 * holding unsynced offline sales that have already consumed that stock.
 * Applying both blindly would drive reconciled stock negative.
 *
 * So the adjustment lands here as `pending`. On the branch's next sync the
 * till pushes its real movements first (they already happened and win), then
 * walks this queue IN ORDER and validates each row against true reconciled
 * stock: accept and write a ledger entry if stock stays >= 0, otherwise reject
 * and flag it for the Owner to re-issue. The row only becomes authoritative on
 * acceptance, so neither side can ever go negative.
 *
 * A branch role may UPDATE FOUR columns here, not `status` alone as §7's prose
 * says: `inventory_update_queue_resolved_at` below ties `status` to
 * `resolved_at`, so a grant on `status` by itself permits nothing. See
 * BranchGrants::UPDATE_COLUMNS, which is the operative matrix.
 *
 * WHO inserts these rows: an Owner or Admin, on any branch device, while
 * online, targeting any branch of their business — hence `branch_id` (target)
 * and `issued_by` (user). The words "online admin" and "HQ" used elsewhere both
 * mean this actor, and both are retired (docs/glossary.md §8).
 *
 * As built (M8): the branch role holds INSERT here — see BranchGrants::INSERT
 * for what that costs and how it is bounded. Until M8 only the console's admin
 * connection could create a row, which left the feature unreachable from the
 * screens meant to offer it.
 *
 * WHO writes the ledger entry on acceptance: the TARGET branch's own device,
 * during step 2 of its sync cycle (App\Support\Sync\QueueProcessor), after its
 * real movements have been pushed. It writes the entry upstream only and lets
 * the ordinary pull bring it back down, so `type = 'stock_adjustment'` keeps
 * exactly one writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_update_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            // Signed, but the mediation only matters for negatives.
            $table->integer('quantity');
            $table->text('notes')->nullable();
            // Allowed: pending | accepted | rejected
            $table->string('status')->default('pending');
            // Set on rejection so the in-app admin can explain it to the Owner.
            $table->text('rejection_reason')->nullable();
            // The accepted row's ledger entry, so the two are traceable to each
            // other and acceptance is provably idempotent.
            $table->uuid('ledger_entry_id')->nullable();
            $table->foreignUuid('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            // The branch walks its own pending rows oldest-first.
            $table->index(['branch_id', 'status', 'created_at']);
        });

        DB::statement(
            "alter table inventory_update_queue add constraint inventory_update_queue_status
             check (status in ('pending', 'accepted', 'rejected'))"
        );

        // A resolved row must say when, and a pending one must not pretend to
        // have been resolved — the branch replays on `pending` alone.
        DB::statement(
            "alter table inventory_update_queue add constraint inventory_update_queue_resolved_at
             check ((status = 'pending') = (resolved_at is null))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_update_queue');
    }
};
