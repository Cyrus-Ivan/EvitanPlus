<?php

namespace Tests\Feature\Sync;

use App\Exceptions\OfflineWriteException;
use App\Exceptions\SetupFailedException;
use App\Models\User;
use App\Support\Sync\OnlineRead;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;
use TypeError;

/**
 * A broken consolidated read must be distinguishable from a broken router.
 *
 * Every screen that can widen its scope wraps the read in
 * `rescue(..., null, report: false)` and falls back to the branch-local view.
 * The behaviour is right — a shop with no connection still has to work — but
 * `rescue()` catches `Throwable`, so a `TypeError`, a column that does not exist
 * upstream or a grant the matrix forgot ALL presented to the operator as "the
 * business database could not be reached", and `report: false` kept every one of
 * them out of the log as well. A feature could be dead at every branch for weeks
 * with nothing anywhere to say so.
 *
 * The documented `Carbon\Carbon` vs `Illuminate\Support\Carbon` trap is exactly
 * this shape, which is why the policy is pinned here rather than left to each
 * screen to remember.
 */
class ConsolidatedReadDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    /** Make the online read fail with $failure, however this screen calls it. */
    private function failingRead(Closure $failure): void
    {
        $this->app->instance(OnlineRead::class, new class($failure) extends OnlineRead
        {
            public function __construct(private readonly Closure $failure) {}

            public function run(Closure $work, string $subject = 'This'): mixed
            {
                return ($this->failure)();
            }

            public function possible(): bool
            {
                return true;
            }
        });
    }

    public function test_a_network_outage_is_not_logged(): void
    {
        Log::spy();

        $this->failingRead(fn () => throw OfflineWriteException::forReading('Sales'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('consolidatedOnline', false));

        Log::shouldNotHaveReceived('error');
    }

    /**
     * The one that matters: a programming error still falls back — the screen
     * must not 500 — but it is REPORTED, so somebody can find out.
     */
    public function test_a_programming_error_falls_back_and_is_still_logged(): void
    {
        Log::spy();

        $this->failingRead(fn () => throw new TypeError(
            'ReportPeriods::resolveWindow(): Argument #2 must be of type Illuminate\Support\Carbon, Carbon\Carbon given',
        ));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('consolidatedOnline', false));

        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_missing_column_upstream_is_logged(): void
    {
        Log::spy();

        $this->failingRead(fn () => throw new QueryException(
            'pgsql',
            'select "nope" from transactions',
            [],
            new PDOException('SQLSTATE[42703]: Undefined column: 7 ERROR:  column "nope" does not exist'),
        ));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales', ['scope' => 'all']))
            ->assertOk();

        Log::shouldHaveReceived('error')->once();
    }

    /**
     * The predicate itself, on the shapes a real outage arrives in — including
     * the wrapped ones, which is why it walks the `previous` chain.
     */
    public function test_the_outage_predicate_recognises_a_connection_failure(): void
    {
        $this->assertTrue(OnlineRead::isOutage(OfflineWriteException::forReading('Sales')));
        $this->assertTrue(OnlineRead::isOutage(new SetupFailedException('could not connect')));
        $this->assertTrue(OnlineRead::isOutage($this->pdo('08006', 'connection refused')));
        $this->assertTrue(OnlineRead::isOutage(new QueryException(
            'pgsql', 'select 1', [], $this->pdo('08006', 'connection refused'),
        )));

        // The distinction that matters: a query the schema refused is a BUG, and
        // it wraps a PDOException exactly like a dropped connection does.
        $this->assertFalse(OnlineRead::isOutage(new QueryException(
            'pgsql', 'select "nope"', [], $this->pdo('42703', 'column "nope" does not exist'),
        )));
        $this->assertFalse(OnlineRead::isOutage(new QueryException(
            'pgsql', 'select 1', [], $this->pdo('42501', 'permission denied for table transactions'),
        )));

        $this->assertFalse(OnlineRead::isOutage(new TypeError('a hint mismatch')));
        $this->assertFalse(OnlineRead::isOutage(new \RuntimeException('something else entirely')));
    }

    /**
     * A PDOException carrying a SQLSTATE, the way the driver raises one.
     *
     * The driver assigns the string SQLSTATE to `$code`, which `Exception`
     * declares protected — hence the subclass rather than an assignment.
     */
    private function pdo(string $sqlState, string $message): PDOException
    {
        return new class("SQLSTATE[{$sqlState}]: {$message}", $sqlState) extends PDOException
        {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message);

                $this->code = $sqlState;
            }
        };
    }
}
