<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Append-only tables — the Postgres half of the immutable-history invariant
 * (CLAUDE.md), mirroring
 * database/migrations/2026_07_18_000001_add_immutability_triggers.php.
 *
 * The app's model guards protect Eloquent paths, and the branch Postgres role
 * is granted no DELETE at all (§7). Neither is sufficient here: grants can be
 * re-issued by a future migration, and the CONSOLE connects as an admin role
 * that bypasses grants entirely. These triggers are the one guard that also
 * binds the console itself, which is exactly the point — a provisioning bug
 * must not be able to rewrite a business's sales history.
 *
 * Deliberately NOT covered: import_history (its status and counts legitimately
 * progress) and stock_snapshots (a reconciled current-state row, by definition
 * mutable).
 *
 * This list must stay identical to the app's. The parity guard compares
 * columns, not triggers, so divergence here is caught by SchemaInvariantsTest
 * asserting every one of these tables rejects UPDATE and DELETE.
 */
return new class extends Migration
{
    /** @var list<string> */
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
        // One shared trigger function: TG_TABLE_NAME makes the message specific
        // without needing seven near-identical functions. Created inside the
        // business schema (search_path is pinned), so dropping the schema takes
        // the function with it.
        DB::unprepared(<<<'SQL'
            create or replace function evitan_reject_mutation() returns trigger as $$
            begin
                raise exception '% rows are immutable', TG_TABLE_NAME
                    using errcode = 'restrict_violation';
            end;
            $$ language plpgsql;
        SQL);

        foreach (self::TABLES as $table) {
            DB::unprepared(<<<SQL
                create trigger {$table}_immutable
                before update or delete on {$table}
                for each row execute function evitan_reject_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("drop trigger if exists {$table}_immutable on {$table}");
        }

        DB::unprepared('drop function if exists evitan_reject_mutation()');
    }
};
