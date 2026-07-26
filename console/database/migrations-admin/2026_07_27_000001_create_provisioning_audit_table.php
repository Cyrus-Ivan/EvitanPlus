<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The console's self-audit trail (MULTIBRANCH-BRAINSTORM.md §4, §8.6).
 *
 * Every provision, migrate, rotate, disable and delete the console performs
 * records who/what/when/target here. This is the developer-side counterpart to
 * the business `audit_logs` table and is deliberately a DIFFERENT thing:
 *
 *  - `audit_logs` is branch-scoped business history, written by tills and
 *    pushed up by sync. Every row needs a branch and a business schema, so a
 *    provisioning event — which happens BEFORE any branch exists, and sometimes
 *    fails before the schema does — has nowhere to go in it.
 *  - This table lives in the console's own schema (SchemaName::CONSOLE),
 *    outside every business schema. That also keeps it clear of the parity and
 *    invariants allowlists, which enumerate the tables a business schema may
 *    contain.
 *
 * This migration set is applied by `console:migrate`, never by
 * `schema:migrate` — the two sets have different targets and different owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_audit', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Dotted action name: business.provision, branch.provision,
            // branch.rotate, branch.disable (M3+).
            $table->string('action');

            // A first-class column, not just a detail key: "which provisions
            // failed and left something behind" is the question this trail
            // exists to answer, so it must be cheap to query.
            // Allowed: succeeded | rolled_back | cleanup_failed
            $table->string('outcome');

            // Target. All nullable because a failure can be recorded before the
            // target exists — a provision that dies during migration still owes
            // an entry naming what it was attempting.
            $table->uuid('business_id')->nullable();
            $table->string('schema_name')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('branch_code', 16)->nullable();
            $table->string('role_name')->nullable();

            // Who: the operator, not an app user. There are no accounts here.
            $table->string('actor');

            // Anything action-specific worth keeping (outcome, error message,
            // cleanup result). NEVER credentials.
            $table->jsonb('detail')->nullable();

            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(
            "alter table provisioning_audit add constraint provisioning_audit_outcome
             check (outcome in ('succeeded', 'rolled_back', 'cleanup_failed'))"
        );

        // Reading the trail is how M10 answers "what happened to this branch",
        // and the ordering is always newest-first per target.
        DB::statement('create index provisioning_audit_business_idx on provisioning_audit (business_id, created_at desc)');
        DB::statement('create index provisioning_audit_action_idx on provisioning_audit (action, created_at desc)');

        // Append-only, mirroring the business schema's immutability triggers.
        // An audit trail that the tool writing it can also rewrite is not an
        // audit trail — and this binds the admin connection, which is the only
        // thing that ever reaches this schema.
        DB::unprepared(<<<'SQL'
            create or replace function evitan_reject_mutation() returns trigger as $$
            begin
                raise exception '% rows are immutable', TG_TABLE_NAME
                    using errcode = 'restrict_violation';
            end;
            $$ language plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            create trigger provisioning_audit_immutable
            before update or delete on provisioning_audit
            for each row execute function evitan_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists provisioning_audit_immutable on provisioning_audit');
        DB::unprepared('drop function if exists evitan_reject_mutation()');

        Schema::dropIfExists('provisioning_audit');
    }
};
