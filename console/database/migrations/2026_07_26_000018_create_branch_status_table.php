<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch heartbeat (§14). Postgres-only; excluded from the parity guard.
 *
 * Each instance upserts its own row every sync cycle, inside its own business
 * schema — which is what makes the monitor compatible with least privilege:
 * the branch role writes only here and never reaches another schema. The
 * console's monitor view (M10) aggregates these rows across all schemas.
 *
 * One row per branch, enforced by making branch_id the primary key: a
 * heartbeat is a current-state upsert, not a history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_status', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->primary()->constrained()->restrictOnDelete();
            $table->string('app_version', 20)->nullable();
            $table->integer('schema_version')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            // Outbox depth: how far behind this branch currently is.
            $table->integer('unsynced_count')->default(0);
            $table->timestampTz('last_seen_at')->nullable();
            // Allowed: online | offline | unauthorized | version_blocked.
            // Same four values as the till's local sync_status, so the
            // heartbeat is a straight copy: "disabled credential" and "no
            // internet" must not collapse to one row in the console monitor,
            // since they need opposite responses. Not yet a check constraint —
            // M10 writes this column first and should add one then.
            $table->string('connection_state')->nullable();
            // Last failure surfaced to both the till and the monitor
            // (auth / version / network).
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            'alter table branch_status add constraint branch_status_unsynced_non_negative check (unsynced_count >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_status');
    }
};
