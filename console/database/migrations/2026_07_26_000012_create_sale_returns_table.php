<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * After-sales events: returns and defects (§5.2). Local-write, insert-only.
 *
 * The roadmap lists "returns, defects" as two tables; the app models them as
 * one table with a `type`, because they share every column and differ only in
 * what they do to stock — a return restores it, a defect does not
 * (CLAUDE.md). Mirroring the app keeps the parity guard meaningful. Void was
 * dropped from the product entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('return_number');
            $table->foreignUuid('transaction_id')->constrained()->restrictOnDelete();
            // Allowed: return | defect
            $table->string('type');
            $table->text('reason')->nullable();
            // Null for defects: no money goes back to the customer.
            $table->string('refund_payment_type')->nullable();
            $table->decimal('total_refund', 12, 2);
            $table->foreignUuid('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index(['branch_id', 'created_at']);
        });

        // Per-branch for the same reason transaction_number is: the serial is
        // generated locally and offline.
        DB::statement(
            'create unique index sale_returns_branch_number_unique on sale_returns (branch_id, return_number)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
