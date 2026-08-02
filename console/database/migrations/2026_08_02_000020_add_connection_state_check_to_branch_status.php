<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The four words `branch_status.connection_state` may hold (§14, M10).
 *
 * The vocabulary is settled and shared with the till's own `sync_status`
 * (§11.6) — `online | offline | unauthorized | version_blocked` — so a value
 * here is the same fact the cashier is reading, not a translation of it. Until
 * now that agreement was a convention held in two PHP files; nothing in the
 * database would have refused a fifth word, and the monitor would have rendered
 * it without comment.
 *
 * Added in M10 rather than in 000018 because M10 is the first code to WRITE the
 * column: a constraint on a column nothing writes constrains nothing, and this
 * is the moment it starts to mean something.
 *
 * NULL stays legal. `branch_id` is the primary key and a branch that has never
 * synced simply has no row, but a partial write is not worth refusing for a
 * reason unrelated to the value set — a heartbeat may never be the thing that
 * makes a till's sync cycle fail. (Named in prose rather than with an @see: the
 * class is the POS app's, and console code never references it — §18.)
 *
 * ## Additive, so the floor does not move
 *
 * This raises SCHEMA_VERSION to 20 and leaves MIN_APP_VERSION at 1.0.0. No
 * shipped build writes this column at all, so no build is broken by the shape
 * changing; raising the floor would freeze every branch of every business for
 * nothing (see Provisioning\Support\SchemaVersion for that discipline in full).
 */
return new class extends Migration
{
    private const CONSTRAINT = 'branch_status_connection_state_allowed';

    public function up(): void
    {
        DB::statement(
            'alter table branch_status add constraint '.self::CONSTRAINT.'
             check (connection_state is null or connection_state in
                    (\'online\', \'offline\', \'unauthorized\', \'version_blocked\'))'
        );
    }

    public function down(): void
    {
        DB::statement('alter table branch_status drop constraint if exists '.self::CONSTRAINT);
    }
};
