<?php

namespace Provisioning\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

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
     * Replace the role's password (§8.3 rotate).
     *
     * There is no "old password" parameter and no way to put one back: the
     * previous secret was written to one credentials file and never stored
     * anywhere the console can read. That is the design (§9), and it makes this
     * a one-way door — BranchIssuer reserves the new credentials file BEFORE
     * calling this, so the window in which a password can change without a file
     * to carry it is as close to zero as it can be made.
     *
     * Existing sessions are terminated, for the same reason disabling does it
     * (see setLogin). Postgres checks the password at connect time only, and
     * the pooler keeps warm connections keyed by username — so without this a
     * client holding the OLD password keeps working through an already-open
     * pooled session. Rotation is often performed precisely because the old
     * credential is suspect, and leaving it usable would defeat the point.
     *
     * The disruption is nil: the till keeps selling regardless, and a dropped
     * sync cycle simply retries (§4).
     */
    public static function setPassword(string $role, string $password): void
    {
        $role = RoleName::validate($role);
        $secret = BranchSecret::quote($password);

        self::admin()->statement("alter role \"{$role}\" with password {$secret}");

        self::terminateSessions($role);
    }

    /**
     * Revoke / grant LOGIN (§8.3 disable-enable).
     *
     * The whole of "disable" — grants are left exactly as they are. A role that
     * cannot log in cannot use them, and leaving them intact means enabling is
     * a single statement rather than a re-derivation of the privilege matrix
     * that could silently differ from what the branch had before.
     *
     * Deliberately NOT touched: branches.disabled_at. That column is business
     * state, pulled down to every till; a credential disabled because it may
     * have leaked is not a branch that has closed, and conflating the two would
     * make re-enabling a credential silently reopen a branch.
     *
     * Idempotent: ALTER ROLE ... NOLOGIN on an already-disabled role is a
     * no-op, so callers can report the resulting state rather than guarding.
     *
     * ## Disabling also terminates the role's sessions, and must
     *
     * Postgres checks LOGIN only at connect time, so NOLOGIN alone leaves
     * established sessions running. Behind Supabase's pooler that is not the
     * mild caveat it sounds like: Supavisor keeps warm server connections keyed
     * by username and satisfies NEW clients from that pool without
     * re-authenticating, so a disabled branch was measured still connecting
     * more than 90 seconds later. A "disable" that does not disable is worse
     * than no feature at all — the operator believes a suspect credential is
     * dead while it is still serving.
     *
     * Terminating the backends empties that pool and forces the next client
     * through authentication, where NOLOGIN refuses it. The till is unaffected
     * either way: a dropped connection is a failed sync, and a failed sync
     * never stops it selling (§4).
     */
    public static function setLogin(string $role, bool $canLogin): void
    {
        $role = RoleName::validate($role);

        self::admin()->statement(
            "alter role \"{$role}\" with ".($canLogin ? 'login' : 'nologin')
        );

        if (! $canLogin) {
            self::terminateSessions($role);
        }
    }

    /**
     * Close every open session belonging to a role.
     *
     * Best-effort by design: the admin is not a superuser, and if a future
     * Supabase change withdraws this privilege the disable itself has still
     * happened. Reporting a failure here would suggest the role is still
     * enabled, which is the opposite of the truth.
     */
    public static function terminateSessions(string $role): void
    {
        try {
            self::admin()->select(
                'select pg_terminate_backend(pid) from pg_stat_activity where usename = ?',
                [RoleName::validate($role)],
            );
        } catch (Throwable) {
            // Deliberately swallowed; see the docblock.
        }
    }

    /** Null when the role does not exist, so callers can tell that apart from disabled. */
    public static function canLogin(string $role): ?bool
    {
        $row = self::admin()->selectOne(
            'select rolcanlogin from pg_roles where rolname = ?',
            [RoleName::validate($role)],
        );

        return $row === null ? null : (bool) $row->rolcanlogin;
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
     *
     * ## All three statements are one transaction
     *
     * Run separately, a DROP ROLE that failed after the revokes had committed
     * left the role ALIVE and stripped of every privilege — the one state
     * nothing in the system can describe. The branch would still authenticate,
     * then fail every sync with `permission denied for table ...`, which is not
     * one of the four connection states a till can report, so it would show as
     * offline while being perfectly online. Meanwhile branch:revoke recorded
     * `rolled_back`, meaning "attempted, left nothing behind".
     *
     * REVOKE and DROP ROLE are both transactional in Postgres, so wrapping them
     * makes that outcome unreachable and makes `rolled_back` true when it is
     * reported.
     */
    public static function drop(string $role, ?string $schema = null): void
    {
        $role = RoleName::validate($role);
        $admin = self::admin();

        $revokeIn = $schema !== null && SchemaConnection::exists($schema)
            ? SchemaName::validate($schema)
            : null;

        $admin->transaction(function () use ($admin, $role, $revokeIn): void {
            if ($revokeIn !== null) {
                $admin->statement("revoke all privileges on all tables in schema \"{$revokeIn}\" from \"{$role}\"");
                $admin->statement("revoke all privileges on schema \"{$revokeIn}\" from \"{$role}\"");
            }

            $admin->statement("drop role if exists \"{$role}\"");
        });
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
     * The statements that remove ONE ROLE and nothing else.
     *
     * Separate from cleanupSql() because that one starts with
     * `drop schema ... cascade`, which is correct for a business provision that
     * created the schema in the same run and catastrophic for anything in M3,
     * where the schema holds other branches' sales history. Handing an operator
     * the wrong list at the moment something has already gone wrong is how a
     * recovery becomes an incident, so the two lists are different methods
     * rather than one with a flag.
     *
     * @return list<string>
     */
    public static function dropRoleSql(string $role, string $schema): array
    {
        return [
            "revoke all privileges on all tables in schema \"{$schema}\" from \"{$role}\";",
            "revoke all privileges on schema \"{$schema}\" from \"{$role}\";",
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
