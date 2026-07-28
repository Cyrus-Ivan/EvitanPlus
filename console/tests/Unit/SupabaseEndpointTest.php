<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Provisioning\Support\SupabaseEndpoint;
use ReflectionClass;
use Tests\Concerns\FakesTheAdminConnection;
use Tests\CredentialFreeTestCase;

/**
 * The endpoint every branch device is issued, derived without a Supabase project.
 *
 * This is where the port split (M3.1) is actually pinned. The Feature suite can
 * only ever observe the ONE configuration the developer's console/.env happens to
 * be in; here every configuration is reachable in the same run — pooler and
 * direct, override and default, valid and refused — and the answers are the same
 * on every machine.
 *
 * That matters more than usual for this class, because two of its branches are
 * mutually exclusive in production. A project either has a Supavisor tenant or it
 * does not, and the inheritance behaviour that applies when it does not is
 * invisible to anyone whose project does.
 *
 * Assertions are literals, never expressions re-derived from the code under test.
 * The single exception is the constant guard below, which exists so that changing
 * TRANSACTION_POOLER_PORT cannot silently retune every other assertion in the file.
 */
class SupabaseEndpointTest extends CredentialFreeTestCase
{
    use FakesTheAdminConnection;

    /** A syntactically valid branch role; the UUID behind it does not matter here. */
    private const ROLE = 'branch_a259bfe482e845a28feeb2b63fadec16';

    private const REF = 'abcdefghijklmnopqrst';

    public function test_the_transaction_pooler_port_is_the_number_supabase_serves(): void
    {
        // The number is the contract, not an implementation detail: it is what
        // every shipped credentials JSON carries.
        $this->assertSame(6543, SupabaseEndpoint::TRANSACTION_POOLER_PORT);
    }

    public function test_a_pooler_admin_puts_branches_on_the_transaction_pooler(): void
    {
        $this->fakeAdminConnection();

        $endpoint = SupabaseEndpoint::forBranch();

        // Same host, different port: Supabase serves both modes on one Supavisor
        // host, so the host is inherited and only the transport changes.
        $this->assertSame('aws-1-ap-southeast-1.pooler.supabase.com', $endpoint->host);
        $this->assertSame(6543, $endpoint->port);

        // The load-bearing claim of the whole M3.1 change. Session mode pins one
        // Postgres backend per client for the connection's life, which does not
        // survive hundreds of branches.
        $this->assertNotSame(5432, $endpoint->port, 'A branch must never inherit the console admin session port.');

        // Identity is derived, so it cannot drift from the database the console
        // actually administers.
        $this->assertSame('postgres', $endpoint->database);
        $this->assertSame('require', $endpoint->sslmode);
        $this->assertSame(self::REF, $endpoint->projectRef);
    }

    public function test_a_direct_admin_makes_a_branch_inherit_the_port_it_actually_serves(): void
    {
        // No dot in the username means no Supavisor tenant, which means there is
        // no 6543 in the path at all. Defaulting to it anyway would hand every
        // branch an endpoint that refuses connections.
        $this->fakeAdminConnection(base: self::DIRECT_ADMIN);

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertNull($endpoint->projectRef);
        $this->assertSame(5432, $endpoint->port);
        $this->assertSame('db.abcdefghijklmnopqrst.supabase.co', $endpoint->host);
    }

    public function test_a_local_postgres_is_inherited_whole(): void
    {
        // Proves the inheritance is of the VALUE, not a second hard-coded 5432.
        $this->fakeAdminConnection(
            ['host' => '127.0.0.1', 'port' => '5433', 'sslmode' => 'disable'],
            self::DIRECT_ADMIN,
        );

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame('127.0.0.1', $endpoint->host);
        $this->assertSame(5433, $endpoint->port);
        $this->assertSame('disable', $endpoint->sslmode);
    }

    public function test_the_branch_host_can_be_overridden_without_the_port(): void
    {
        // The documented remedy for an admin that connects directly while
        // branches go through the pooler: point the host at Supavisor and let
        // the tenant ref be set explicitly.
        $this->fakeAdminConnection(base: self::DIRECT_ADMIN);
        $this->fakeEnv('CONSOLE_SUPABASE_PROJECT_REF', self::REF);
        $this->fakeEnv('CONSOLE_BRANCH_DB_HOST', 'aws-1-ap-southeast-1.pooler.supabase.com');

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame('aws-1-ap-southeast-1.pooler.supabase.com', $endpoint->host);
        $this->assertSame(6543, $endpoint->port);
    }

    public function test_the_branch_port_can_be_overridden(): void
    {
        // The configuration console:ping warns about: legal, and it works, but
        // it puts branches back on the endpoint that does not scale.
        $this->fakeAdminConnection();
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', '5432');

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame(5432, $endpoint->port);
        $this->assertSame(self::REF, $endpoint->projectRef);
    }

    /**
     * An explicit ref plus a direct host is the one combination the defaults
     * cannot save, and it is reachable without anyone choosing it.
     *
     * The ref makes projectRef non-null, so the port defaults to 6543 — but the
     * host is inherited from an admin that connects directly, and
     * db.<ref>.supabase.co does not serve 6543. This test exists to document the
     * trap rather than to bless it: the fix is CONSOLE_BRANCH_DB_HOST, and
     * console:ping's branch probe is what catches it before a provision does.
     */
    public function test_an_explicit_ref_on_a_direct_host_produces_an_endpoint_that_cannot_answer(): void
    {
        $this->fakeAdminConnection(base: self::DIRECT_ADMIN);
        $this->fakeEnv('CONSOLE_SUPABASE_PROJECT_REF', self::REF);

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame(self::REF, $endpoint->projectRef);
        $this->assertSame(6543, $endpoint->port);
        $this->assertSame('db.abcdefghijklmnopqrst.supabase.co', $endpoint->host);
    }

    #[DataProvider('blankOverrides')]
    public function test_a_blank_override_reads_as_absent(string $blank): void
    {
        // Blank must mean "not set", not "set to empty". An empty host would
        // otherwise be written into a credentials JSON and reach the operator as
        // an opaque connect failure inside verify().
        $this->fakeAdminConnection();
        $this->fakeEnv('CONSOLE_BRANCH_DB_HOST', $blank);
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', $blank);

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame('aws-1-ap-southeast-1.pooler.supabase.com', $endpoint->host);
        $this->assertSame(6543, $endpoint->port);
    }

    /** @return iterable<string, array{string}> */
    public static function blankOverrides(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tab' => ["\t"];
        yield 'newline' => ["\n"];
    }

    public function test_an_override_is_trimmed(): void
    {
        $this->fakeAdminConnection();
        $this->fakeEnv('CONSOLE_BRANCH_DB_HOST', '  pooler.example.com  ');
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', ' 6543 ');

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame('pooler.example.com', $endpoint->host);
        $this->assertSame(6543, $endpoint->port);
    }

    #[DataProvider('unusablePorts')]
    public function test_an_unusable_branch_port_is_refused_by_name(string $port): void
    {
        $this->fakeAdminConnection();
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', $port);

        $this->expectException(InvalidArgumentException::class);
        // The KEY must be in the message. A port typo the operator cannot locate
        // is the failure this refusal exists to prevent; a bare "invalid port"
        // would trade one silent misconfiguration for one loud mystery.
        $this->expectExceptionMessage('CONSOLE_BRANCH_DB_PORT must be a TCP port between 1 and 65535');

        SupabaseEndpoint::forBranch();
    }

    /** @return iterable<string, array{string}> */
    public static function unusablePorts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'above the range' => ['65536'];
        yield 'far above the range' => ['99999'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['abc'];
        yield 'decimal' => ['5432.0'];
        yield 'hex' => ['0x1F'];
        yield 'sql' => ['5432; drop role x'];
    }

    #[DataProvider('boundaryPorts')]
    public function test_the_edges_of_the_port_range_are_accepted(string $port, int $expected): void
    {
        $this->fakeAdminConnection();
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', $port);

        $this->assertSame($expected, SupabaseEndpoint::forBranch()->port);
    }

    /** @return iterable<string, array{string, int}> */
    public static function boundaryPorts(): iterable
    {
        yield 'lowest' => ['1', 1];
        yield 'highest' => ['65535', 65535];
    }

    public function test_a_branch_logs_in_with_the_tenant_suffix_through_a_pooler(): void
    {
        $this->fakeAdminConnection();

        // The FULL string. BusinessProvisionTest only ever checked the prefix,
        // which passes with or without the suffix — and a missing suffix is the
        // one thing that can be wrong here. It authenticates nowhere.
        $this->assertSame(
            self::ROLE.'.'.self::REF,
            SupabaseEndpoint::forBranch()->loginName(self::ROLE),
        );
    }

    public function test_a_direct_admin_gives_a_branch_a_bare_login_name(): void
    {
        $this->fakeAdminConnection(base: self::DIRECT_ADMIN);

        $name = SupabaseEndpoint::forBranch()->loginName(self::ROLE);

        $this->assertSame(self::ROLE, $name);
        $this->assertStringNotContainsString('.', $name);
    }

    public function test_a_login_name_is_still_an_identifier_boundary(): void
    {
        // loginName() returns a string that ends up in a DSN. It delegates to
        // RoleName::validate for that reason, and nothing else asserted that it
        // still does.
        $this->fakeAdminConnection();

        $this->expectException(InvalidArgumentException::class);

        SupabaseEndpoint::forBranch()->loginName('branch_a"; drop role x; --');
    }

    public function test_an_explicit_project_ref_wins_over_the_admin_username(): void
    {
        $this->fakeAdminConnection();
        $this->fakeEnv('CONSOLE_SUPABASE_PROJECT_REF', 'overridden');

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame('overridden', $endpoint->projectRef);
        $this->assertSame(self::ROLE.'.overridden', $endpoint->loginName(self::ROLE));
    }

    #[DataProvider('adminUsernames')]
    public function test_the_tenant_is_read_from_the_first_dot(string $username, ?string $expected): void
    {
        $this->fakeAdminConnection(['username' => $username]);

        $this->assertSame($expected, SupabaseEndpoint::forBranch()->projectRef);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function adminUsernames(): iterable
    {
        yield 'pooler' => ['postgres.abcdefg', 'abcdefg'];
        yield 'bare' => ['postgres', null];
        yield 'trailing dot is not a tenant' => ['postgres.', null];
        yield 'first dot wins' => ['postgres.a.b', 'a.b'];
        yield 'leading dot' => ['.abc', 'abc'];
    }

    public function test_identity_is_derived_from_the_admin_connection(): void
    {
        $this->fakeAdminConnection(['database' => 'evitan', 'sslmode' => 'verify-full']);

        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame('evitan', $endpoint->database);
        $this->assertSame('verify-full', $endpoint->sslmode);
    }

    public function test_the_endpoint_cannot_carry_the_admin_credentials(): void
    {
        // §18's "the admin username and password are never copied" was enforced
        // only by a docblock. A property cannot leak what it does not have.
        $properties = array_map(
            static fn ($p) => $p->getName(),
            (new ReflectionClass(SupabaseEndpoint::class))->getProperties(),
        );

        $this->assertSame(['host', 'port', 'database', 'sslmode', 'projectRef'], $properties);
    }

    public function test_a_console_with_no_admin_connection_is_refused(): void
    {
        // Every read in forBranch() is `?? default`-guarded, so without this the
        // result would be a plausible-looking endpoint on host '' — shipped
        // inside a credentials JSON and discovered much later.
        config(['database.connections.pgsql_admin' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No [pgsql_admin] connection is configured');

        SupabaseEndpoint::forBranch();
    }
}
