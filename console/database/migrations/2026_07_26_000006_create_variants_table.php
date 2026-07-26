<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog, business-wide and mirrored to every till (§5.1).
 *
 * Price and cost live here, but a sale never reads them back: each sale
 * SNAPSHOTS price and cost onto its transaction_items, so editing a price
 * here can never retroactively rewrite history (CLAUDE.md). That is also why
 * a price change made online while a branch is offline is harmless — the
 * offline sale committed against the price it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained()->restrictOnDelete();
            // Null name = the "no variant" case: a single-product item keeps
            // exactly one unnamed variant and the UI falls back to the item
            // name. Variant names are NOT unique — "Small" recurs everywhere.
            $table->string('name')->nullable();
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->decimal('selling_price', 12, 2);
            // Owner-only to view or edit, at the app layer. Nullable: imported
            // stock may arrive with an unknown cost.
            $table->decimal('cost', 12, 2)->nullable();
            $table->integer('low_stock_threshold')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('item_id');
        });

        // SKU is required and canonically uppercase, so one physical product
        // has exactly one spelling across branches.
        DB::statement('alter table variants add constraint variants_sku_uppercase check (sku = upper(sku))');
        DB::statement('create unique index variants_sku_unique on variants (sku)');

        // Barcode is unique only WHEN PRESENT — a blank barcode must never
        // block selling (CLAUDE.md). SQLite gets this free because it treats
        // NULLs as distinct; Postgres needs the predicate spelled out.
        DB::statement('create unique index variants_barcode_unique on variants (barcode) where barcode is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
