<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-write, pushed up insert-only (§5.2). Immutable and append-only.
 *
 * user_id is nullable: some auditable events (a failed login against an
 * unknown username) have no user to attribute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();
            $table->text('description')->nullable();
            // JSON documents, but text to mirror the app's SQLite columns
            // exactly — the parity guard compares these two shapes.
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('user_id');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
