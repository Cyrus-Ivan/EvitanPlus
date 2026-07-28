<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesTheAdminConnection;
use Tests\CredentialFreeTestCase;

/**
 * console:ping's diagnostics, without a Supabase project.
 *
 * The command exists to tell an operator what is wrong before they spend a
 * provision finding out, so what is under test is mostly what it SAYS. All of it
 * is reachable offline: since M3.1 the branch endpoint is reported even when the
 * admin connection fails, and the branch endpoint is derived from the admin
 * config array rather than from a live connection.
 *
 * ## Why the fake admin points at 127.0.0.1 port 1
 *
 * A refused connection must be instant and deterministic. Loopback to a port
 * nothing listens on returns RST immediately — measured at ~79ms — and
 * "Connection refused" is not in Laravel's DetectsLostConnections list, so the
 * connector does not even retry once.
 *
 * Do NOT "improve" this to an unroutable address like 10.255.255.1: that costs a
 * full TCP SYN timeout, roughly two minutes per attempt on Linux, and there are
 * several attempts in this file. A bogus hostname is no better — it costs
 * whatever the machine's resolver decides, which is not a fixed number.
 */
class PingCommandTest extends CredentialFreeTestCase
{
    use FakesTheAdminConnection;

    /** A port nothing listens on, on an interface that answers instantly. */
    private const DEAD = ['host' => '127.0.0.1', 'port' => '1'];

    public function test_it_refuses_a_default_connection_that_is_not_configured(): void
    {
        config(['database.default' => 'nope']);

        $exit = Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('No database connection named [nope]', $output);
    }

    public function test_it_reports_the_admin_endpoint_before_it_tries_to_connect(): void
    {
        $this->fakeAdminConnection(self::DEAD);

        Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertStringContainsString('Connecting as [pgsql_admin]', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
        $this->assertStringContainsString('postgres.abcdefghijklmnopqrst', $output);
        $this->assertStringContainsString('require', $output);
    }

    public function test_it_never_echoes_the_admin_password(): void
    {
        // The command says "never echoed, not even masked" and nothing held it
        // to that. The failure path prints the most, so assert on it.
        $this->fakeAdminConnection(self::DEAD);

        Artisan::call('console:ping');

        $this->assertStringNotContainsString('fixture-password-never-copied', Artisan::output());
    }

    public function test_a_failed_admin_connection_names_the_pooler_mistakes(): void
    {
        $this->fakeAdminConnection(self::DEAD);

        $exit = Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('Connection failed:', $output);
        $this->assertStringContainsString('SESSION endpoint on port 5432', $output);
        $this->assertStringContainsString('IPv6-only', $output);
        $this->assertStringContainsString('postgres.<project-ref>', $output);
    }

    /**
     * A broken admin connection must not hide a broken branch endpoint.
     *
     * Before M3.1 the command returned at the admin catch, so an operator
     * setting up a fresh project — who usually has both values wrong at once —
     * got one diagnosis per run.
     */
    public function test_a_failed_admin_connection_still_reports_the_branch_endpoint(): void
    {
        $this->fakeAdminConnection(self::DEAD);

        Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertStringContainsString('Connection failed:', $output);
        $this->assertStringContainsString('Endpoint branch devices are issued', $output);
        $this->assertStringContainsString('6543', $output);
    }

    /**
     * The command exists to diagnose a broken configuration, so it must not be
     * one of the things a broken configuration takes down.
     *
     * SupabaseEndpoint::forBranch() throws by design on a port it will not
     * guess at, and this call site used to be unguarded — including inside the
     * failed-admin catch, the path whose whole purpose is to produce one
     * complete diagnosis per run. A stray space in the env value turned "run
     * this first whenever anything looks wrong" into a PHP stack trace.
     */
    public function test_an_unparseable_branch_port_is_reported_not_thrown(): void
    {
        $this->fakeAdminConnection(self::DEAD);
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', '654 3');

        $exit = Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('The branch endpoint cannot be derived', $output);
        $this->assertStringContainsString('CONSOLE_BRANCH_DB_PORT', $output);
        $this->assertStringNotContainsString('Stack trace', $output);
    }

    /** Same guard on the healthy-admin path, which reaches it from elsewhere. */
    public function test_an_unparseable_branch_port_does_not_escape_the_command(): void
    {
        $this->fakeAdminConnection(self::DEAD);
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', 'abc');

        // The assertion is that this returns at all rather than throwing.
        $this->assertNotSame(0, Artisan::call('console:ping'));
    }

    public function test_it_reports_the_login_shape_a_branch_will_carry(): void
    {
        $this->fakeAdminConnection(self::DEAD);

        Artisan::call('console:ping');

        $this->assertStringContainsString('branch_<32 hex>.abcdefghijklmnopqrst', Artisan::output());
    }

    public function test_a_direct_admin_reports_a_login_shape_with_no_suffix(): void
    {
        $this->fakeAdminConnection(self::DEAD, self::DIRECT_ADMIN);

        Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertStringContainsString('branch_<32 hex> (no pooler suffix)', $output);
        $this->assertStringNotContainsString('branch_<32 hex>.', $output);
    }

    public function test_it_warns_when_branches_would_be_issued_the_admin_session_endpoint(): void
    {
        $this->fakeAdminConnection(self::DEAD);
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', '1');

        Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertStringContainsString('Branches would be issued the admin SESSION endpoint', $output);
        $this->assertStringContainsString('CONSOLE_BRANCH_DB_PORT=6543', $output);
    }

    public function test_the_warning_is_silent_without_a_pooler_tenant(): void
    {
        // Ports equal, but no Supavisor in the path — so inheriting the admin
        // port is correct and warning about it would be noise. This is the `&&`
        // in the guard, and it is the half that is easy to drop.
        $this->fakeAdminConnection(self::DEAD, self::DIRECT_ADMIN);

        Artisan::call('console:ping');

        // Deliberately the warning's own sentence, not the phrase "SESSION
        // endpoint": that also appears in the admin-failure hint above it, and
        // asserting on the loose form passed for the wrong reason.
        $this->assertStringNotContainsString('Branches would be issued', Artisan::output());
    }

    public function test_an_unreachable_branch_endpoint_names_the_three_fixes_and_fails_the_run(): void
    {
        $this->fakeAdminConnection(self::DEAD);

        $exit = Artisan::call('console:ping', ['--no-ddl-probe' => true]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('Branch endpoint unreachable:', $output);
        $this->assertStringContainsString('provisioning will fail at verification', $output);
        $this->assertStringContainsString('CONSOLE_BRANCH_DB_HOST / CONSOLE_BRANCH_DB_PORT', $output);
        $this->assertStringContainsString('does not serve 6543', $output);
    }

    public function test_the_branch_probe_leaves_no_connection_and_no_password_behind(): void
    {
        $this->fakeAdminConnection(self::DEAD);

        Artisan::call('console:ping', ['--no-ddl-probe' => true]);

        // The finally block is the only thing stopping the admin password from
        // sitting in the config repository for the rest of the process.
        $this->assertNull(config('database.connections.pgsql_branch_probe'));
        $this->assertArrayNotHasKey('pgsql_branch_probe', DB::getConnections());
    }

    public function test_the_branch_probe_never_inherits_a_console_db_url(): void
    {
        // ConfigurationUrlParser merges a url OVER host and port, so without the
        // unset the probe would connect to the admin endpoint and report success
        // about the wrong server. Sibling of the latent bug 77b16a6 fixed in
        // BranchConnection, which is asserted separately.
        $this->fakeAdminConnection([
            ...self::DEAD,
            'url' => 'pgsql://admin:pw@aws-9-elsewhere.pooler.supabase.com:5432/postgres',
        ]);
        $this->fakeEnv('CONSOLE_BRANCH_DB_HOST', '127.0.0.1');
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', '1');

        Artisan::call('console:ping', ['--no-ddl-probe' => true]);

        $failure = strstr(Artisan::output(), 'Branch endpoint unreachable:');

        $this->assertIsString($failure, 'The branch probe was expected to fail against 127.0.0.1:1.');
        $this->assertStringContainsString('127.0.0.1', $failure);
        $this->assertStringNotContainsString('aws-9-elsewhere', $failure);
    }

    public function test_no_branch_probe_reports_the_endpoint_without_opening_one(): void
    {
        $this->fakeAdminConnection(self::DEAD);

        Artisan::call('console:ping', ['--no-ddl-probe' => true, '--no-branch-probe' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Endpoint branch devices are issued', $output);
        $this->assertStringNotContainsString('Branch endpoint unreachable', $output);
        $this->assertStringNotContainsString('Branch endpoint answers', $output);
        $this->assertArrayNotHasKey('pgsql_branch_probe', DB::getConnections());
    }
}
