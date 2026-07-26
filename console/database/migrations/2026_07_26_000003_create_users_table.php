<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users are business-wide, not branch-scoped (MULTIBRANCH-BRAINSTORM.md §6).
 *
 * Every till mirrors EVERY user of the business, password hash included, so
 * any user can log in at any branch with no network. That is the one table
 * whose mirror is business-wide rather than branch-scoped (§5).
 *
 * Role permissions (Owner > Admin > Cashier, Item Cost Owner-only) are an app
 * layer concern and stay there: the per-branch Postgres role is shared by
 * whoever is signed in, so it can never be user-aware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('username');
            $table->string('password');
            // Allowed: owner | admin | cashier
            $table->string('role');
            $table->boolean('must_change_password')->default(false);
            $table->timestampsTz();
            // Soft delete, never hard: archived users must still resolve in
            // sales, login history and audit logs (CLAUDE.md).
            $table->timestampTz('deleted_at')->nullable();
        });

        // Usernames are unique across the business including archived users, so
        // a departed cashier's name is not silently reissued to someone else and
        // then confused with them in the audit trail.
        DB::statement('create unique index users_username_unique on users (username)');

        // Exactly one Owner (CLAUDE.md). Partial, so archiving is not blocked by
        // it — though the Owner itself can never be archived, which the app
        // enforces; this index only stops a SECOND live owner existing.
        DB::statement(
            "create unique index users_single_owner on users ((true)) where role = 'owner' and deleted_at is null"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
