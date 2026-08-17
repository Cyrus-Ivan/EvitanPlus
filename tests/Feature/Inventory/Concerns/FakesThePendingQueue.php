<?php

namespace Tests\Feature\Inventory\Concerns;

use App\Models\Instance;
use App\Support\Sync\OnlineRead;
use App\Support\Sync\OnlineWrite;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ApprovesEveryPendingRow;
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
 *
 * ## One session, where production has two
 *
 * The `OnlineRead` fake below hands back the SAME connection the write uses,
 * because there is only one stand-in. Production has two: `OnlineRead` runs its
 * own `BranchCycle` — connect, query, `DB::purge`, disconnect — and the write
 * then opens a second connection, so the gap between "what the resolver read"
 * and "what it writes" is a full reconnect rather than microseconds.
 *
 * That gap cannot be reproduced here by construction, so a test about the RACE
 * has to create it by hand — see `AdjustmentResolutionTest`'s
 * `answerElsewhereMidFlight()`, which hooks the local ledger insert that happens
 * between the two. `docs/glossary.md` §9 carries the accepted risk this leaves.
 */
trait FakesThePendingQueue
{
    use ApprovesEveryPendingRow, FakesTheBusinessDatabase;

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

            /**
             * Saves and RESTORES the follow-up stack, exactly as the real class
             * does in its `finally`.
             *
             * This used to just clear it (`$this->after = []`) on the way in,
             * which is fine for one write and wrong for a NESTED one — and both
             * importers nest, because their failure paths call `mirrorFailure()`
             * inside the outer write. Under the old fake the inner write wiped
             * the outer write's registered follow-ups and they never ran, so a
             * real regression in that path would have passed here while failing
             * in production. A fake that is laxer than the thing it stands in for
             * is a false negative generator.
             */
            public function run(Closure $work, string $subject = 'This'): mixed
            {
                $previous = $this->after;
                $this->after = [];

                try {
                    return DB::transaction(function () use ($work) {
                        $result = $work();

                        foreach ($this->after as $follow) {
                            $follow($this->remote);
                        }

                        return $result;
                    });
                } finally {
                    $this->after = $previous;
                }
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
}
