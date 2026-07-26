<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Throwable;

/**
 * Report a business schema's version — the other half of the M1 milestone.
 *
 * Two numbers matter and they are not the same thing (§13): `schema_version`
 * is what this schema has been migrated to, and `min_app_version` is the
 * oldest app build still allowed to sync with it. A branch below the minimum
 * is refused in both directions and keeps selling offline, so this command is
 * how you tell whether a branch that has stopped syncing is version-behind
 * rather than broken.
 */
class SchemaVersionCommand extends Command
{
    protected $signature = 'schema:version
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}';

    protected $description = "Report a business schema's version and minimum compatible app version";

    public function handle(): int
    {
        try {
            $schema = SchemaName::resolve($this->option('business'), $this->option('schema'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        try {
            if (! SchemaConnection::exists($schema)) {
                $this->components->error("Schema [{$schema}] does not exist — run schema:migrate first.");

                return self::FAILURE;
            }

            $connection = SchemaConnection::pin($schema);

            $meta = $connection->selectOne('select schema_version, min_app_version, applied_at from schema_meta');

            $migrations = $connection->selectOne(
                'select count(*) as applied, max(migration) as latest from migrations'
            );
        } catch (Throwable $e) {
            // An existing schema with no schema_meta is a half-built schema,
            // not a healthy one — say so rather than reporting a blank version.
            $this->components->error("Could not read the schema version: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Schema [{$schema}]");
        $this->components->twoColumnDetail('Schema version', (string) ($meta->schema_version ?? '—'));
        $this->components->twoColumnDetail('Min app version', (string) ($meta->min_app_version ?? '—'));
        $this->components->twoColumnDetail('Applied at', (string) ($meta->applied_at ?? '—'));
        $this->components->twoColumnDetail('Migrations applied', (string) ($migrations->applied ?? 0));
        $this->components->twoColumnDetail('Latest migration', (string) ($migrations->latest ?? '—'));

        return self::SUCCESS;
    }
}
