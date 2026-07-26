<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The only record of stock movement. There is no stock-adjustment table:
 * sales, returns, defects, manual adjustments and imports are all ledger rows,
 * and stock is their sum (CLAUDE.md).
 *
 * Uniquely among the tables here, BOTH sides write it (§5.1/§5.2):
 *   - online-authoritative rows: restock / manual adjustment / import
 *   - local-write rows: sale / return / defect
 * They never conflict because both are additive — an insert, never an edit.
 * Subtractive online adjustments do not land here directly at all; they go
 * through inventory_update_queue first so reconciled stock cannot go negative
 * behind an offline branch's back (§10).
 *
 * Append-only: created_at only, no updated_at, no soft delete. Corrections are
 * new compensating entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            // Signed movement (+/-).
            $table->integer('quantity');
            // Running on-hand after this entry; never negative.
            $table->integer('resulting_snapshot');
            // Allowed: sale | stock_adjustment
            $table->string('type');
            // Allowed: sale | return | defect (null for stock adjustments)
            $table->string('sale_type')->nullable();
            $table->uuid('reference_id')->nullable();
            // Allowed: transaction | manual_adjustment | csv_import | return
            $table->string('reference_type');
            $table->text('notes')->nullable();
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('variant_id');
            $table->index(['variant_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['branch_id', 'variant_id', 'created_at']);
        });

        // Negative inventory is never allowed (CLAUDE.md). The app enforces it
        // at write time; this is the backstop no code path can bypass.
        DB::statement(
            'alter table inventory_ledger add constraint inventory_ledger_non_negative check (resulting_snapshot >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger');
    }
};
