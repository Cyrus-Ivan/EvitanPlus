<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-write, pushed up insert-only (§5.2). Immutable and append-only.
 *
 * Sessions happen at a specific till, so this is branch-scoped even though
 * users are business-wide — which is exactly what lets the Owner see, online,
 * who signed in where (§6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            // Allowed: login | logout | failed
            $table->string('event_type');
            $table->timestampTz('occurred_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('user_id');
            $table->index(['branch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_history');
    }
};
