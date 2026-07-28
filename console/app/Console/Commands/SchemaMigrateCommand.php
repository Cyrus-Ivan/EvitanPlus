<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Throwable;

/**
 * Phase M1 milestone command: build a correctly-constrained business schema.
 *
 * Creates biz_<id> if absent and runs the Postgres migration set into it. The
 * console owns all DDL (§7, §8) — no branch role and no shipped app can do
 * this, by grant and by the fact that this code never ships.
 *
 * Idempotent: re-running applies only migrations the schema has not seen, so
 * this doubles as the M-phase upgrade path (§8.4) rather than being a
 * create-once command.
 */
class SchemaMigrateCommand extends Command
{
    protected $signature = 'schema:migrate
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--pretend : Print the SQL instead of running it}';

    protected $description = 'Create a business schema if needed and migrate it to the latest version';

    public function handle(): int
    {
        try {
            $schema = SchemaName::resolve($this->option('business'), $this->option('schema'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        if ($this->option('pretend')) {
            return $this->preview($schema);
        }

        $this->components->info("Migrating schema [{$schema}]");

        try {
            $created = SchemaConnection::ensureExists($schema);

            $this->components->twoColumnDetail(
                'Schema',
                $created ? '<fg=green>created</>' : 'already present',
            );

            SchemaConnection::pin($schema);
        } catch (Throwable $e) {
            $this->components->error('Could not prepare the schema: '.$e->getMessage());

            return self::FAILURE;
        }

        // --force: the console is a developer tool but APP_ENV may be anything,
        // and there is no interactive operator to answer a production prompt.
        //
        // Wrapped: MigrateCommand::handle() returns 0 or throws — it never
        // returns a non-zero code with --force — so the exit check below was
        // unreachable and a genuinely broken migration escaped as a raw SQL
        // stack trace, with no schema name and no hint that re-running is the
        // documented recovery.
        try {
            $exit = $this->call('migrate', array_filter([
                '--database' => SchemaConnection::NAME,
                '--path' => 'database/migrations',
                '--force' => true,
            ]));
        } catch (Throwable $e) {
            return $this->migrationFailed($schema, $e->getMessage());
        }

        if ($exit !== self::SUCCESS) {
            return $this->migrationFailed($schema, 'the migrator exited with '.$exit);
        }

        $this->newLine();
        $this->call('schema:version', ['--schema' => $schema]);

        return self::SUCCESS;
    }

    /**
     * Say what --pretend claims to do: print, and change nothing.
     *
     * It used to create the schema before it read the flag, and Laravel's own
     * MigrateCommand calls migrate:install before it reads the flag too — so
     * `--pretend` against a fresh UUID left behind a schema containing only
     * `migrations`, which nothing in this tool could then reach:
     * business:provision refuses it as create-only, the branch commands refuse
     * it as not-a-business, and the only exit was a hand-written DROP SCHEMA.
     *
     * There is nothing to preview against a schema that does not exist yet, so
     * that case is reported rather than manufactured.
     */
    private function preview(string $schema): int
    {
        $this->components->info("Previewing migrations for [{$schema}] — nothing will be changed");

        try {
            $exists = SchemaConnection::exists($schema);
            $started = $exists && SchemaConnection::hasTable($schema, 'migrations');
        } catch (Throwable $e) {
            $this->components->error('Could not inspect the schema: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $started) {
            $this->components->twoColumnDetail('Schema', $exists ? 'exists, never migrated' : 'would be created');
            $this->components->twoColumnDetail('Migrations', $this->migrationCount().' would run, all of them');
            $this->newLine();
            $this->components->bulletList([
                'The SQL cannot be printed until the schema exists — the migrator reads its ledger first.',
                'Run the same command without --pretend to create and migrate it.',
            ]);

            return self::SUCCESS;
        }

        SchemaConnection::pin($schema);

        try {
            $this->call('migrate', array_filter([
                '--database' => SchemaConnection::NAME,
                '--path' => 'database/migrations',
                '--force' => true,
                '--pretend' => true,
            ]));
        } catch (Throwable $e) {
            return $this->migrationFailed($schema, $e->getMessage());
        }

        return self::SUCCESS;
    }

    private function migrationCount(): int
    {
        return count(glob(database_path('migrations').'/*.php') ?: []);
    }

    private function migrationFailed(string $schema, string $reason): int
    {
        $this->newLine();
        $this->components->error("Migration failed for [{$schema}]: {$reason}");
        $this->components->bulletList([
            'The schema is left partially migrated; re-running this command applies only what is missing.',
            'schema:version reports how far it got.',
        ]);

        return self::FAILURE;
    }
}
