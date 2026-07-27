<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What credential is each branch supposed to be running right now (M3).
 *
 * `provisioning_audit` already records that a rotation HAPPENED — who, when,
 * which branch. What it cannot answer is whether the credential a given branch
 * is actually using is the current one, because an audit trail describes events
 * and this question is about state. That gap is the whole reason this table
 * exists, and it has to be filled at issuance time or not at all: the password
 * is generated, written to one 0600 file and forgotten within a single command,
 * so a credential issued without a fingerprint can never be fingerprinted
 * afterwards.
 *
 * ## The fingerprint is not a password hash
 *
 * It is a deterministic sha256 of the secret (BranchSecret::fingerprint), NOT
 * bcrypt, and that is deliberate: nothing here ever verifies a login. The one
 * operation is comparison — M5 stores the same fingerprint on the device and
 * M10's heartbeat reports it, so the console can say "this till is two
 * generations behind" without either side ever transmitting the secret. A
 * per-row salt would make that comparison impossible.
 *
 * Disclosure risk is nil in the only direction that matters: the input is 32
 * characters from BranchSecret's [A-Za-z0-9] alphabet — ~190 bits — so the
 * digest is not a shortcut to the password by any margin that will ever exist.
 *
 * ## Why it is not simply append-only
 *
 * `superseded_at` must stay writable, so the blanket evitan_reject_mutation()
 * used by provisioning_audit would be wrong here. The trigger below is narrower
 * but the same in spirit: DELETE is never allowed, no issued fact may be
 * rewritten, and superseding is one-way. A row can therefore move from live to
 * superseded exactly once and never back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_issuance', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Target. Denormalised on purpose — this schema holds no registry of
            // businesses (the console enumerates biz_* schemas instead), and a
            // row here must stay readable after its business schema is gone.
            $table->uuid('business_id');
            $table->string('schema_name');
            $table->uuid('branch_id');
            $table->string('role_name');

            // NEVER the password. See the class docblock.
            $table->string('fingerprint');

            // 1, 2, 3… per branch. Cheap to read, and the number the operator
            // actually says out loud ("that laptop is on generation 2").
            $table->unsignedInteger('generation');

            // Allowed: provision | rotate | recreate
            $table->string('reason');

            $table->string('actor');

            $table->timestampTz('issued_at')->useCurrent();

            // Null means live. Set when a later generation is issued, or when
            // the role is revoked outright — which is what makes an old JSON
            // found on a desk identifiable as stale.
            $table->timestampTz('superseded_at')->nullable();
        });

        DB::statement(
            "alter table credential_issuance add constraint credential_issuance_reason
             check (reason in ('provision', 'rotate', 'recreate'))"
        );

        DB::statement(
            'alter table credential_issuance add constraint credential_issuance_generation_positive
             check (generation >= 1)'
        );

        // At most ONE live credential per branch, enforced by the database
        // rather than by the order in which a command happens to run its steps.
        // Two live rows would make "is this till current?" unanswerable, which
        // is the single question this table exists to answer.
        DB::statement(
            'create unique index credential_issuance_live_unique
             on credential_issuance (branch_id) where superseded_at is null'
        );

        DB::statement(
            'create unique index credential_issuance_generation_unique
             on credential_issuance (branch_id, generation)'
        );

        DB::statement(
            'create index credential_issuance_branch_idx
             on credential_issuance (branch_id, generation desc)'
        );

        // Append-only in every column but superseded_at, and one-way even
        // there. evitan_reject_mutation() already exists in this schema (the
        // provisioning_audit migration creates it) but rejects UPDATE wholesale,
        // which would freeze the one field that must move.
        DB::unprepared(<<<'SQL'
            create or replace function evitan_reject_issuance_rewrite() returns trigger as $$
            begin
                if TG_OP = 'DELETE' then
                    raise exception 'credential_issuance rows are immutable'
                        using errcode = 'restrict_violation';
                end if;

                if new.id is distinct from old.id
                   or new.business_id is distinct from old.business_id
                   or new.schema_name is distinct from old.schema_name
                   or new.branch_id is distinct from old.branch_id
                   or new.role_name is distinct from old.role_name
                   or new.fingerprint is distinct from old.fingerprint
                   or new.generation is distinct from old.generation
                   or new.reason is distinct from old.reason
                   or new.actor is distinct from old.actor
                   or new.issued_at is distinct from old.issued_at then
                    raise exception 'credential_issuance rows are immutable except for superseded_at'
                        using errcode = 'restrict_violation';
                end if;

                if old.superseded_at is not null then
                    raise exception 'credential_issuance generation % is already superseded', old.generation
                        using errcode = 'restrict_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            create trigger credential_issuance_append_only
            before update or delete on credential_issuance
            for each row execute function evitan_reject_issuance_rewrite();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists credential_issuance_append_only on credential_issuance');
        DB::unprepared('drop function if exists evitan_reject_issuance_rewrite()');

        Schema::dropIfExists('credential_issuance');
    }
};
