<?php

namespace Provisioning\Console\Commands;

use InvalidArgumentException;
use Provisioning\Support\BusinessDirectory;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Provisioning\Support\SchemaStamp;
use Provisioning\Support\SchemaVersion;
use RuntimeException;
use Throwable;

/**
 * Apply pending Postgres migrations to one business schema or to all of them
 * (M1 milestone; completed by M9's §8.4).
 *
 * Creates biz_<id> if absent and runs the Postgres migration set into it. The
 * console owns all DDL (§7, §8) — no branch role and no shipped app can do
 * this, by grant and by the fact that this code never ships.
 *
 * Idempotent: re-running applies only migrations the schema has not seen, which
 * is what makes this the upgrade path rather than a create-once command.
 *
 * ## What M9 added, and why each piece is here rather than left to the operator
 *
 * **The version stamp.** `schema_meta` used to be written once, by migration
 * 000001, and could therefore be set but never raised. A twentieth migration
 * would have left every business still declaring version 1 and a min-app floor
 * of 1.0.0, so a breaking change could never freeze the branches it needed to
 * freeze. The pair now lives in {@see SchemaVersion} and is
 * re-stamped here after every clean run ({@see SchemaStamp}).
 *
 * **The re-grant.** Grants are enumerated per table on purpose (BranchGrants:
 * no ON ALL TABLES, no ALTER DEFAULT PRIVILEGES), so a migration that ADDS a
 * table leaves every branch role without privileges on it. The till's symptom is
 * "Sync stuck" — `online` + `conflict`, because `permission denied` is SQLSTATE
 * 42501 — which reads like a data problem and is actually a missing GRANT. That
 * was a documented caveat for six phases and a footgun the whole time, so a
 * schema that ADVANCED is re-granted in the same breath.
 *
 * **The frozen-branches warning.** A raised min-app version is the one migration
 * outcome with a consequence at every till of that business, and this is the
 * moment the operator can still think about it. Which tills are actually behind
 * is not knowable here — only `branch_status` carries a device's app version, and
 * nothing writes it until M10's heartbeat.
 */
class SchemaMigrateCommand extends BranchCommand
{
    protected $signature = 'schema:migrate
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--all : Every biz_* schema in this project}
        {--pretend : Print the SQL instead of running it}
        {--actor= : Recorded as the operator in the provisioning audit}
        {--force : Skip the confirmation --all asks for}';

    protected $description = 'Migrate one business schema, or all of them, to the latest version';

    public function handle(): int
    {
        try {
            $schemas = $this->targetSchemas();
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        if ($schemas === []) {
            $this->components->info('No biz_* schemas found — nothing to migrate.');

            return self::SUCCESS;
        }

        if ($this->option('pretend')) {
            return $this->previewAll($schemas);
        }

        if (count($schemas) > 1 && ! $this->confirmed(
            'Migrate '.count($schemas).' schemas to the latest version?'
        )) {
            $this->components->info('Nothing was migrated.');

            return self::SUCCESS;
        }

        $failed = [];

        foreach ($schemas as $schema) {
            if ($this->migrateOne($schema) !== self::SUCCESS) {
                $failed[] = $schema;
            }
        }

        return count($schemas) === 1
            ? ($failed === [] ? self::SUCCESS : self::FAILURE)
            : $this->summarise($schemas, $failed);
    }

    /**
     * The schemas this invocation targets.
     *
     * `--all` deliberately does NOT filter to provisioned businesses the way
     * schema:reapply-grants does. Provisioned-ness is DEFINED by having
     * schema_meta and a branches table — exactly what a schema needing migration
     * may be missing — so filtering would hide the schemas this command exists to
     * fix. A leftover biz_test_* fixture looks the same and gets migrated too;
     * that is harmless, and README §7 is how those are swept.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    private function targetSchemas(): array
    {
        if (! (bool) $this->option('all')) {
            return [SchemaName::resolve($this->stringOption('business'), $this->stringOption('schema'))];
        }

        if ($this->stringOption('business') !== null || $this->stringOption('schema') !== null) {
            throw new InvalidArgumentException('--all cannot be combined with --business or --schema.');
        }

        return array_values(array_map(
            fn (array $business): string => $business['schema'],
            BusinessDirectory::all(),
        ));
    }

    /** One schema, end to end. */
    private function migrateOne(string $schema): int
    {
        $this->newLine();
        $this->components->info("Migrating schema [{$schema}]");

        try {
            $created = SchemaConnection::ensureExists($schema);

            $this->components->twoColumnDetail(
                'Schema',
                $created ? '<fg=green>created</>' : 'already present',
            );

            $applied = $this->appliedMigrations($schema);

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
            $exit = $this->call('migrate', [
                '--database' => SchemaConnection::NAME,
                '--path' => 'database/migrations',
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            return $this->migrationFailed($schema, $e->getMessage());
        }

        if ($exit !== self::SUCCESS) {
            return $this->migrationFailed($schema, 'the migrator exited with '.$exit);
        }

        // ADVANCED: at least one pending migration ran, as against a re-run that
        // was a no-op (docs/glossary.md §7). Only an advanced schema can have
        // gained a table its branch roles hold no privilege on, and only an
        // advanced schema is worth re-granting.
        $advanced = $this->appliedMigrations($schema) > $applied;

        try {
            $stamp = (new SchemaStamp)->apply(SchemaConnection::pin($schema));
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error("Could not stamp the version for [{$schema}]: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->reportVersion($stamp, $advanced);
        $this->recordMigration($schema, $stamp, $advanced);

        // A schema this run CREATED has no branch roles — they are made after it,
        // by business:provision — so there is nothing to re-grant and saying so
        // would be noise in the middle of a provision.
        if ($advanced && ! $created) {
            return $this->regrant($schema);
        }

        if (! $advanced) {
            $this->components->twoColumnDetail('Branch grants', '<fg=gray>unchanged — the schema was already current</>');
        }

        return self::SUCCESS;
    }

    /**
     * Hand the re-grant to the command that already does it properly: per-role
     * reporting, revoked roles skipped rather than failed, disabled roles granted
     * anyway, and one audit row each.
     *
     * Its failure fails this command. The schema is migrated and its roles cannot
     * use what the migration added — reporting success over that would leave the
     * operator to discover it as "Sync stuck" at a till, which is the exact
     * outcome M9 exists to prevent.
     */
    private function regrant(string $schema): int
    {
        $this->newLine();
        $this->components->info('Re-applying branch grants — the schema advanced');

        $exit = $this->call('schema:reapply-grants', array_filter([
            '--schema' => $schema,
            '--actor' => $this->stringOption('actor'),
        ]));

        if ($exit !== self::SUCCESS) {
            $this->components->error(
                "[{$schema}] is migrated but its branch roles were not re-granted. "
                ."Until they are, those tills report 'Sync stuck'. Recovery: "
                ."php console/artisan schema:reapply-grants --schema={$schema}"
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The version transition, and the one consequence an operator can still act
     * on while they are standing here.
     *
     * @param  array{from: array{schema_version: int, min_app_version: string}, to: array{schema_version: int, min_app_version: string}, changed: bool, min_app_raised: bool}  $stamp
     */
    private function reportVersion(array $stamp, bool $advanced): void
    {
        $from = $stamp['from'];
        $to = $stamp['to'];

        $this->components->twoColumnDetail('Migrations', $advanced ? '<fg=green>advanced</>' : 'already current');

        $this->components->twoColumnDetail(
            'Schema version',
            $from['schema_version'] !== $to['schema_version']
                ? "{$from['schema_version']} <fg=gray>→</> <fg=green>{$to['schema_version']}</>"
                : (string) $to['schema_version'],
        );

        $this->components->twoColumnDetail(
            'Min app version',
            $stamp['min_app_raised']
                ? "{$from['min_app_version']} <fg=gray>→</> <fg=yellow>{$to['min_app_version']}</>"
                : $to['min_app_version'],
        );

        if (! $stamp['min_app_raised']) {
            return;
        }

        // Kept to short lines on purpose: these are read in a terminal, and the
        // one sentence that matters must not be the one that wrapped.
        $this->newLine();
        $this->components->warn("The minimum app version rose to {$to['min_app_version']}.");
        $this->components->bulletList([
            "Branches below {$to['min_app_version']} stop syncing until updated.",
            'They keep selling; their backlog goes up when they are.',
            'Which tills are behind is M10\'s monitor, not visible here.',
        ]);
    }

    /**
     * §8.6 names migrate among the audited actions, and it was the only one in
     * that list writing nothing. Recorded quietly rather than behind isReady():
     * the migration has already happened by this point, and an unreachable trail
     * must not turn a successful upgrade into a reported failure.
     *
     * @param  array{from: array{schema_version: int, min_app_version: string}, to: array{schema_version: int, min_app_version: string}, changed: bool, min_app_raised: bool}  $stamp
     */
    private function recordMigration(string $schema, array $stamp, bool $advanced): void
    {
        $target = ['schema_name' => $schema];

        try {
            $target['business_id'] = SchemaName::toBusinessUuid($schema);
        } catch (Throwable) {
            // A biz_ schema encoding no UUID — the suite's fixtures. The trail
            // still wants the row, just without the business index.
        }

        $this->recordQuietly($this->audit(), 'schema.migrate', ProvisioningAudit::SUCCEEDED, $target, [
            'advanced' => $advanced,
            'schema_version' => $stamp['to']['schema_version'],
            'min_app_version' => $stamp['to']['min_app_version'],
            'previous_schema_version' => $stamp['from']['schema_version'],
            'previous_min_app_version' => $stamp['from']['min_app_version'],
        ]);
    }

    /**
     * Migrations the schema has already applied; 0 when it has no ledger yet.
     *
     * Counted rather than compared against the file list on disk, because the
     * question is only ever "did this run apply anything", and the ledger is the
     * one source that answers it after the fact as well as before.
     */
    private function appliedMigrations(string $schema): int
    {
        if (! SchemaConnection::hasTable($schema, 'migrations')) {
            return 0;
        }

        $row = SchemaConnection::pin($schema)->selectOne('select count(*) as applied from migrations');

        return (int) ($row->applied ?? 0);
    }

    /**
     * @param  list<string>  $schemas
     * @param  list<string>  $failed
     */
    private function summarise(array $schemas, array $failed): int
    {
        $this->newLine();

        if ($failed === []) {
            $this->components->info(count($schemas).' schemas are at the latest version.');

            return self::SUCCESS;
        }

        $this->components->error(
            count($failed).' of '.count($schemas).' schemas failed: '.implode(', ', $failed)
        );
        $this->components->bulletList([
            'The rest were migrated. Re-running applies only what is missing, so it is safe.',
        ]);

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $schemas
     */
    private function previewAll(array $schemas): int
    {
        $worst = self::SUCCESS;

        foreach ($schemas as $schema) {
            if ($this->preview($schema) !== self::SUCCESS) {
                $worst = self::FAILURE;
            }
        }

        return $worst;
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
     *
     * Nothing here stamps a version or re-grants a role either, for the same
     * reason: both are changes, and a preview makes none.
     */
    private function preview(string $schema): int
    {
        $this->newLine();
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
            $this->call('migrate', [
                '--database' => SchemaConnection::NAME,
                '--path' => 'database/migrations',
                '--force' => true,
                '--pretend' => true,
            ]);
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
            'Its version is NOT stamped and its grants are NOT re-applied until a run completes.',
            'schema:version reports how far it got.',
        ]);

        return self::FAILURE;
    }
}
