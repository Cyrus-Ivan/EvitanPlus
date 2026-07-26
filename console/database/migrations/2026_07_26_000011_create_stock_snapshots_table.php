<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Derived: per-branch current stock, reconciled from the ledger (§5.3, §10).
 *
 * Stock is per BRANCH, not per business — the same variant has different
 * on-hand at each till — so the app's one-row-per-variant uniqueness becomes
 * one row per (branch, variant) here. This is the only mirrored table whose
 * unique key genuinely changes shape between the two schemas.
 *
 * The till never blind-overwrites it: effective local stock is the pulled
 * baseline minus its own unsynced out-movements, which self-heals once those
 * movements land (§10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestampsTz();
        });

        DB::statement(
            'create unique index stock_snapshots_branch_variant_unique on stock_snapshots (branch_id, variant_id)'
        );

        DB::statement(
            'alter table stock_snapshots add constraint stock_snapshots_non_negative check (quantity >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_snapshots');
    }
};
