<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What a shipped build's `pgsql` connection defaults to.
 *
 * Nothing in the POS app opens this connection yet — M5's setup wizard hydrates
 * it from a credentials JSON — so every value here is a default that no other
 * test would notice changing. The port in particular: a revert from 6543 to 5432
 * would keep both suites green and surface much later as pool exhaustion in the
 * field, once enough branches exist to hit a 15-client session pool.
 *
 * Not in tests/Feature/Build/: that directory is about what a build EXCLUDES.
 * This is about what it ships.
 *
 * ## Why the config file is re-evaluated rather than read from config()
 *
 * BRANCH_DB_* is a documented developer escape hatch for phases M1–M4, so a
 * developer with BRANCH_DB_HOST set locally would fail a fail-closed assertion
 * for no reason. config/database.php is a pure function of the environment, so
 * clearing those keys and re-requiring it asserts the DEFAULTS on every machine
 * — which is what actually ships — rather than whatever this checkout resolves to.
 */
class BranchConnectionDefaultsTest extends TestCase
{
    private const BRANCH_KEYS = [
        'BRANCH_DB_URL',
        'BRANCH_DB_HOST',
        'BRANCH_DB_PORT',
        'BRANCH_DB_DATABASE',
        'BRANCH_DB_USERNAME',
        'BRANCH_DB_PASSWORD',
        'BRANCH_DB_SEARCH_PATH',
        'BRANCH_DB_SSLMODE',
    ];

    /** The eight keys the credentials JSON carries (BranchCredentials::document). */
    private const CREDENTIAL_KEYS = [
        'driver', 'host', 'port', 'database', 'username', 'password', 'search_path', 'sslmode',
    ];

    public function test_a_shipped_build_defaults_to_the_transaction_pooler(): void
    {
        // 6543 multiplexes Postgres backends across clients; 5432 pins one per
        // client for the connection's whole life. Branches need the former.
        $this->assertSame('6543', (string) $this->shippedPgsql()['port']);
    }

    public function test_a_shipped_build_never_pins_search_path_on_the_client(): void
    {
        $searchPath = $this->shippedPgsql()['search_path'];

        // NULL, and specifically not '': PostgresConnector::configureSearchPath
        // fires on isset(), so null emits nothing while '' would emit
        // `set search_path to ""` — a schema literally named "". The real pin is
        // server-side (ALTER ROLE), which is what survives transaction pooling.
        $this->assertNull($searchPath);
        $this->assertNotSame('', $searchPath);
    }

    public function test_a_shipped_build_requires_tls(): void
    {
        // 'prefer' silently downgrades to plaintext if the handshake fails.
        $this->assertSame('require', $this->shippedPgsql()['sslmode']);
    }

    public function test_a_shipped_build_with_no_imported_credentials_fails_closed(): void
    {
        $pgsql = $this->shippedPgsql();

        // A build that has not been through the setup wizard must reach NO
        // database, rather than some unrelated one.
        foreach (['url', 'host', 'database', 'username', 'password'] as $key) {
            $this->assertEmpty($pgsql[$key], "[{$key}] must be empty in a build with no imported credentials.");
        }
    }

    public function test_the_local_database_is_still_the_default_connection(): void
    {
        // pgsql is never selected by default; the till runs on local SQLite and
        // reaches Postgres only inside a sync cycle.
        $this->assertSame('sqlite', $this->shippedConfig()['default']);
    }

    /**
     * The credentials JSON is a strict SUBSET of this connection.
     *
     * M5 must therefore MERGE the JSON's `connection` block over this array
     * rather than assigning it wholesale — an assignment drops the keys asserted
     * below, and would silently discard any driver `options` a later fix adds.
     * The console suite would stay green while every shipped till lost them.
     *
     * The key list is duplicated in console/app/Support/BranchCredentials.php
     * because the two apps have separate autoloaders and §18 forbids a runtime
     * reference either way. This is the root-side half of that duplication.
     */
    public function test_the_credentials_json_block_is_a_strict_subset_of_the_shipped_connection(): void
    {
        $keys = array_keys($this->shippedPgsql());

        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertContains($key, $keys, "The JSON carries [{$key}]; the shipped connection must have it.");
        }

        // Deliberately not an exact count — a legitimate `options` addition must
        // not fail this. What matters is only that the array is strictly larger.
        $this->assertNotEmpty(
            array_diff($keys, self::CREDENTIAL_KEYS),
            'M5 must merge the JSON over this array, not replace it. If the two key sets ever '
            .'match exactly, that reasoning no longer holds and the hydration docs need revisiting.',
        );
    }

    /** @return array<string, mixed> */
    private function shippedPgsql(): array
    {
        /** @var array<string, mixed> $pgsql */
        $pgsql = $this->shippedConfig()['connections']['pgsql'];

        return $pgsql;
    }

    /**
     * config/database.php evaluated with every BRANCH_DB_* key cleared.
     *
     * All three channels, because Laravel's env repository reads $_SERVER and
     * $_ENV before the putenv adapter — clearing only putenv would leave a
     * developer's own value winning.
     *
     * @return array<string, mixed>
     */
    private function shippedConfig(): array
    {
        $prior = [];

        foreach (self::BRANCH_KEYS as $key) {
            $prior[$key] = [getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null];

            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        }

        try {
            /** @var array<string, mixed> $config */
            $config = require base_path('config/database.php');

            return $config;
        } finally {
            foreach ($prior as $key => [$env, $server, $envSuper]) {
                $env === false ? putenv($key) : putenv("{$key}={$env}");

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }

                if ($envSuper !== null) {
                    $_ENV[$key] = $envSuper;
                }
            }
        }
    }
}
