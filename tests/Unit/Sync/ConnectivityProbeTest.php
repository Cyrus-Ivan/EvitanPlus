<?php

namespace Tests\Unit\Sync;

use App\Support\Sync\ConnectivityProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The probe (§11.3).
 *
 * The rule it exists to obey is negative and cannot be asserted by looking at a
 * result: it must never open Postgres. An offline till probing on a timer would
 * hold a Supavisor client slot forever — the exact ceiling that connecting
 * per cycle removes — so the check is HTTP and the endpoint derivation is
 * pinned here, because a probe that could not work out where to look would
 * either always answer "offline" (a branch that can never sync) or always
 * answer "online" (a Postgres connection attempt every interval).
 */
class ConnectivityProbeTest extends TestCase
{
    /** The probe itself is what is under test, so it must be the real one. */
    protected ?bool $connected = null;

    /** @param array<string, mixed> $overrides */
    private function connection(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'pgsql',
            'host' => 'aws-0-ap-southeast-1.pooler.supabase.com',
            'port' => 6543,
            'database' => 'postgres',
            'username' => 'branch_9f2c.abcdefghijklmnop',
            'password' => 'secret',
        ], $overrides);
    }

    public function test_the_endpoint_comes_from_the_pooler_username(): void
    {
        // Supavisor requires <role>.<project-ref> as the login name, which is
        // the only place a branch device learns its project ref.
        $this->assertSame(
            'https://abcdefghijklmnop.supabase.co/rest/v1/',
            app(ConnectivityProbe::class)->endpoint($this->connection()),
        );
    }

    public function test_a_non_pooler_username_yields_no_endpoint(): void
    {
        $this->assertNull(app(ConnectivityProbe::class)->endpoint($this->connection(['username' => 'postgres'])));
    }

    public function test_any_http_answer_counts_as_reachable(): void
    {
        // /rest/v1/ without an apikey answers 401. Something spoke HTTP, so the
        // link is up — treating that as "down" would make a healthy branch look
        // disconnected and stop it syncing.
        Http::fake(['*' => Http::response('', 401)]);

        $this->assertTrue(app(ConnectivityProbe::class)->reachable($this->connection()));
    }

    public function test_a_transport_failure_is_offline(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->assertFalse(app(ConnectivityProbe::class)->reachable($this->connection()));
    }

    public function test_an_underivable_endpoint_is_treated_as_online(): void
    {
        Http::fake(fn () => throw new ConnectionException('should not be called'));

        // "I cannot tell" must not become "offline": that would leave such a
        // device unable to sync at all, reported as a network error that never
        // happened. Let the cycle try and let its own failure classify itself.
        $this->assertTrue(app(ConnectivityProbe::class)->reachable($this->connection(['username' => 'postgres'])));
    }

    public function test_a_device_with_no_credential_is_offline(): void
    {
        // Nothing to be online TO. This runs without a database, so the vault
        // finding no row is the whole scenario.
        $this->assertFalse(app(ConnectivityProbe::class)->online());
    }
}
