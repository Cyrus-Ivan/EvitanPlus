<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog, business-wide and mirrored to every till (§5.1).
 *
 * Archived, never deleted: an archived category is hidden from default lists
 * but must still resolve on the items and historical records that reference
 * it (CLAUDE.md). Category names are deliberately NOT unique — matching the
 * app, where only Item names are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestampsTz();
            $table->timestampTz('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
