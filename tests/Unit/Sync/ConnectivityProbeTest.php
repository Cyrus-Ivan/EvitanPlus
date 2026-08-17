<?php

namespace Tests\Unit\Sync;

use App\Support\Sync\ConnectivityProbe;
use App\Support\Sync\NetworkLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
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

    /**
     * The advertised two-second budget must bound a server that never answers,
     * not only one that answers slowly.
     *
     * `Http::timeout()` sets Guzzle's `timeout` alone, leaving `connect_timeout`
     * at Laravel's default of ten seconds. So the shop failure that matters —
     * a router associated but black-holing packets, no refusal and no DNS error,
     * just silence — took five times the budget this class documents. That lands
     * on a render path: the Inventory page probes, and so does the roster read
     * behind every scope picker, on every load.
     *
     * Asserted on the request options rather than by timing, which would be a
     * ten-second test and a flaky one.
     */
    public function test_the_probe_bounds_the_connect_as_well_as_the_response(): void
    {
        $options = [];

        // The fake's callback is handed the MERGED Guzzle options, which is the
        // only place a timeout that never reaches the wire can be observed.
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('', 401);
        });

        app(ConnectivityProbe::class)->reachable($this->connection());

        $this->assertSame(2, $options['connect_timeout'] ?? null, 'A server that never answers must not outlast the budget.');
        $this->assertSame(2, $options['timeout'] ?? null);
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

    /**
     * The cheap layer must actually save the expensive one.
     *
     * A socket does not fail fast just because the machine is plainly offline —
     * traffic forced onto an interface with no carrier still burns the full two
     * seconds. So the saving is real only if no HTTP is attempted at all, which
     * is why this asserts on the absence of a request rather than on the result.
     */
    public function test_a_down_link_answers_without_touching_the_network(): void
    {
        $this->app->instance(NetworkLink::class, new class extends NetworkLink
        {
            public function isUp(): bool
            {
                return false;
            }
        });

        Http::fake(fn () => throw new ConnectionException('the probe must not have got this far'));

        $this->assertFalse(app(ConnectivityProbe::class)->reachable($this->connection()));

        Http::assertNothingSent();
    }

    /**
     * `/inventory` asks twice per render — once for its own `online` prop, once
     * inside the roster read behind the scope picker — and neither call knows the
     * other exists. That was two identical round trips milliseconds apart, and
     * four seconds rather than two when the network was silent.
     *
     * Bound to the container deliberately: the memo only works because the probe
     * is a shared singleton, so resolving it twice is half of what is under test.
     */
    public function test_the_answer_is_reused_for_the_rest_of_the_request(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->assertTrue(app(ConnectivityProbe::class)->reachable($this->connection()));
        $this->assertTrue(app(ConnectivityProbe::class)->reachable($this->connection()));

        Http::assertSentCount(1);
    }

    public function test_a_device_with_no_credential_is_offline(): void
    {
        // Nothing to be online TO. This runs without a database, so the vault
        // finding no row is the whole scenario.
        $this->assertFalse(app(ConnectivityProbe::class)->online());
    }
}
