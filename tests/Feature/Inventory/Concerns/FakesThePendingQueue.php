<?php

namespace Tests\Feature\Inventory\Concerns;

use App\Models\Instance;
use App\Models\User;
use App\Support\Inventory\AdjustmentResolver;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\Concerns\FakesTheBusinessDatabase;

/**
 * A stand-in business database for the tests that are about pending stock
 * adjustments rather than about Postgres.
 *
 * ## Why the default fakes are not enough here
 *
 * `Tests\TestCase` stands the online half down entirely: its `OnlineWrite`
 * fake runs the local closure and DROPS whatever `afterMirroring()` registers,
 * because before schema 21 the only thing that followed a mirror was
 * `RemoteStock::recompute`, which has no meaning with no Postgres to recompute
 * in.
 *
 * That is now exactly the wrong shape. A pending adjustment IS an
 * `afterMirroring` insert — `inventory_update_queue` is Postgres-only, so there
 * is no local row to write — which means under the default fake every stock
 * adjustment in the suite silently becomes a no-op. Since every stock movement
 * now goes through that queue, that would leave the whole feature untestable
 * without Supabase.
 *
 * So this trait keeps the closures and runs them against a second SQLite
 * connection, exactly as {@see FakesTheBusinessDatabase} already does for the
 * pull. Same trade, same limits.
 *
 * ## What this cannot prove, and where that is proved instead
 *
 * Everything about Postgres being Postgres: the six-column UPDATE grant, the
 * `(status = 'pending') = (resolved_at is null)` check constraint, `timestamptz`
 * rendering, and whether the branch role may write these columns at all. Those
 * are the things that fail silently, and they are covered for real in
 * tests/Integration/InventoryQueueTest.php against two provisioned branches.
 *
 * Treat this trait as testing the DECISION — who may answer what, which
 * quantities are legal, what a partial approval writes — and that file as
 * testing the round trip. A finding that belongs to the round trip must not be
 * asserted here, or it will pass while the shipped path is broken.
 */
trait FakesThePendingQueue
{
    use FakesTheBusinessDatabase;

    /**
     * Install the stand-in and rebind both online classes onto it.
     *
     * Call from a test before acting. Returns the connection so assertions can
     * read the queue back.
     */
    private function fakeBusinessQueue(): Connection
    {
        $remote = $this->businessDatabase([
            'branches', 'inventory_update_queue', 'inventory_ledger', 'stock_snapshots',
        ]);

        $this->app->instance(OnlineWrite::class, new class($remote) extends OnlineWrite
        {
            /** @var list<Closure(Connection): void> */
            private array $after = [];

            public function __construct(private readonly Connection $remote) {}

            public function run(Closure $work, string $subject = 'This'): mixed
            {
                $this->after = [];

                return DB::transaction(function () use ($work) {
                    $result = $work();

                    foreach ($this->after as $follow) {
                        $follow($this->remote);
                    }

                    return $result;
                });
            }

            /**
             * Copy the rows upward, so the ledger the resolver just wrote is
             * really there when `RemoteStock::recompute` sums it. A no-op here
             * would make every recompute find an empty ledger and quietly write
             * a zero snapshot — passing, and wrong.
             *
             * Only the tables the stand-in actually carries. A CSV import also
             * mirrors categories, items, variants and its history row, and those
             * are not what this stand-in is for: it exists to make the QUEUE
             * observable, and building the whole business schema to hold rows
             * nothing here reads would be a second copy of the app's own
             * migrations to keep in step.
             */
            public function mirror(Model ...$models): void
            {
                foreach ($models as $model) {
                    if (! $this->remote->getSchemaBuilder()->hasTable($model->getTable())) {
                        continue;
                    }

                    $this->remote->table($model->getTable())->insertOrIgnore(
                        $model->getAttributes() + ['branch_id' => (string) Instance::current()?->branch_id],
                    );
                }
            }

            public function afterMirroring(Closure $work): void
            {
                $this->after[] = $work;
            }

            public function possible(): bool
            {
                return true;
            }
        });

        $this->app->instance(OnlineRead::class, new class($remote) extends OnlineRead
        {
            public function __construct(private readonly Connection $remote) {}

            public function run(Closure $work, string $subject = 'This'): mixed
            {
                return $work($this->remote);
            }

            public function possible(): bool
            {
                return true;
            }
        });

        return $remote;
    }

    /**
     * Answer every row waiting on this branch, as $actor would from the dialog.
     *
     * A shortcut for the many tests whose subject is what a stock movement DOES
     * once confirmed, not the confirming — they used to get that for free,
     * because the movement happened at the moment it was asked for.
     */
    private function approveEverythingPending(User $actor): int
    {
        return app(AdjustmentResolver::class)->approveAll($actor);
    }
}
