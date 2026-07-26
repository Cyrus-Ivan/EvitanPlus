<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Throwable;

/**
 * Builds the console's OWN schema — the provisioning audit trail (§8.6).
 *
 * Deliberately separate from schema:migrate, which only ever targets biz_*.
 * Two migration sets with two different targets: mixing them would let the
 * business schema's 19 tables be built inside the audit store, or the audit
 * table inside a business schema where the parity guard forbids it.
 *
 * Idempotent, and the pre-flight for provisioning: provision:business refuses
 * to run until this has been applied, because an unauditable provision is not
 * one this tool is willing to perform.
 */
class ConsoleMigrateCommand extends Command
{
    protected $signature = 'console:migrate
        {--pretend : Print the SQL instead of running it}';

    protected $description = "Create and migrate the console's own schema (the provisioning audit trail)";

    public function handle(): int
    {
        $schema = SchemaName::CONSOLE;

        $this->components->info("Migrating console schema [{$schema}]");

        try {
            $created = SchemaConnection::ensureExists($schema);

            $this->components->twoColumnDetail(
                'Schema',
                $created ? '<fg=green>created</>' : 'already present',
            );

            SchemaConnection::pin($schema, SchemaConnection::CONSOLE_NAME);
        } catch (Throwable $e) {
            $this->components->error('Could not prepare the console schema: '.$e->getMessage());

            return self::FAILURE;
        }

        // --force: the console is a developer tool but APP_ENV may be anything,
        // and there is no interactive operator to answer a production prompt.
        $exit = $this->call('migrate', array_filter([
            '--database' => SchemaConnection::CONSOLE_NAME,
            '--path' => 'database/migrations-admin',
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

        return $this->hardenSchema($schema);
    }

    /**
     * Deny the schema to PUBLIC, re-applied on every run.
     *
     * Every Postgres role is implicitly a member of PUBLIC, so a branch role
     * would otherwise inherit whatever PUBLIC holds. Branch roles must never
     * see the provisioning trail: it names every other business.
     *
     * This lives in the command rather than the migration because a migration
     * runs once, while a grant can be re-issued by anything with admin rights;
     * re-asserting it here makes the command the way to restore the boundary.
     */
    private function hardenSchema(string $schema): int
    {
        try {
            DB::connection('pgsql_admin')->statement("revoke all on schema \"{$schema}\" from public");

            $this->newLine();
            $this->components->twoColumnDetail('PUBLIC access', '<fg=green>revoked</>');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('Could not revoke PUBLIC access: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
