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
        $exit = $this->call('migrate', array_filter([
            '--database' => SchemaConnection::NAME,
            '--path' => 'database/migrations',
            '--force' => true,
            '--pretend' => (bool) $this->option('pretend'),
        ]));

        if ($exit !== self::SUCCESS) {
            $this->components->error("Migration failed for [{$schema}].");

            return self::FAILURE;
        }

        if ($this->option('pretend')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->call('schema:version', ['--schema' => $schema]);

        return self::SUCCESS;
    }
}
