<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Provisioning\Support\SchemaVersion;

/**
 * The version gate's storage (MULTIBRANCH-BRAINSTORM.md §13).
 *
 * Two things version independently: the app build on each till, and this
 * schema. Every sync carries the app's version; the schema declares the
 * minimum app version it can safely talk to. Below that, sync is refused in
 * BOTH directions — a stale push would corrupt Postgres, a new-format pull
 * would corrupt the till's SQLite — while the till keeps selling offline.
 *
 * Single row by construction: a second row would make "the" schema version
 * ambiguous exactly when a branch is deciding whether it may sync.
 *
 * The seeded pair comes from SchemaVersion, not from constants here. This
 * migration runs once per schema and never again, so numbers written here could
 * be set but never raised — see that class for why that was a silent trap and
 * for the additive-vs-breaking discipline. `schema:migrate` re-stamps the row
 * from the same constants after every successful run (M9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schema_meta', function ($table) {
            $table->smallInteger('id')->primary();
            $table->integer('schema_version');
            $table->string('min_app_version', 20);
            $table->timestampTz('applied_at')->useCurrent();
        });

        DB::statement('alter table schema_meta add constraint schema_meta_singleton check (id = 1)');

        DB::table('schema_meta')->insert([
            'id' => 1,
            'schema_version' => SchemaVersion::SCHEMA_VERSION,
            'min_app_version' => SchemaVersion::MIN_APP_VERSION,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_meta');
    }
};
