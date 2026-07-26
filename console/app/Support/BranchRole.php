<?php

namespace Provisioning\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Creates and manages a branch's Postgres login role (§7).
 *
 * Everything here runs on the ADMIN connection: the console owns all role
 * management and DDL, and neither the shipped app nor a branch role can perform
 * any of it — by grant, and by the fact that this code never ships (§18).
 *
 * ## What this role can and cannot do
 *
 * The grants come from BranchGrants. Beyond them, the role is created with every
 * dangerous attribute explicitly off, and ownership of the schema stays with the
 * admin role — which is what makes "no DDL" structural rather than merely
 * ungranted.
 *
 * Some privileges are inherited from the implicit PUBLIC role and CANNOT be
 * removed per-role (you cannot revoke a PUBLIC grant from a single grantee).
 * They are accepted deliberately, and the negative tests assert the real
 * boundary rather than a flattering one:
 *
 *  - CONNECT on the database — required; the branch must be able to connect.
 *  - USAGE on schema `public` — unavoidable, and empty: no business data lives
 *    there, and PG 15+ already revoked CREATE on public from PUBLIC.
 *  - TEMPORARY on the database — session-local scratch space; cannot reach
 *    business data.
 *  - Reading pg_catalog / information_schema — by design. This leaks other
 *    schema NAMES via pg_namespace, never their rows; information_schema is
 *    privilege-filtered, so other businesses' columns stay invisible.
 *
 * What it genuinely cannot do: DELETE or TRUNCATE anything, UPDATE anything
 * outside BranchGrants, INSERT into online-authoritative tables, run DDL, read
 * another business's schema, or escalate (no membership in any role, and
 * NOINHERIT so a future accidental GRANT of membership would still not take
 * effect without an explicit SET ROLE).
 *
 * Row-level isolation is NOT attempted: within its own business schema a
 * compromised branch credential could insert rows tagged with another branch's
 * branch_id. RLS is the fix and is out of scope here; stating that plainly
 * beats overclaiming least privilege.
 */
final class BranchRole
{
    public static function exists(string $role): bool
    {
        return self::admin()->selectOne(
            'select 1 as found from pg_roles where rolname = ?',
            [RoleName::validate($role)],
        ) !== null;
    }

    /**
     * Create the role and apply its full privilege set.
     *
     * One transaction: Postgres makes CREATE ROLE, GRANT and ALTER ROLE all
     * transactional, so the role either exists fully granted or does not exist
     * at all. There is no state in which a branch role is connectable but
     * mis-granted.
     */
    public static function create(string $role, string $password, string $schema): void
    {
        $role = RoleName::validate($role);
        $schema = SchemaName::validate($schema);
        $secret = BranchSecret::quote($password);

        self::admin()->transaction(function (Connection $admin) use ($role, $schema, $secret): void {
            $admin->statement(
                "create role \"{$role}\" with login
                 nosuperuser nocreatedb nocreaterole noinherit nobypassrls noreplication
                 password {$secret}"
            );

            self::applyGrants($admin, $role, $schema);
        });
    }

    /**
     * Re-apply the privilege matrix to an existing role, idempotently.
     *
     * Exists because grants are enumerated per table (see BranchGrants): any
     * migration that ADDS a table leaves the branch role without privileges on
     * it, so M9's migrate-on-update must call this for every schema it advances.
     * M3's re-created roles use it too.
     */
    public static function reapplyGrants(string $role, string $schema): void
    {
        $role = RoleName::validate($role);
        $schema = SchemaName::validate($schema);

        self::admin()->transaction(function (Connection $admin) use ($role, $schema): void {
            self::applyGrants($admin, $role, $schema);
        });
    }

    /**
     * Drop the role, revoking its grants first when its schema still stands.
     *
     * Postgres refuses to drop a role while any ACL entry references it, so the
     * grants must go first. The textbook tool for that is `DROP OWNED BY`, and
     * it is NOT usable here: it requires holding the privileges OF the target
     * role, and Supabase's admin is not a superuser — it fails with
     * "Only roles with privileges of role ... may drop objects owned by it".
     * Explicit REVOKEs need only ownership of the tables, which the admin has.
     *
     * $schema is optional because the dependency only exists while the schema
     * does: after a `DROP SCHEMA ... CASCADE` the ACL entries went with the
     * tables and a bare DROP ROLE succeeds. Passing it is still correct in that
     * case (the exists() check skips the revokes), and it is required for M3's
     * delete-a-branch-role flow, where the business schema stays.
     */
    public static function drop(string $role, ?string $schema = null): void
    {
        $role = RoleName::validate($role);
        $admin = self::admin();

        if ($schema !== null && SchemaConnection::exists($schema)) {
            $schema = SchemaName::validate($schema);

            $admin->statement("revoke all privileges on all tables in schema \"{$schema}\" from \"{$role}\"");
            $admin->statement("revoke all privileges on schema \"{$schema}\" from \"{$role}\"");
        }

        $admin->statement("drop role if exists \"{$role}\"");
    }

    /**
     * The statements a human would run to clean up by hand.
     *
     * Printed when automatic compensation fails, so a half-provisioned business
     * is always accompanied by the exact SQL that finishes the job rather than
     * an instruction to work it out.
     *
     * Schema first, deliberately: CASCADE takes the tables and therefore the
     * grants with it, which is what lets the bare DROP ROLE that follows work
     * without superuser rights.
     *
     * @return list<string>
     */
    public static function cleanupSql(string $role, string $schema): array
    {
        return [
            "drop schema if exists \"{$schema}\" cascade;",
            "drop role if exists \"{$role}\";",
        ];
    }

    /**
     * The privilege matrix as executable SQL. Every GRANT is idempotent in
     * Postgres, so this is safe to re-run.
     */
    private static function applyGrants(Connection $admin, string $role, string $schema): void
    {
        // The only schema it can see. Nothing else is granted anywhere.
        $admin->statement("grant usage on schema \"{$schema}\" to \"{$role}\"");

        foreach (BranchGrants::SELECT as $table) {
            $admin->statement("grant select on \"{$schema}\".\"{$table}\" to \"{$role}\"");
        }

        foreach (BranchGrants::INSERT as $table) {
            $admin->statement("grant insert on \"{$schema}\".\"{$table}\" to \"{$role}\"");
        }

        foreach (BranchGrants::UPDATE as $table) {
            $admin->statement("grant update on \"{$schema}\".\"{$table}\" to \"{$role}\"");
        }

        foreach (BranchGrants::UPDATE_COLUMNS as $table => $columns) {
            $list = implode(', ', array_map(static fn (string $c): string => "\"{$c}\"", $columns));
            $admin->statement("grant update ({$list}) on \"{$schema}\".\"{$table}\" to \"{$role}\"");
        }

        // Pin server-side, so a client that never sets search_path still lands
        // in the right business — and cannot wander out of it by unsetting one.
        $admin->statement("alter role \"{$role}\" set search_path to \"{$schema}\"");

        // Explicit denials. No-ops against today's defaults; tripwires for the
        // day a Postgres or Supabase default changes underneath us.
        //
        // Note "revoke create", NOT "revoke all": revoke all on the schema would
        // strip the USAGE granted above and leave the role unable to see its own
        // business.
        $admin->statement("revoke create on schema \"{$schema}\" from \"{$role}\"");

        foreach (BranchGrants::NEVER as $table) {
            $admin->statement("revoke all on \"{$schema}\".\"{$table}\" from \"{$role}\"");
        }

        // Only direct grants can be revoked here; PUBLIC's own grants on
        // `public` survive by necessity (see the class docblock).
        $admin->statement("revoke all on schema public from \"{$role}\"");
    }

    private static function admin(): Connection
    {
        return DB::connection('pgsql_admin');
    }
}
