<?php

namespace Tests\Feature\EmployeePerformance\Concerns;

use App\Support\Sync\OnlineRead;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Inventory\Concerns\FakesThePendingQueue;
use Tests\TestCase;

/**
 * A stand-in business database for the tests that are about the employee report
 * rather than about Postgres.
 *
 * ## Why this is needed at all
 *
 * Employee performance is online-only: it reads the business database through
 * {@see OnlineRead} and has no local path, because a person is not a branch and a
 * branch-local month of theirs is wrong rather than narrow. {@see TestCase}
 * fakes `OnlineWrite` but deliberately leaves `OnlineRead` alone, and no test
 * device holds a sealed credential — so without this trait every test of this
 * screen would test it in its offline state, which is one test's worth of value,
 * not fifteen.
 *
 * ## Why the LOCAL connection, and not a second SQLite one
 *
 * {@see FakesThePendingQueue} builds a separate
 * stand-in because the rows it is about (`inventory_update_queue`) exist ONLY
 * upstream. Nothing here is like that: every table this report reads —
 * `login_history`, `transactions`, `sale_returns` and their lines — is mirrored,
 * and these tests write to them through the real checkout and the real return
 * processor. Pointing the read at a second, empty connection would prove nothing
 * about arithmetic that is the whole point of the suite.
 *
 * ## What this cannot prove, and where that is proved instead
 *
 * Everything about Postgres being Postgres: `to_char` against `strftime`, the
 * offset a `timestamptz` window bound has to carry, `numeric` SUMs arriving as
 * exact strings — and the branch code on each shift, which comes from a join onto
 * `branches` that only runs on `pgsql` (the local `login_history` has no
 * `branch_id` and deliberately never will). Shifts read back here therefore carry
 * a null branch and an empty `hours_by_branch`.
 *
 * Those are covered for real in tests/Integration/ConsolidatedEmployeePerformanceTest.php
 * against two provisioned branches. Treat this trait as testing the RECOMPUTE —
 * what a shift is, which day a sale lands on, whose net a refund reduces — and
 * that file as testing which database answers.
 */
trait ReadsTheBusinessLocally
{
    /**
     * Point the online read at this device's own connection.
     *
     * Call from `setUp()` after `parent::setUp()`, or from a test before acting.
     */
    private function readBusinessLocally(): void
    {
        $this->app->instance(OnlineRead::class, new class extends OnlineRead
        {
            public function __construct() {}

            public function run(Closure $work, string $subject = 'This'): mixed
            {
                return $work(DB::connection());
            }

            public function possible(): bool
            {
                return true;
            }
        });
    }
}
