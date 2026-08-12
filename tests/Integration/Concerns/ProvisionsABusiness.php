<?php

namespace Tests\Integration\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;

/**
 * The harness the `supabase`-group tests share: provision a throwaway business
 * through the console CLI, hold an admin connection for seeding and cleanup, and
 * destroy the schema and role afterwards.
 *
 * ## The console is a subprocess, never a dependency
 *
 * §18 forbids any runtime reference from the POS app to console code, and that
 * boundary is structural — separate app, separate autoload, separate .env — so
 * the only honest way to provision from here is to run `console/artisan` the way
 * a developer does. Reading `console/.env` as a FILE rather than loading its
 * config is the same rule applied to credentials.
 *
 * ## Cleanup is best-effort on purpose
 *
 * A run that is killed never reaches `tearDownAfterClass`, and the console suite
 * settled this already: a rare manual sweep is cheaper than a reaper worth
 * trusting. An earlier attempt at automated pruning grew ~330 lines of custodial
 * machinery guarding nothing but its own litter, and was deleted.
 *
 *   select schema_name from information_schema.schemata where schema_name like 'biz_%';
 */
trait ProvisionsABusiness
{
    /** Where this class's credentials JSONs live for the duration of the run. */
    private string $directory;

    /**
     * The console's admin credentials, read from its .env as a FILE.
     *
     * @return array<string, string>
     */
    private function consoleEnv(): array
    {
        $file = base_path('console/.env');

        if (! is_file($file)) {
            return [];
        }

        $values = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[trim($key)] = trim(trim($value), "'\"");
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function adminConnection(array $env): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $env['CONSOLE_DB_HOST'],
            $env['CONSOLE_DB_PORT'] ?? '5432',
            $env['CONSOLE_DB_DATABASE'] ?? 'postgres',
            $env['CONSOLE_DB_SSLMODE'] ?? 'require',
        );

        return new PDO($dsn, $env['CONSOLE_DB_USERNAME'], $env['CONSOLE_DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * A `console/artisan` invocation that does not inherit this app's identity.
     *
     * The unset list is load-bearing and was found the hard way. Laravel's
     * Dotenv calls `putenv()`, so the POS app's `.env` becomes real environment
     * variables that any child process inherits — and Dotenv does **not**
     * overwrite a variable that is already set, so `console/.env` loses to them.
     * The console therefore ran with the POS app's `APP_TIMEZONE=Asia/Manila`
     * instead of its own `UTC`, and stamped every `provisioning_audit` row eight
     * hours into the future. Harmless-looking until an assertion depended on
     * "the most recent audit row", which then read one from a previous test.
     *
     * It is a test-harness leak rather than a console bug — the console is
     * correct standing alone, which is exactly why nothing caught it since M5.
     *
     * @param  list<string>  $arguments
     */
    private function consoleCommand(array $arguments): string
    {
        return 'env -u APP_TIMEZONE -u APP_ENV -u APP_KEY -u APP_DEBUG -u DB_CONNECTION -u DB_DATABASE '
            .implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                base_path('console/artisan'),
                ...$arguments,
            ]));
    }

    /**
     * Provision a throwaway business through the console CLI.
     *
     * @return array<string, mixed> the credentials document it emitted
     */
    private function provisionBusiness(string $businessName, string $ownerUsername, string $branchCode, string $actor): array
    {
        $out = $this->directory.'/provisioned.json';

        @unlink($out);

        $command = $this->consoleCommand([
            'business:provision',
            '--owner-name='.$businessName.' Owner',
            '--username='.$ownerUsername,
            '--owner-password=a-provisioned-password',
            '--business-name='.$businessName,
            '--business-address=1 Test Street',
            '--tin=000-000-000',
            '--receipt-footer=Salamat po!',
            '--timezone=Asia/Manila',
            '--currency-symbol=₱',
            '--branch-name='.$businessName.' Branch',
            '--branch-code='.$branchCode,
            '--branch-address=1 Test Street',
            '--out='.$out,
            '--actor='.$actor,
            // biz_test_<hex>, so an integration run killed before its teardown
            // leaves something `composer rig:clean:all` can sweep.
            '--disposable',
            '--force',
            '--no-interaction',
        ]);

        exec($command.' 2>&1', $output, $status);

        $this->assertSame(0, $status, "business:provision failed:\n".implode("\n", $output));

        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($out), true);

        return $document;
    }

    /**
     * Drop a provisioned schema and its role. Never masks the class's real
     * result — a failure here is litter, not a test outcome.
     */
    private static function destroyBusiness(?PDO $admin, ?string $schema, ?string $role): void
    {
        if ($admin === null || $schema === null) {
            return;
        }

        foreach ([
            'drop schema if exists "'.$schema.'" cascade',
            'drop role if exists "'.$role.'"',
        ] as $statement) {
            try {
                $admin->exec($statement);
            } catch (\Throwable) {
                //
            }
        }
    }

    /** @param array<string, mixed> $document */
    private function writeCredentialsFile(array $document, string $name = 'credentials.json'): string
    {
        $path = $this->directory.'/'.$name;

        File::put($path, (string) json_encode($document));

        return $path;
    }

    private function uuid(): string
    {
        return (string) Str::orderedUuid();
    }
}
