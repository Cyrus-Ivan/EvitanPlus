<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\OverridesEnvironment;
use Tests\TestCase;

/**
 * console:ping against a real Supabase project.
 *
 * Deliberately thin. Everything about what the command SAYS is settled offline in
 * Tests\Unit\PingCommandTest, which needs no credentials; what is left here is
 * only what a live server can answer — that a healthy project passes both probes,
 * and that the exit code combines them rather than reporting whichever ran last.
 *
 * Session budget: this opens two admin-credentialed connections, `pgsql_admin`
 * and the command's own `pgsql_branch_probe`. The base class already purges the
 * first and the command purges the second in a finally, so nothing needs adding
 * to TestCase's purge list — noted here because it looks like an omission.
 */
class PingCommandTest extends TestCase
{
    use OverridesEnvironment;

    public function test_a_healthy_project_reports_both_endpoints_and_succeeds(): void
    {
        $exit = Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Connected', $output);
        $this->assertStringContainsString('DDL probe passed', $output);
        $this->assertStringContainsString('Endpoint branch devices are issued', $output);
        $this->assertStringContainsString('Branch endpoint answers', $output);
    }

    public function test_the_ddl_probe_no_longer_short_circuits_the_branch_report(): void
    {
        // The honest proxy. Proving the FAILING-DDL case would need a role that
        // cannot create schemas, which this suite has no way to obtain — the
        // admin credential is by definition one that can.
        $exit = Artisan::call('console:ping', ['--no-ddl-probe' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('DDL probe passed', $output);
        $this->assertStringContainsString('Endpoint branch devices are issued', $output);
    }

    /**
     * A passing DDL probe must not rescue a failing branch probe.
     *
     * The one direction of `$ddl === SUCCESS && $branch === SUCCESS` that only a
     * live server can prove: everything before the branch probe genuinely
     * succeeds, so a regression to `return $ddl;` would exit 0 while telling the
     * operator their branches cannot connect.
     */
    public function test_a_passing_ddl_probe_does_not_rescue_a_failing_branch_probe(): void
    {
        $this->fakeEnv('CONSOLE_BRANCH_DB_HOST', '127.0.0.1');
        $this->fakeEnv('CONSOLE_BRANCH_DB_PORT', '1');

        $exit = Artisan::call('console:ping');
        $output = Artisan::output();

        $this->assertStringContainsString('Connected', $output);
        $this->assertStringContainsString('DDL probe passed', $output);
        $this->assertStringContainsString('Branch endpoint unreachable:', $output);
        $this->assertNotSame(0, $exit, $output);
    }

    public function test_a_successful_run_leaves_no_probe_connection_behind(): void
    {
        Artisan::call('console:ping', ['--no-ddl-probe' => true]);

        $this->assertNull(config('database.connections.pgsql_branch_probe'));
        $this->assertArrayNotHasKey('pgsql_branch_probe', DB::getConnections());
    }

    public function test_it_never_prints_the_admin_password(): void
    {
        $password = (string) config('database.connections.pgsql_admin.password');

        $this->assertNotSame('', $password, 'The suite is misconfigured; this test would be vacuous.');

        Artisan::call('console:ping', ['--no-ddl-probe' => true]);

        $this->assertStringNotContainsString($password, Artisan::output());
    }
}
