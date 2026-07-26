<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch identity (MULTIBRANCH-BRAINSTORM.md §5.1, §7, §12).
 *
 * Postgres-only: the till never mirrors the branch list, it only knows which
 * branch it IS (from its credentials JSON). One Postgres login role is created
 * per branch at provisioning (§7); this table is that role's business-side
 * identity.
 *
 * `code` is load-bearing, not cosmetic: it prefixes the branch's receipt
 * serial (MNL-0000000001), and that prefix is the ONLY thing making receipt
 * numbers unique business-wide, since each branch generates its own gapless
 * serial offline (§12). Uppercase-canonical for the same reason SKUs are —
 * a printed receipt number must have exactly one spelling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 16);
            $table->string('name');
            $table->text('address')->nullable();
            // Disabled, never deleted: a branch's transactions are immutable
            // business history and must keep resolving their branch forever.
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('alter table branches add constraint branches_code_uppercase check (code = upper(code))');
        DB::statement('create unique index branches_code_unique on branches (code)');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
