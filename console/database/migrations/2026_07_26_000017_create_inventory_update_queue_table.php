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
 * Why it exists: an online admin subtracting stock from a branch cannot be
 * applied as an authoritative ledger entry immediately, because the branch may
 * be holding unsynced offline sales that have already consumed that stock.
 * Applying both blindly would drive reconciled stock negative.
 *
 * So the adjustment lands here as `pending`. On the branch's next sync the
 * till pushes its real movements first (they already happened and win), then
 * walks this queue IN ORDER and validates each row against true reconciled
 * stock: accept and write a ledger entry if stock stays >= 0, otherwise reject
 * and flag it for the Owner to re-issue. The row only becomes authoritative on
 * acceptance, so neither side can ever go negative.
 *
 * `status` is the one column a branch role may UPDATE (§7).
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
