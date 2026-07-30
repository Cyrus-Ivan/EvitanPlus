<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the pull got to, per table — the last of §11.6's proposed local sync
     * tables to be built (M7). LOCAL-ONLY (docs/glossary.md §3): no Postgres
     * counterpart, and allowlisted in SchemaParityTest::SQLITE_LOCAL_ONLY, which
     * has named it by name since M4.
     *
     * ## One row per table, not one global watermark
     *
     * A failure pulling `variants` must not discard the progress just made on
     * `users`, and a pulled table added later has to start from zero without
     * dragging every other table back through a full re-read. Both fall out of
     * keying by table name; neither is expressible with a single timestamp.
     */
    public function up(): void
    {
        Schema::create('sync_cursor', function (Blueprint $table) {
            $table->string('table_name')->primary();

            // A STRING, not a datetime, and the type is the whole point.
            //
            // This column holds Postgres's own rendering of the watermark
            // ('2026-07-31 04:00:00.123456+00') and hands it straight back as a
            // bind parameter on the next cycle. A datetime column would store it
            // through this app's naive 'Y-m-d H:i:s' convention, dropping the
            // microseconds and the offset — so the cursor would land up to a
            // second away from the row it was read off, and every cycle would
            // either re-read a second of rows or step over one. Never parsed
            // locally, never compared locally; only Postgres ever interprets it,
            // which is what makes the round trip exact.
            $table->string('pulled_through')->nullable();

            // When the pull last RAN, as opposed to how far it got. Two
            // different questions: a table with nothing new advances this and
            // not pulled_through.
            $table->timestamp('last_pulled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursor');
    }
};
