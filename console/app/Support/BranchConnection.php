<?php

namespace Provisioning\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Opens a connection authenticated AS a branch role.
 *
 * Two uses, both of which need the branch's own credentials rather than the
 * admin's:
 *
 *  1. Provisioning verifies its own output — it connects as the freshly created
 *     role and reads the seeded data before reporting success. That is what
 *     turns "emitted a JSON" into "emitted a JSON that works", and it is the
 *     only way to settle the Supabase pooler-username question truthfully
 *     rather than by reading documentation (see SupabaseEndpoint).
 *  2. The negative grant tests: proving the role CANNOT delete, update or run
 *     DDL requires being that role. An admin connection bypasses grants
 *     entirely and would pass every such test vacuously.
 *
 * Deliberately NOT a block in console/config/database.php. A configured
 * connection implies env keys to populate it, and a BRANCH_DB_PASSWORD in
 * console/.env is exactly the durable copy of a delivered credential that §9's
 * one-time-JSON rule exists to prevent. Here the credentials live only in the
 * arguments, for the life of one command.
 */
final class BranchConnection
{
    public const NAME = 'pgsql_branch';

    /**
     * Take the admin connection's driver settings, the BRANCH endpoint, and the
     * branch's own credentials.
     *
     * The endpoint comes from SupabaseEndpoint::forBranch() rather than from the
     * admin config, because the two differ by design: the admin administers over
     * the session pooler and branches connect over the transaction pooler. Using
     * the admin's would mean verifying every issued credential against an
     * endpoint no branch ever uses — the check would still pass and would no
     * longer mean anything. Only driver, charset and prefix survive the clone.
     *
     * $searchPath is null by default on purpose: leaving it unset proves the
     * server-side `ALTER ROLE ... SET search_path` pin is doing the work. If
     * this passed the schema explicitly, a missing pin would be invisible —
     * the client would look correct while any other client of that role landed
     * in `public` and found nothing.
     */
    public static function open(string $username, string $password, ?string $searchPath = null): Connection
    {
        /** @var array<string, mixed> $config */
        $config = config('database.connections.pgsql_admin');

        $endpoint = SupabaseEndpoint::forBranch();

        // A CONSOLE_DB_URL is merged OVER everything set below, username
        // included (ConfigurationUrlParser::parseConfiguration), so a "branch"
        // connection would silently be the admin — every negative grant test
        // would pass vacuously and verify() would prove nothing. Unset before
        // it can win.
        unset($config['url']);

        $config['host'] = $endpoint->host;
        $config['port'] = $endpoint->port;
        $config['database'] = $endpoint->database;
        $config['sslmode'] = $endpoint->sslmode;

        $config['username'] = $username;
        $config['password'] = $password;

        // Unset, not empty string: Laravel's PostgresConnector issues
        // `set search_path` for any present value, and '' would blank the pin.
        unset($config['search_path']);

        if ($searchPath !== null) {
            $config['search_path'] = SchemaName::validate($searchPath);
        }

        config(['database.connections.'.self::NAME => $config]);

        // Purge before handing it back: a previous branch's session would
        // otherwise be reused under the new name, and every assertion made
        // against it would be about the wrong role.
        DB::purge(self::NAME);

        return DB::connection(self::NAME);
    }

    /**
     * Close the session and forget the credentials.
     *
     * Must be called before dropping the role or the schema: an open session
     * holds locks that block `DROP SCHEMA`, and Postgres refuses to drop a role
     * that is still connected.
     */
    public static function close(): void
    {
        DB::purge(self::NAME);

        config(['database.connections.'.self::NAME => null]);
    }
}
