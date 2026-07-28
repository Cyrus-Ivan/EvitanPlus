<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('nativephp.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            // Wait for a competing writer instead of failing SQLITE_BUSY
            // immediately — NativePHP runs several PHP workers on one file.
            'busy_timeout' => 5000,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                // PHP 8.4 deprecated the PDO::MYSQL_ATTR_* constants in favour of
                // the Pdo\Mysql class constants. Pick the right one for the runtime
                // so the config loads cleanly on both (NativePHP may bundle < 8.4).
                (PHP_VERSION_ID >= 80400 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                // PHP 8.4 deprecated the PDO::MYSQL_ATTR_* constants in favour of
                // the Pdo\Mysql class constants. Pick the right one for the runtime
                // so the config loads cleanly on both (NativePHP may bundle < 8.4).
                (PHP_VERSION_ID >= 80400 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * The shipped POS app talks to Postgres as a least-privilege *branch*
         * role (MULTIBRANCH-BRAINSTORM.md §7). Those credentials never live in
         * a build: the setup wizard (§16) imports them from the provisioning
         * JSON and stores them encrypted at rest under the per-install APP_KEY
         * (§9). Phase M5 hydrates this array at runtime, before the first
         * query — MERGING over it, never replacing it:
         *
         *     config(['database.connections.pgsql' => array_merge(
         *         config('database.connections.pgsql'),
         *         $credentials,          // the JSON's `connection` block
         *     )]);
         *     DB::purge('pgsql');
         *
         * The merge is not a style preference. The JSON carries eight keys and
         * this array has twelve, so a wholesale assignment silently drops
         * `charset`, `prefix_indexes` and any driver `options` — and the console
         * suite would stay green while every shipped till lost them.
         *
         * The defaults below are therefore deliberately EMPTY: a build with no
         * imported credentials must fail closed rather than reach some
         * unrelated database. The BRANCH_DB_* keys are a developer escape
         * hatch for phases M1–M4 only. They use their own prefix — never DB_*,
         * which belongs to the local SQLite connection and must keep working
         * inside shipped builds — and are stripped from every build by
         * nativephp.cleanup_env_keys.
         *
         * `sqlite` remains the default connection; see 'default' above.
         *
         * Connection lifetime is normative from M5 on: a till connects, runs one
         * sync cycle, and disconnects (§11.5). It never holds this open.
         */
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('BRANCH_DB_URL'),
            'host' => env('BRANCH_DB_HOST'),
            // Supabase's TRANSACTION pooler, not the session pooler on 5432 the
            // provisioning console administers through. Session mode pins one
            // Postgres backend per client for the client's whole life, and the
            // pool is 15 wide — which does not survive hundreds of branches.
            // Transaction mode multiplexes backends, holding one only for the
            // length of a transaction. Safe here because nothing in a sync cycle
            // needs session state (§11.5) and the branch role's schema is pinned
            // server-side by `ALTER ROLE ... SET search_path` (§7).
            'port' => env('BRANCH_DB_PORT', '6543'),
            'database' => env('BRANCH_DB_DATABASE'),
            'username' => env('BRANCH_DB_USERNAME'),
            'password' => env('BRANCH_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // Stays NULL: the schema is pinned server-side as a role default, so
            // Postgres applies it at backend startup and pooling cannot lose it.
            // A connect-time `SET` would not survive transaction mode, which is
            // why the credentials JSON emits `search_path: null` too (§9).
            // NULL, not '': PostgresConnector::configureSearchPath only fires
            // when the key isset(), and an empty string would emit
            // `set search_path to ""` — a schema literally named "".
            'search_path' => env('BRANCH_DB_SEARCH_PATH') ?: null,
            // Supabase requires TLS. 'prefer' would silently downgrade.
            'sslmode' => env('BRANCH_DB_SSLMODE', 'require'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
