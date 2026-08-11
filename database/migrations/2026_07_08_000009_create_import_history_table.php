<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('filename')->nullable();
            // What kind of file this was. Item imports classify themselves as
            // create | update | upsert, or `unknown` when the file never parsed
            // far enough to tell; an opening-stock import is `opening_stock`.
            // Repurposed in M13 from the CSV's old stock-mode column, and
            // unconstrained on both sides — the Postgres twin is a bare string
            // too, so the values are the app's rule rather than the schema's.
            $table->string('mode');
            // Allowed: success | failed | partial
            $table->string('status');
            $table->integer('total_rows')->nullable();
            $table->integer('success_count')->nullable();
            $table->integer('error_count')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_history');
    }
};
