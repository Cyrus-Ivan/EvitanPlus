<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-write, insert-only (§5.2). Immutable.
 *
 * Prices are snapshotted here too, and refund_amount is stored rather than
 * recomputed because a partial return's refund is apportioned from the
 * original transaction's discounted total — the arithmetic must stay frozen
 * exactly as it was at the counter.
 *
 * Branch is inherited through sale_return_id, matching transaction_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('transaction_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('selling_price', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('refund_amount', 12, 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('sale_return_id');
            $table->index('transaction_item_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
    }
};
