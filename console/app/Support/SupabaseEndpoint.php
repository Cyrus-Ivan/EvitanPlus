<?php

namespace Provisioning\Support;

/**
 * The connection facts a branch device needs, derived from the console's own
 * admin connection.
 *
 * Branches reach the same Supabase project the console administers, so the
 * endpoint is taken from CONSOLE_DB_* rather than configured twice — one fewer
 * pair of values to keep in step, and no way to hand a branch an endpoint the
 * console never verified. The admin USERNAME and PASSWORD are of course never
 * copied: only host, port, database and sslmode.
 *
 * ## The pooler username suffix
 *
 * Supabase's session pooler (Supavisor) multiplexes tenants, so it takes the
 * tenant out of the login name: you connect as `<role>.<project-ref>`, which is
 * why CONSOLE_DB_USERNAME is `postgres.<project-ref>` rather than `postgres`.
 * The Postgres role is still plain `postgres` — the suffix is addressing, not
 * identity.
 *
 * A branch role is therefore CREATED as `branch_<hex>` but must CONNECT as
 * `branch_<hex>.<project-ref>` whenever the endpoint is a pooler. Getting this
 * wrong produces a credentials JSON that authenticates nowhere, which is why
 * both forms travel in the JSON (`role.name` and `connection.username`) and why
 * provisioning connects as the role to prove it before reporting success.
 *
 * When the admin username carries no dot — a direct db.<ref>.supabase.co
 * connection, or a local Postgres — there is no tenant to encode and the two
 * forms are identical.
 */
final class SupabaseEndpoint
{
    /**
     * @param  string|null  $projectRef  Null when the endpoint is not a pooler.
     */
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $sslmode,
        public readonly ?string $projectRef,
    ) {}

    public static function fromConsoleConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('database.connections.pgsql_admin');

        return new self(
            host: (string) ($config['host'] ?? ''),
            port: (int) ($config['port'] ?? 5432),
            database: (string) ($config['database'] ?? 'postgres'),
            sslmode: (string) ($config['sslmode'] ?? 'require'),
            projectRef: self::projectRef((string) ($config['username'] ?? '')),
        );
    }

    /**
     * The name a branch role logs in with: suffixed when talking to a pooler,
     * bare otherwise.
     */
    public function loginName(string $role): string
    {
        $role = RoleName::validate($role);

        return $this->projectRef === null ? $role : $role.'.'.$this->projectRef;
    }

    /**
     * The tenant ref encoded in a pooler username, or null if there isn't one.
     *
     * Explicit override first: an admin connecting directly while branches go
     * through the pooler has no suffix to derive from.
     */
    private static function projectRef(string $adminUsername): ?string
    {
        $override = env('CONSOLE_SUPABASE_PROJECT_REF');

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $position = strpos($adminUsername, '.');

        if ($position === false) {
            return null;
        }

        $ref = substr($adminUsername, $position + 1);

        return $ref === '' ? null : $ref;
    }
}
