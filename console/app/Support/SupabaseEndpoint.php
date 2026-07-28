<?php

namespace Provisioning\Support;

use InvalidArgumentException;

/**
 * The connection facts a branch device needs.
 *
 * Branches reach the same Supabase project the console administers, so identity
 * — database, sslmode, tenant ref — is DERIVED from CONSOLE_DB_* rather than
 * configured twice: one fewer set of values to keep in step, and no way to hand
 * a branch a database the console never administered. The admin USERNAME and
 * PASSWORD are of course never copied.
 *
 * ## Why the transport is separate
 *
 * The one thing branches must NOT inherit is the admin's port. Supabase serves
 * two pooling modes on the same Supavisor host:
 *
 *   5432  session mode      — one Postgres backend pinned per client, for the
 *                             client's whole life. The console needs it: DDL,
 *                             CREATE/ALTER ROLE and its own search_path pin all
 *                             depend on session state surviving between
 *                             statements. One operator, so the cost is nothing.
 *   6543  transaction mode  — backends are multiplexed across clients, held only
 *                             for the length of a transaction. Branches need it:
 *                             session mode's pool is 15 clients on this project,
 *                             and Evitan Plus is heading for hundreds of
 *                             branches, each holding a connection.
 *
 * Transaction mode is only safe here because a branch role's schema is pinned
 * SERVER-side by `ALTER ROLE ... SET search_path` (BranchRole::applyGrants), a
 * role default Postgres applies at backend startup — so every pooled backend
 * carries it. A connect-time `SET` would not survive, which is why the
 * credentials JSON emits `connection.search_path: null` (§9).
 *
 * CONSOLE_BRANCH_DB_HOST / CONSOLE_BRANCH_DB_PORT override the transport. Both
 * default sensibly, so the common case configures nothing: same host as the
 * admin, port 6543. And because BranchConnection uses this same endpoint,
 * provisioning verifies each credential over the exact endpoint it hands out
 * rather than over one it merely resembles.
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
     * Supabase's transaction pooler. See the class docblock for why branches
     * get this and the console admin keeps 5432.
     */
    public const TRANSACTION_POOLER_PORT = 6543;

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

    /**
     * The endpoint branch devices connect through — and the one the console
     * verifies each issued credential over.
     */
    public static function forBranch(): self
    {
        $admin = self::adminConfig();
        $projectRef = self::projectRef((string) ($admin['username'] ?? ''));

        return new self(
            host: self::envValue('CONSOLE_BRANCH_DB_HOST') ?? (string) ($admin['host'] ?? ''),
            port: self::port('CONSOLE_BRANCH_DB_PORT')
                // No project ref means no Supavisor in the path — a direct
                // db.<ref>.supabase.co connection or a local Postgres, neither
                // of which serves 6543. Inherit rather than invent.
                ?? ($projectRef === null ? (int) ($admin['port'] ?? 5432) : self::TRANSACTION_POOLER_PORT),
            database: (string) ($admin['database'] ?? 'postgres'),
            sslmode: (string) ($admin['sslmode'] ?? 'require'),
            projectRef: $projectRef,
        );
    }

    /**
     * The admin connection this endpoint derives its identity from.
     *
     * Refuses rather than falling back. Every read below is `?? default`-guarded,
     * so without this a console with no `pgsql_admin` would quietly produce an
     * endpoint on host `''` — and ship it inside a credentials JSON, where the
     * operator would meet it much later as an opaque connect failure. Same rule
     * the port parser follows, and the same rule RoleName and SchemaName follow.
     *
     * @return array<string, mixed>
     */
    private static function adminConfig(): array
    {
        $config = config('database.connections.pgsql_admin');

        if (! is_array($config) || $config === []) {
            throw new InvalidArgumentException(
                'No [pgsql_admin] connection is configured; a branch endpoint is derived from it.'
            );
        }

        return $config;
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
     * A non-blank env string, or null.
     *
     * Blank must read as absent, not as a value: an empty CONSOLE_BRANCH_DB_HOST
     * would otherwise ship a credentials JSON pointing at "", and the operator
     * would meet it as an opaque connect failure inside verify().
     */
    private static function envValue(string $key): ?string
    {
        $value = env($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A TCP port from the environment, or null when the key is unset.
     *
     * Throws rather than falling back on a value it cannot parse. A typo'd port
     * that silently defaulted would produce a credential pointing somewhere the
     * operator never chose — the same class of mistake RoleName and SchemaName
     * refuse outright.
     */
    private static function port(string $key): ?int
    {
        $value = self::envValue($key);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new InvalidArgumentException(
                "{$key} must be a TCP port between 1 and 65535, got [{$value}]."
            );
        }

        return (int) $value;
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
