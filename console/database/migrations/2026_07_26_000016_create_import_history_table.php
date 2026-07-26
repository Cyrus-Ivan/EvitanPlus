<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CSV import runs, branch-scoped and pushed up.
 *
 * Not in the roadmap's table list, but the app has it and it is business
 * history: an import is an auditable event that moved stock. Including it
 * keeps the mirror complete, so the parity guard does not need a special case
 * for a table that exists on only one side.
 *
 * Mutable by design (status and counts progress as the run proceeds), so
 * unlike its neighbours it keeps updated_at and takes no immutability trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('filename')->nullable();
            // Allowed: create | update | upsert
            $table->string('mode');
            // Allowed: pending | completed | failed
            $table->string('status');
            $table->integer('total_rows')->nullable();
            $table->integer('success_count')->nullable();
            $table->integer('error_count')->nullable();
            $table->text('errors')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_history');
    }
};
