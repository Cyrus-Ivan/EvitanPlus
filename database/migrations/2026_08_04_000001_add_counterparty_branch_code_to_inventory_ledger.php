<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other branch a stock movement involved, on the device's own ledger.
 *
 * The SQLite half of the business schema's 000021. `inventory_ledger` is
 * MIRRORED, so the parity guard requires the two sides to agree in structure;
 * this file is the reason that guard still passes.
 *
 * ## Why a code and not a branch id
 *
 * This column exists to be READ on a till with no connection. `branches` lives
 * only in Postgres and is deliberately not pulled (App\Support\Sync\Puller), so
 * a device can never turn a branch id into a name by itself — and Stock History
 * is a Cashier-level screen whose whole point is that it works offline. Storing
 * the code makes the movement's detail readable with nothing but this table,
 * which is the same trade the receipt serial already makes (MNL-0000000001).
 *
 * Null for everything that involved only this branch: sales, returns, defects,
 * and a recount or import somebody did here. It is set when a stock adjustment
 * was asked for by another branch, and on both legs of a transfer.
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
            $table->string('counterparty_branch_code')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_ledger', function (Blueprint $table) {
            $table->dropColumn('counterparty_branch_code');
        });
    }
};
