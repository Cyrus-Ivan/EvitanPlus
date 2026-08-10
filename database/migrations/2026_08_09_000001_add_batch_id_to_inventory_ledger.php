<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which stock batch a movement belonged to, on the device's own ledger.
 *
 * The SQLite half of the business schema's 000023. `inventory_ledger` is
 * MIRRORED, so the parity guard requires the two sides to agree in structure;
 * this file is the reason that guard still passes. The queue's half of that
 * migration gets no counterpart here — `inventory_update_queue` is Postgres-only
 * by decision, and a device holds no copy of it.
 *
 * Null for every movement issued on its own, and for everything written before
 * schema 23. It is set when a recount, an adjustment or a transfer was one line
 * of a batch somebody submitted as a single act, so Stock History can say "these
 * came off the shelf together" with no connection — the same reason
 * `counterparty_branch_code` is stored here as a code rather than an id.
 *
 * Nothing else has to change for this to flow: RowMapper reads the local column
 * list from `pragma table_info`, so a mirrored column travels up and down as
 * soon as both sides have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->after('reference_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
