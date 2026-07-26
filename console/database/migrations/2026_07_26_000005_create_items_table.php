<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog, business-wide and mirrored to every till (§5.1).
 *
 * Every Item must have at least one Variant; stock, price, SKU and barcode
 * live on the Variant, never here (CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('unit', 50);
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('category_id');
        });

        // Item names are unique case-insensitively AND across archived rows: a
        // retired name stays reserved, so history can never be read as two
        // different products. SQLite expresses this with COLLATE NOCASE; the
        // Postgres equivalent is a functional index on lower(name).
        DB::statement('create unique index items_name_unique on items (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
