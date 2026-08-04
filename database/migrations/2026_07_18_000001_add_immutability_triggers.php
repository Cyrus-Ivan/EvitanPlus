<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Append-only tables (CLAUDE.md — immutable business history). The
     * Immutable model trait guards the Eloquent paths, but model events do not
     * fire for bulk builder writes or raw SQL; these BEFORE UPDATE / DELETE
     * triggers are the database-level backstop nothing can bypass.
     *
     * DROP TABLE (migrate:fresh) drops them alongside the table.
     *
     * Binding on anything that writes these tables in bulk: hydration copies a
     * branch's history into every one of them (M11.6) and must therefore INSERT,
     * never upsert — an upsert's ON CONFLICT DO UPDATE arm fires the update
     * trigger and aborts. See Hydrator::INSERT_ONLY.
     *
     * @var list<string>
     */
    private const TABLES = [
        'transactions',
        'transaction_items',
        'inventory_ledger',
        'sale_returns',
        'sale_return_items',
        'login_history',
        'audit_logs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_immutable_update
                BEFORE UPDATE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} rows are immutable');
                END
                SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_immutable_delete
                BEFORE DELETE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} rows are immutable');
                END
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_delete");
        }
    }
};
