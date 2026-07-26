<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-write, pushed up insert-only (§5.2). Immutable business history.
 *
 * selling_price and cost are SNAPSHOTS taken at the moment of sale, not
 * lookups into variants. This is the table the immutable-history invariant
 * actually rests on: editing a variant's price later cannot reach back and
 * change what a past sale earned or cost (CLAUDE.md).
 *
 * No branch_id: it inherits branch through transaction_id, matching the app's
 * SQLite shape. Denormalising it here would create a second, divergable copy
 * of the same fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('selling_price', 12, 2);
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('transaction_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
