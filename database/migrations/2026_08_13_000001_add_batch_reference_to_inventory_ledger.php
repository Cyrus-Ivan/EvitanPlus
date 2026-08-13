<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a stock batch is CALLED, on the device's own ledger.
 *
 * The SQLite half of the business schema's 000024, and the same pairing as
 * `batch_id` before it: `inventory_ledger` is MIRRORED, so the parity guard
 * requires both sides to agree in structure, and `inventory_update_queue` gets no
 * counterpart here because a device holds no copy of it.
 *
 * `MNL-B-000001` — the issuing branch's code and a running serial, minted once
 * when the batch is asked for and carried onto every ledger row the batch
 * eventually writes. It is on the device precisely so the Stock History dialog
 * can name the batch, and the history can be filtered to it, with no connection.
 *
 * Null for anything issued on its own, and for everything written before schema
 * 24. RowMapper reads the local column list from `pragma table_info`, so the
 * column travels up and down as soon as both sides have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->string('batch_reference', 32)->nullable()->after('batch_id');
            $table->index('batch_reference');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropIndex(['batch_reference']);
            $table->dropColumn('batch_reference');
        });
    }
};
