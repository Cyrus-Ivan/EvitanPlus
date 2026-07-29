<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch identity and the sealed branch credential — the two local-only
     * tables M5 needs (MULTIBRANCH-BRAINSTORM.md §11.6).
     *
     * ## Why identity is not a setting
     *
     * The obvious home for "which branch am I" is the settings table, and it is
     * the wrong one: settings is MIRRORED and pulled from Postgres, so a local
     * write there is either overwritten by the next pull or — worse — pushed and
     * becomes another branch's setting. Branch identity arrives from the
     * credentials JSON, is true of this device only, and belongs in its own
     * table. `check (id = 1)` makes "two identities on one device"
     * unrepresentable rather than merely unlikely.
     *
     * ## Why the secret is a separate table
     *
     * Exposure, not normalization. `branch_code` is read on every checkout from
     * M6 on (it prefixes the receipt serial, §12); the sealed credential is read
     * only when a sync cycle opens a Postgres connection. Splitting them keeps
     * the hot path from loading ciphertext into memory, and makes rotation
     * replace exactly one row.
     *
     * Both tables are LOCAL-ONLY: they have no Postgres counterpart and are
     * allowlisted in SchemaParityTest::SQLITE_LOCAL_ONLY. Neither is business
     * history — a re-install rebuilds them from the JSON.
     */
    public function up(): void
    {
        Schema::create('instance', function (Blueprint $table) {
            // Not a UUID: this is a singleton row, not a business record that
            // has to be unique across branches.
            $table->integer('id')->primary();

            // Identity, straight from the credentials document.
            $table->uuid('business_id');
            $table->string('schema_name');
            $table->uuid('branch_id');
            $table->string('branch_code');
            $table->string('branch_name');

            // The version pair, read back from schema_meta at hydration — never
            // taken on trust from the JSON (§9). schema_version is recorded;
            // min_app_version is what the gate compares against (§13).
            $table->integer('schema_version');
            $table->string('min_app_version', 20);

            // Null until hydration completes. This is the fresh-install signal
            // RequireInitialSetup reads, and it can never be half-true: the
            // hydrate and this stamp are one SQLite transaction.
            $table->timestamp('hydrated_at')->nullable();

            $table->timestamps();
        });

        DB::statement('create trigger instance_singleton before insert on instance
            when (new.id <> 1) begin select raise(abort, \'instance holds exactly one row\'); end');

        Schema::create('instance_secret', function (Blueprint $table) {
            $table->integer('id')->primary();

            // The pgsql connection block, sealed with the per-install APP_KEY
            // (EncryptionKeyBootstrapper). Never the plaintext password.
            $table->text('dsn_ciphertext');

            // Hash of the credential, computed the same way the console does, so
            // "is this device still holding the credential I last issued?" can
            // be answered without either side transmitting the password.
            $table->string('fingerprint');

            $table->timestamp('imported_at');
            $table->timestamp('rotated_at')->nullable();
        });

        DB::statement('create trigger instance_secret_singleton before insert on instance_secret
            when (new.id <> 1) begin select raise(abort, \'instance_secret holds exactly one row\'); end');
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_secret');
        Schema::dropIfExists('instance');
    }
};
