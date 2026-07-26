<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Throwable;

/**
 * Base case for the console suite.
 *
 * These tests need a REAL Postgres. The invariants this phase exists to
 * establish — immutability triggers, partial unique indexes, check
 * constraints — are Postgres features; asserting them against anything else
 * would prove nothing about the schema that actually ships to Supabase.
 *
 * So the suite skips itself when no admin credentials are configured, which
 * keeps a fresh clone green and is why it stays out of the repo-root
 * `composer verify` chain.
 *
 * ## Why one schema per class, not per test
 *
 * Supabase's session pooler allows 15 clients, and applying the migration set
 * takes ~13s against a remote database. Building a schema per test method
 * exhausts the pool AND makes the suite unusably slow, so each class migrates
 * ONE throwaway schema and every test runs inside a transaction that is rolled
 * back — the standard Laravel arrangement, just with the setup cost hoisted.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The class's migrated schema. Static because it outlives the per-test
     * application refresh; dropped in tearDownAfterClass.
     */
    private static ?string $classSchema = null;

    /**
     * One PDO handle shared by every test in the class.
     *
     * Laravel refreshes the application per test, which would otherwise mean a
     * fresh DatabaseManager and a fresh TCP session each time. Against
     * Supabase's session pooler — 15 clients, and sessions are released
     * lazily — that exhausts the pool part-way through a class. Connections
     * resolve their PDO lazily, so handing each new Connection the handle we
     * already hold means the whole class costs exactly one session.
     */
    private static ?\PDO $sharedPdo = null;

    /**
     * The application itself is shared for the same reason.
     *
     * Laravel rebuilds it per test, and a rebuilt application means a new
     * DatabaseManager, which resolves a new Connection and therefore a new
     * session no matter what we do with the handle above. These tests assert
     * on a database, not on application state, so there is nothing for a
     * refresh to isolate — and keeping one application keeps the whole class
     * to a single Postgres session.
     */
    private static ?Application $sharedApp = null;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Reuse the shared application instead of building a fresh one per test.
     * Facades are re-pointed at it because the parent's tearDown clears them.
     */
    protected function refreshApplication(): void
    {
        self::$sharedApp ??= $this->createApplication();

        $this->app = self::$sharedApp;

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function setUp(): void
    {
        // Before parent::setUp(): with no credentials there is nothing worth
        // booting an application for.
        if (! $this->hasAdminCredentials()) {
            $this->markTestSkipped(
                'No admin Postgres credentials — set CONSOLE_DB_* in console/.env to run the console suite.'
            );
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Detach before the parent runs: it would otherwise flush the shared
        // application, taking the connection with it.
        $this->app = null;

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$classSchema !== null) {
            $schema = self::$classSchema;
            self::$classSchema = null;

            // Leaking this would accumulate biz_test_* schemas in the real
            // Supabase project, one per run.
            try {
                DB::connection('pgsql_admin')->statement("drop schema if exists \"{$schema}\" cascade");
            } catch (Throwable) {
                // Best effort: never mask the class's real result.
            }
        }

        // Release the class's session so the next class starts with a free
        // slot in the pooler.
        self::$sharedPdo = null;

        if (self::$sharedApp !== null) {
            self::$sharedApp->flush();
            self::$sharedApp = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * A throwaway schema with the full migration set applied, built once for
     * the class and returned pinned on SchemaConnection::NAME.
     */
    protected function migratedSchema(): Connection
    {
        $connection = SchemaConnection::pin($this->schemaName());

        if (self::$sharedPdo === null) {
            self::$sharedPdo = $connection->getPdo();
        } else {
            // A previous test may have died mid-transaction; the new Connection
            // object reports transactionLevel 0 regardless, so ask the handle
            // itself rather than trusting Laravel's counter.
            if (self::$sharedPdo->inTransaction()) {
                self::$sharedPdo->rollBack();
            }

            $connection->setPdo(self::$sharedPdo);
        }

        return $connection;
    }

    /**
     * The class's schema name, creating and migrating it on first use.
     *
     * Deliberately does NOT re-pin: callers hold the Connection returned by
     * migratedSchema(), and re-pinning would purge it out from under them.
     */
    protected function schemaName(): string
    {
        if (self::$classSchema === null) {
            $name = SchemaName::validate('biz_test_'.Str::lower(Str::random(12)));

            SchemaConnection::ensureExists($name);
            SchemaConnection::pin($name);

            Artisan::call('migrate', [
                '--database' => SchemaConnection::NAME,
                '--path' => 'database/migrations',
                '--force' => true,
            ]);

            self::$classSchema = $name;
        }

        return self::$classSchema;
    }

    private function hasAdminCredentials(): bool
    {
        $env = dirname(__DIR__).'/.env';

        if (! is_file($env)) {
            return false;
        }

        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'CONSOLE_DB_PASSWORD=')) {
                return trim(substr($line, \strlen('CONSOLE_DB_PASSWORD='))) !== '';
            }
        }

        return false;
    }
}
