<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Provisioning\Support\SupabaseEndpoint;
use Throwable;

/**
 * Phase M0 milestone command.
 *
 * Proves the console can reach Supabase with admin credentials, and that the
 * role it authenticates as is actually capable of the DDL phases M1/M2 depend
 * on. Deliberately contains NO provisioning logic and leaves NO persistent
 * objects behind: the DDL probe runs inside a transaction that is always
 * rolled back (Postgres makes CREATE SCHEMA and CREATE ROLE transactional).
 *
 * ## Why it speaks for two endpoints
 *
 * Since M3.1 the console depends on two: it administers over the session pooler
 * and branches connect over the transaction pooler (see SupabaseEndpoint). The
 * second is not a detail of this command's own connection — it is what gets
 * stamped into every credentials JSON and shipped to a device.
 *
 * Reporting only the admin endpoint made "Connected" mean less than it looked
 * like it meant. A wrong branch endpoint is first exercised by verify(), the
 * second-to-last step of a provision, so it surfaced only after a schema,
 * nineteen migrations, a seed, a role and the whole grant matrix had been
 * created — and then rolled back. That check still exists and still gates the
 * JSON; this command just makes the same answer available before the work.
 *
 * ## Nothing here short-circuits
 *
 * A failed admin connection, a failed DDL probe and an unreachable branch
 * endpoint are three independent misconfigurations, and a fresh project
 * routinely has more than one. Each is reported in full and each contributes to
 * the exit code, so one run gives one complete diagnosis rather than uncovering
 * the next problem only after the previous one is fixed.
 */
class PingCommand extends Command
{
    protected $signature = 'console:ping
        {--no-ddl-probe : Skip the rolled-back CREATE SCHEMA / CREATE ROLE probe}
        {--no-branch-probe : Skip connecting to the branch endpoint (it still gets reported)}';

    protected $description = 'Verify both Supabase endpoints: the admin connection and DDL capability, and the endpoint branches are issued';

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

            // Report the branch endpoint anyway, for the same reason the DDL
            // probe no longer short-circuits: an operator setting up a fresh
            // project usually has both values wrong at once, and one diagnosis
            // per run is what this command exists to stop. Nothing below needs
            // the admin CONNECTION — only the admin config array — so there is
            // no reason for a failed connect to hide it.
            $this->reportBranchEndpoint();

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

        $ddl = $this->option('no-ddl-probe') ? self::SUCCESS : $this->probeDdl($name);

        // Runs even when the DDL probe failed, on purpose: two independent
        // misconfigurations should both be visible in one run rather than one
        // per run.
        $branch = $this->reportBranchEndpoint();

        return $ddl === self::SUCCESS && $branch === self::SUCCESS
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Report — and unless waived, reach — the endpoint branch devices are issued.
     *
     * Reporting is unconditional because it is free and it is the part an
     * operator cannot otherwise see: nothing else in the console prints this
     * endpoint until a provision has already done its work.
     */
    private function reportBranchEndpoint(): int
    {
        // Guarded, because forBranch() throws by design on exactly the two
        // misconfigurations this command exists to name: no pgsql_admin at all,
        // and a CONSOLE_BRANCH_DB_PORT it refuses to guess at. Unguarded, a
        // stray space in that env value turned "run this first whenever
        // anything looks wrong" into a PHP stack trace — and did it on the
        // failed-connection path too, the one whose whole purpose is to produce
        // one complete diagnosis per run.
        try {
            $endpoint = SupabaseEndpoint::forBranch();
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('The branch endpoint cannot be derived: '.$e->getMessage());
            $this->components->bulletList([
                'CONSOLE_BRANCH_DB_HOST / CONSOLE_BRANCH_DB_PORT override it; both default sensibly, so the common case sets neither.',
                'Every credentials JSON carries this endpoint, so nothing can be provisioned until it resolves.',
            ]);

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Endpoint branch devices are issued');
        $this->components->twoColumnDetail('Host', $endpoint->host);
        $this->components->twoColumnDetail('Port', (string) $endpoint->port);
        $this->components->twoColumnDetail('Database', $endpoint->database);
        $this->components->twoColumnDetail('SSL mode', $endpoint->sslmode);
        $this->components->twoColumnDetail(
            'Branch login name',
            $endpoint->projectRef === null
                ? 'branch_<32 hex> (no pooler suffix)'
                : 'branch_<32 hex>.'.$endpoint->projectRef,
        );

        // A pooler tenant exists but branches would be handed the admin's own
        // session endpoint. Not fatal — it will work — but it is the shape that
        // does not survive more than a handful of branches, and it is silent.
        if ($endpoint->projectRef !== null
            && $endpoint->port === (int) config('database.connections.pgsql_admin.port')) {
            $this->components->warn(
                'Branches would be issued the admin SESSION endpoint. Session mode pins one '
                .'Postgres backend per client for the connection\'s whole life; set '
                .'CONSOLE_BRANCH_DB_PORT='.SupabaseEndpoint::TRANSACTION_POOLER_PORT.' (the default) instead.'
            );
        }

        if ($this->option('no-branch-probe')) {
            return self::SUCCESS;
        }

        return $this->probeBranchEndpoint($endpoint);
    }

    /**
     * Confirm the branch endpoint answers, using the ADMIN credentials.
     *
     * That is deliberate, and it is not the thing console/config/database.php
     * warns against. The prohibition there protects session state — DDL, role
     * management, search_path pinning — and this runs one `select 1` and needs
     * none of it. What it proves is the transport: that the host answers on that
     * port and completes a TLS handshake. That is exactly the failure the
     * defaults can produce on their own, when the admin connects directly and
     * the branch host inherits a `db.<ref>.supabase.co` that does not serve 6543.
     *
     * It cannot prove a branch ROLE works: this command holds no branch
     * credential and must never hold one (§9). Provisioning's verify() remains
     * the check that proves that, and remains the one that gates a JSON.
     */
    private function probeBranchEndpoint(SupabaseEndpoint $endpoint): int
    {
        /** @var array<string, mixed> $config */
        $config = config('database.connections.pgsql_admin');

        // Same hazards BranchConnection::open() handles: a CONSOLE_DB_URL would
        // be merged over host and port and this would silently probe the admin
        // endpoint instead, reporting success about the wrong thing.
        unset($config['url'], $config['search_path']);

        $config['host'] = $endpoint->host;
        $config['port'] = $endpoint->port;
        $config['database'] = $endpoint->database;
        $config['sslmode'] = $endpoint->sslmode;

        $name = 'pgsql_branch_probe';
        config(["database.connections.{$name}" => $config]);

        try {
            DB::purge($name);
            DB::connection($name)->select('select 1');

            $this->components->info('Branch endpoint answers (TLS handshake completed, credentials accepted on this port)');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('Branch endpoint unreachable: '.$e->getMessage());
            $this->components->bulletList([
                'Every credentials JSON carries this endpoint, so provisioning will fail at verification until it answers.',
                'CONSOLE_BRANCH_DB_HOST / CONSOLE_BRANCH_DB_PORT in console/.env override it; blank means the admin host on port '.SupabaseEndpoint::TRANSACTION_POOLER_PORT.'.',
                'A direct db.<project-ref>.supabase.co host does not serve '.SupabaseEndpoint::TRANSACTION_POOLER_PORT.' — set CONSOLE_BRANCH_DB_HOST to the pooler host.',
            ]);

            return self::FAILURE;
        } finally {
            // Never leave the probe's connection (or its admin password) behind.
            DB::purge($name);
            config(["database.connections.{$name}" => null]);
        }
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
