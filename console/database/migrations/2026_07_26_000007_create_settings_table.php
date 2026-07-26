<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business identity and settings, business-wide and mirrored (§5.1).
 *
 * Key/value rather than a wide table, matching the app: business name,
 * address, TIN, receipt footer, timezone and currency all live here, which is
 * why the roadmap's "business identity" needs no table of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
