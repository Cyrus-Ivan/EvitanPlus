<?php

namespace Provisioning\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Targets one business schema by cloning the admin connection with its
 * search_path pinned — the flow console/config/database.php documents.
 *
 * Pinning search_path rather than qualifying every statement is what lets the
 * whole Postgres migration set be written as ordinary, schema-agnostic Laravel
 * migrations: the same files build biz_a and biz_b, and Laravel's own
 * `migrations` ledger lands inside whichever schema is pinned, so each
 * business tracks its migration state independently.
 *
 * Laravel's PostgresConnector::configureSearchPath issues `set search_path`
 * once on connect, so the pin is per-connection, not per-query.
 */
final class SchemaConnection
{
    /** Connection name reserved for the currently-pinned business schema. */
    public const NAME = 'pgsql_business';

    /**
     * Point self::NAME at $schema and return the live connection.
     *
     * Always purges first: connections are cached by name, so re-pinning
     * without a purge would silently keep talking to the previous schema —
     * which, in a tool that provisions many businesses in one run, would write
     * one business's data into another's.
     */
    public static function pin(string $schema): Connection
    {
        $schema = SchemaName::validate($schema);

        // Already pointed here: hand back the live connection untouched.
        // Purging unconditionally would drop a perfectly good session — and
        // silently null the PDO on any Connection object the caller is still
        // holding, so their next query reconnects.
        if (config('database.connections.'.self::NAME.'.search_path') === $schema) {
            return DB::connection(self::NAME);
        }

        config([
            'database.connections.'.self::NAME => array_merge(
                config('database.connections.pgsql_admin'),
                ['search_path' => $schema],
            ),
        ]);

        // Re-targeting: the cached connection is pinned to the PREVIOUS schema,
        // so it must go. Without this, provisioning a second business in one
        // run would write into the first one's schema.
        DB::purge(self::NAME);

        return DB::connection(self::NAME);
    }

    /**
     * Create the schema if it is not already there, using the ADMIN connection
     * (still pointed at public) — the schema cannot be pinned before it exists.
     *
     * Returns true when this call created it, so callers can report
     * "created" versus "already present" honestly.
     */
    public static function ensureExists(string $schema): bool
    {
        $schema = SchemaName::validate($schema);

        if (self::exists($schema)) {
            return false;
        }

        // Identifier, not a value: it cannot be bound. Safe only because
        // SchemaName::validate has constrained the character set.
        DB::connection('pgsql_admin')->statement("create schema \"{$schema}\"");

        return true;
    }

    public static function exists(string $schema): bool
    {
        return DB::connection('pgsql_admin')->selectOne(
            'select 1 as found from information_schema.schemata where schema_name = ?',
            [SchemaName::validate($schema)],
        ) !== null;
    }
}
