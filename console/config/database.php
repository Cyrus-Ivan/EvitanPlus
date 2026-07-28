<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | The console has exactly one connection of its own: the Supabase admin /
    | service role. It is the only thing in this monorepo allowed to run DDL
    | (MULTIBRANCH-BRAINSTORM.md §7, §8).
    |
    | The framework's stock sqlite/mysql/pgsql/... entries are merged in
    | underneath `connections` by LoadConfiguration; nothing selects them.
    |
    */

    'default' => env('CONSOLE_DB_CONNECTION', 'pgsql_admin'),

    'connections' => [

        /*
         * Admin connection — owns CREATE SCHEMA / CREATE ROLE / GRANT and the
         * Postgres migration set (§7, §8, §13).
         *
         * Point CONSOLE_DB_HOST/PORT at Supabase's SESSION endpoint: either
         * the direct connection (db.<project-ref>.supabase.co:5432, IPv6-only
         * without the IPv4 add-on) or the session pooler
         * (aws-<n>-<region>.pooler.supabase.com:5432).
         *
         * Do NOT point THIS connection at port 6543 (transaction pooler): it
         * does not preserve session state, which breaks `SET search_path` and
         * role management. The cost — one Postgres backend pinned for the whole
         * life of the connection — is irrelevant here, because the console is
         * one operator running one command.
         *
         * That prohibition is specific to `pgsql_admin` and does NOT generalise.
         * Branch devices are the opposite case: they connect over 6543 and are
         * configured by CONSOLE_BRANCH_DB_* (see SupabaseEndpoint::forBranch),
         * which defaults to this host on the transaction pooler's port. Session
         * mode's 15-client pool does not survive hundreds of branches, and a
         * branch needs none of the session state this connection depends on —
         * its schema is pinned server-side as a role default.
         */
        'pgsql_admin' => [
            'driver' => 'pgsql',
            'url' => env('CONSOLE_DB_URL'),
            'host' => env('CONSOLE_DB_HOST', '127.0.0.1'),
            'port' => env('CONSOLE_DB_PORT', '5432'),
            'database' => env('CONSOLE_DB_DATABASE', 'postgres'),
            'username' => env('CONSOLE_DB_USERNAME', 'postgres'),
            'password' => env('CONSOLE_DB_PASSWORD', ''),
            'charset' => env('CONSOLE_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,

            /*
             * Admin work runs in `public`. Phase M1 targets one business
             * schema by cloning this array at runtime:
             *
             *     config(['database.connections.pgsql_biz' => array_merge(
             *         config('database.connections.pgsql_admin'),
             *         ['search_path' => "biz_{$id}"],
             *     )]);
             *     DB::purge('pgsql_biz');
             *
             * Laravel's PostgresConnector::configureSearchPath issues
             * `set search_path to "..."` on connect, so the pin is
             * per-connection rather than per-query.
             */
            'search_path' => env('CONSOLE_DB_SEARCH_PATH', 'public'),

            // Supabase terminates TLS. Never 'prefer' — that silently
            // downgrades to plaintext if the handshake fails.
            'sslmode' => env('CONSOLE_DB_SSLMODE', 'require'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | The console's migration ledger lives INSIDE each business schema, so the
    | table name is plain `migrations` and the schema is selected by pinning
    | search_path on the connection (see above). Phase M1 owns that flow.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
