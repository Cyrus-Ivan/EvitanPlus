<?php

namespace Tests\Unit\Sync;

use App\Support\Sync\BranchCycle;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * A sync cycle owns its connection (MULTIBRANCH-BRAINSTORM.md §11.5).
 *
 * Both properties asserted here fail silently in production if they regress:
 * dropping a config key surfaces as a missing driver option months later, and a
 * connection left open surfaces as pooler client-slot exhaustion once there are
 * enough branches — never on the machine that made the change.
 *
 * A SQLite connection stands in for Postgres, because what is under test is the
 * config handling and the disconnect, neither of which is dialect-specific.
 */
class BranchCycleTest extends TestCase
{
    /**
     * Enough of a connection to actually open, without a server. `host` is
     * present because Laravel's connection factory refuses a config whose host
     * key exists and is null — which the shipped pgsql defaults are — before it
     * ever looks at the driver.
     */
    private const IN_MEMORY = ['driver' => 'sqlite', 'host' => '127.0.0.1', 'database' => ':memory:'];

    public function test_it_merges_the_credentials_over_the_shipped_connection(): void
    {
        config(['database.connections.pgsql.options' => ['a-driver-option' => true]]);

        (new BranchCycle)->run(self::IN_MEMORY, function (): void {
            $connection = config('database.connections.pgsql');

            // From the credentials…
            $this->assertSame(':memory:', $connection['database']);
            // …and the keys the JSON does not carry, still there. A wholesale
            // assignment drops these, and nothing else would notice.
            $this->assertSame(['a-driver-option' => true], $connection['options']);
            $this->assertSame('utf8', $connection['charset']);
            $this->assertTrue($connection['prefix_indexes']);
        });
    }

    public function test_it_disconnects_and_restores_the_config_afterwards(): void
    {
        $before = config('database.connections.pgsql');

        (new BranchCycle)->run(self::IN_MEMORY, fn (Connection $c) => $c->select('select 1'));

        $this->assertSame($before, config('database.connections.pgsql'));
        $this->assertArrayNotHasKey('pgsql', DB::getConnections());
    }

    public function test_it_disconnects_even_when_the_cycle_throws(): void
    {
        // The case the `finally` exists for: a failed cycle must release its
        // pooler client slot exactly like a successful one, or an offline branch
        // holds a slot for as long as it keeps retrying.
        try {
            (new BranchCycle)->run(self::IN_MEMORY, function (): void {
                throw new RuntimeException('the cycle failed');
            });

            $this->fail('The failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('the cycle failed', $e->getMessage());
        }

        $this->assertArrayNotHasKey('pgsql', DB::getConnections());
        $this->assertEmpty(config('database.connections.pgsql.database'));
    }

    public function test_the_connection_is_handed_to_the_closure(): void
    {
        $name = (new BranchCycle)->run(
            self::IN_MEMORY,
            fn (Connection $connection): string => $connection->getName(),
        );

        $this->assertSame('pgsql', $name);
    }
}
