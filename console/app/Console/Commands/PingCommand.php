<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase M0 milestone command.
 *
 * Proves the console can reach Supabase with admin credentials, and that the
 * role it authenticates as is actually capable of the DDL phases M1/M2 depend
 * on. Deliberately contains NO provisioning logic and leaves NO persistent
 * objects behind: the DDL probe runs inside a transaction that is always
 * rolled back (Postgres makes CREATE SCHEMA and CREATE ROLE transactional).
 */
class PingCommand extends Command
{
    protected $signature = 'console:ping
        {--no-ddl-probe : Skip the rolled-back CREATE SCHEMA / CREATE ROLE probe}';

    protected $description = 'Verify the admin Supabase connection: server version, identity and DDL capability';

    public function handle(): int
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->components->error('The pdo_pgsql extension is not loaded — install php-pgsql.');

            return self::FAILURE;
        }

        $name = (string) config('database.default');

        /** @var array<string, mixed>|null $config */
        $config = config("database.connections.{$name}");

        if ($config === null) {
            $this->components->error("No database connection named [{$name}] is configured.");

            return self::FAILURE;
        }

        $this->components->info("Connecting as [{$name}]");
        $this->components->twoColumnDetail('Host', (string) ($config['host'] ?? '—'));
        $this->components->twoColumnDetail('Port', (string) ($config['port'] ?? '—'));
        $this->components->twoColumnDetail('Database', (string) ($config['database'] ?? '—'));
        $this->components->twoColumnDetail('Username', (string) ($config['username'] ?? '—'));
        $this->components->twoColumnDetail('SSL mode', (string) ($config['sslmode'] ?? '—'));
        // The password is never echoed, not even masked.

        try {
            $connection = DB::connection($name);

            $facts = $connection->selectOne(<<<'SQL'
                select version()                      as server_version,
                       current_database()             as database_name,
                       current_user                   as effective_role,
                       session_user                   as login_role,
                       current_schema()               as active_schema,
                       current_setting('search_path') as search_path,
                       has_database_privilege(current_user, current_database(), 'CREATE') as may_create_schema
            SQL);

            $role = $connection->selectOne(
                'select rolsuper, rolcreaterole, rolcreatedb from pg_roles where rolname = current_user'
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('Connection failed: '.$e->getMessage());
            $this->components->bulletList([
                'Check the CONSOLE_DB_* values in console/.env.',
                'This connection needs the SESSION endpoint on port 5432. Port 6543 is the transaction pooler — it is what branch devices use, and it will not serve DDL or role management.',
                'The direct host db.<project-ref>.supabase.co is IPv6-only without the IPv4 add-on; use the session pooler host instead.',
                'Supabase pooler usernames are "postgres.<project-ref>", not plain "postgres".',
            ]);

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Connected');
        $this->components->twoColumnDetail('Server version', (string) $facts->server_version);
        $this->components->twoColumnDetail('Database', (string) $facts->database_name);
        $this->components->twoColumnDetail('Login role', (string) $facts->login_role);
        $this->components->twoColumnDetail('Effective role', (string) $facts->effective_role);
        $this->components->twoColumnDetail('Active schema', (string) $facts->active_schema);
        $this->components->twoColumnDetail('search_path', (string) $facts->search_path);

        $this->newLine();
        $this->components->info('Capabilities required by phases M1/M2');
        $this->components->twoColumnDetail('Superuser', $this->yesNo($role?->rolsuper));
        $this->components->twoColumnDetail('CREATEROLE (branch roles)', $this->yesNo($role?->rolcreaterole));
        $this->components->twoColumnDetail('CREATEDB', $this->yesNo($role?->rolcreatedb));
        $this->components->twoColumnDetail('CREATE on database (schemas)', $this->yesNo($facts->may_create_schema));

        if ($this->option('no-ddl-probe')) {
            return self::SUCCESS;
        }

        return $this->probeDdl($name);
    }

    /**
     * Execute the DDL that provisioning will need, then roll it back.
     *
     * Privilege bits lie — inherited roles, event triggers and Supabase's own
     * policy all distort them. A rolled-back transaction does not.
     */
    private function probeDdl(string $name): int
    {
        $probe = 'evitan_console_probe_'.bin2hex(random_bytes(4));
        $connection = DB::connection($name);

        $connection->beginTransaction();

        try {
            $connection->statement("create schema \"{$probe}\"");
            $connection->statement("create role \"{$probe}\" nologin");
            $connection->statement("grant usage on schema \"{$probe}\" to \"{$probe}\"");

            $this->newLine();
            $this->components->info('DDL probe passed (CREATE SCHEMA + CREATE ROLE + GRANT, rolled back)');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('DDL probe failed: '.$e->getMessage());
            $this->components->warn('This role cannot provision. Use the Supabase project owner / service role.');

            return self::FAILURE;
        } finally {
            $connection->rollBack();
        }
    }

    private function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
    }
}
