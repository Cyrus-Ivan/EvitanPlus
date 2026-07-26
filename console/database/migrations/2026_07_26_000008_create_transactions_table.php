<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local-write, pushed up insert-only (§5.2). Immutable business history.
 *
 * branch_id is the first branch-scoped column in the set: a sale belongs to
 * the till that rang it, and consolidated reporting (§15) groups on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            // The customer-facing receipt number. Each branch generates its own
            // gapless, never-resetting serial locally so it works offline; the
            // branch code prefixes it on the printed receipt.
            $table->string('transaction_number');
            // Allowed: cash | cashless | others
            $table->string('payment_type');
            $table->decimal('discount', 12, 2)->default(0);
            // Allowed: amount | percentage (null when no discount)
            $table->string('discount_type')->nullable();
            $table->decimal('total', 12, 2);
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change', 12, 2)->nullable();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->text('comment')->nullable();
            // No updated_at: a completed sale is immutable.
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('user_id');
            $table->index(['branch_id', 'created_at']);
        });

        // Serials are unique PER BRANCH, not globally. Two branches both
        // counting from 0000000001 is expected and correct — business-wide
        // uniqueness comes from the branch-code prefix (§12). A global unique
        // index here would make the second branch's first sale fail.
        DB::statement(
            'create unique index transactions_branch_number_unique on transactions (branch_id, transaction_number)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
