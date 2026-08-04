<?php

namespace App\Support\Sync;

use App\Exceptions\SetupFailedException;
use App\Support\Settings\BusinessSettings;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one-time first fill of a new branch device (MULTIBRANCH-BRAINSTORM.md §16,
 * docs/glossary.md §3).
 *
 * A HYDRATE is not a PULL. A pull is the recurring delta M7 builds; this is the
 * initial baseline, and it can be run again from scratch because the local
 * mirror is the only store in the system that may be thrown away — everything in
 * it is either re-derivable from Postgres or already pushed there.
 *
 * ## What it copies
 *
 * Two groups, and the second arrived in M11.6.
 *
 * 1. The ONLINE-AUTHORITATIVE baseline: catalog (categories, items, variants),
 *    ALL users business-wide, the business settings, and this branch's stock
 *    snapshot. The till reads these and never writes them, so copying them down
 *    cannot lose a local edit.
 * 2. This branch's HISTORY: transactions and their items, sale returns and
 *    their items, the inventory ledger, login history, audit logs and import
 *    history — everything the branch authored and pushed up.
 *
 * Group 2 is new. Until M11.6 hydration deliberately skipped history, because a
 * device replacing an existing branch's hardware had a second recovery path: the
 * Owner restored a file backup. Removing that feature made hydration the ONLY
 * path, and three-quarters of a device is not a recovery — a rebuilt till came up
 * with the right catalog and stock but an empty /transactions, /audit-logs and
 * /login-history, no branch-local report figures, and no receipt to reprint.
 *
 * ## Why history is insert-only
 *
 * Seven of the eight history tables carry BEFORE UPDATE / BEFORE DELETE
 * immutability triggers (`2026_07_18_000001_add_immutability_triggers`), so
 * group 2 is written with `insertOrIgnore` rather than the `upsert` group 1
 * uses: an upsert's ON CONFLICT DO UPDATE arm fires that trigger and aborts the
 * whole hydrate. A first run has no conflicts and would not notice; a RE-hydrate
 * would fail on the first row it had already seen, which is exactly the case
 * this class promises to support. It is also the honest verb — an immutable row
 * that is already local cannot have anything to update.
 *
 * ## What it still does not copy
 *
 * Nothing of this branch's, now. Other branches' history stays upstream, where
 * M11's consolidated reporting reads it, and the local schema has no `branch_id`
 * to hold it in anyway.
 *
 * {@see serialFloors()} is unchanged and still read: with `transactions` copied
 * the generators would find the branch's high-water mark locally too, but the
 * floors on `instance` cost nothing and keep them correct however the copy is
 * scoped later.
 *
 * ## Why the copy is column-driven
 *
 * For each table the local SQLite schema names the columns, and exactly those
 * are selected from Postgres. The parity guard is what makes that safe — a
 * mirrored table's columns are proven to exist on both sides — and it means a
 * column added later to both schemas needs no edit here. A hand-written column
 * list would be a fourth place to remember, and the one nothing checks.
 *
 * Rows are written through the QUERY BUILDER, never models: hydration must not
 * fire the single-Owner guard (there is exactly one Owner in the data, and it is
 * arriving from the authority), the `hashed` password cast (the hashes are
 * already hashes), UUID generation (the ids are the business's), or — since
 * M11.6 put history tables in reach — the Immutable trait and PushedUpward,
 * which would enqueue the branch's own pulled-back history into the outbox and
 * push it straight up again.
 */
class Hydrator
{
    /**
     * Copy order matters — FK parents first. `settings` is unrelated to the
     * rest and goes first only because it is the cheapest thing to fail on.
     *
     * The history half (M11.6) is ordered by the local migrations' own foreign
     * keys: `sale_return_items` must follow `transaction_items`
     * (`transaction_item_id`), `inventory_ledger` needs `variants` and `users`
     * (`performed_by`), and the last three all reference `users`.
     *
     * @var list<string>
     */
    private const TABLES = [
        'settings', 'users', 'categories', 'items', 'variants', 'stock_snapshots',
        'transactions', 'transaction_items', 'sale_returns', 'sale_return_items',
        'inventory_ledger', 'login_history', 'audit_logs', 'import_history',
    ];

    /**
     * Tables whose rows may only ever be INSERTed locally, because SQLite
     * triggers abort any UPDATE or DELETE of them.
     *
     * Kept in step with `2026_07_18_000001_add_immutability_triggers` — plus
     * `import_history`, which carries no trigger but is append-only by the same
     * reasoning and gains nothing from an upsert.
     *
     * @var list<string>
     */
    private const INSERT_ONLY = [
        'transactions', 'transaction_items', 'sale_returns', 'sale_return_items',
        'inventory_ledger', 'login_history', 'audit_logs', 'import_history',
    ];

    /**
     * The child tables Postgres does NOT give a `branch_id`, and the parent
     * column that decides which branch each row belongs to.
     *
     * A line inherits its branch from its parent, and repeating the fact on the
     * child would be a second place for it to be wrong — the console migration
     * for `transaction_items` says so explicitly. Every other branch-scoped
     * table is detected rather than listed, by
     * {@see RowMapper::isBranchScoped()}.
     *
     * @var array<string, array{column: string, parent: string}>
     */
    private const PARENT_SCOPED = [
        'transaction_items' => ['column' => 'transaction_id', 'parent' => 'transactions'],
        'sale_return_items' => ['column' => 'sale_return_id', 'parent' => 'sale_returns'],
    ];

    /** Rows per round trip. Large enough to be one query per table in practice. */
    private const CHUNK = 500;

    /**
     * The timezone incoming `timestamptz` values are converted INTO.
     *
     * Not UTC. Since v1.0.0 this app stores naive BUSINESS-LOCAL strings in its
     * datetime columns — `ApplyBusinessTimezone` sets the default timezone at
     * boot and Laravel writes local time — so storing UTC here would leave every
     * hydrated row reading eight hours early in Manila, consistently enough to
     * look right and wrong everywhere it mattered. Resolved from the settings
     * this run copies, which is the first moment the device knows it.
     */
    private string $timezone = 'UTC';

    public function __construct(private readonly RowMapper $rows) {}

    /**
     * @return array<string, int> table => rows copied
     *
     * @throws SetupFailedException
     */
    public function hydrate(Connection $remote, CredentialsDocument $document): array
    {
        $business = $document->business();
        $branch = $document->branch();

        $this->assertSchema($remote, $business['schema']);
        $this->assertBranch($remote, $branch);

        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = $table === 'settings'
                ? $this->copySettings($remote)
                : $this->copyTable($remote, $table, $branch['id']);

            // settings comes first, and this is the reason it has to: the
            // business timezone is one of the six keys, and every timestamp
            // copied after this line is converted into it. On a fresh device
            // ApplyBusinessTimezone found no row at boot, so until now the app
            // has been running on the build's APP_TIMEZONE — which is a
            // different business's answer, or a default.
            if ($table === 'settings') {
                $this->timezone = $this->businessTimezone();
            }
        }

        return $counts;
    }

    /**
     * The business timezone as just hydrated, or the running app's if the
     * setting is missing or unrecognised.
     */
    private function businessTimezone(): string
    {
        $timezone = DB::table('settings')->where('key', 'timezone')->value('value');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone');
    }

    /**
     * The highest serials Postgres already holds for this branch.
     *
     * Read at hydration and stored on `instance` so the local generators
     * continue this branch's sequence instead of restarting it. It is null on a
     * genuinely new branch, which is the common case and costs nothing; it
     * matters only when a device REPLACES existing hardware, where the failure
     * it prevents is silent — the push is insert-only, so a re-used serial does
     * not error, it simply never arrives.
     *
     * Read here rather than at push time because hydration is the one moment the
     * device is guaranteed to be online and guaranteed to have written nothing
     * yet. By the first push a colliding sale has already been rung up.
     *
     * @return array{transaction: string|null, return: string|null}
     */
    public function serialFloors(Connection $remote, string $branchId): array
    {
        $row = $remote->selectOne(
            'select max(t.transaction_number) as transaction_number,
                    (select max(return_number) from sale_returns where branch_id = ?) as return_number
               from transactions t
              where t.branch_id = ?',
            [$branchId, $branchId],
        );

        return [
            'transaction' => $row?->transaction_number === null ? null : (string) $row->transaction_number,
            'return' => $row?->return_number === null ? null : (string) $row->return_number,
        ];
    }

    /**
     * The branch role's schema is pinned server-side (`ALTER ROLE ... SET
     * search_path`), which is the only pin that survives transaction pooling. If
     * it were missing, the role would land in `public` and every unqualified read
     * below would fail with "relation does not exist" — a message that sends the
     * reader looking for a missing table rather than a missing pin.
     */
    private function assertSchema(Connection $remote, string $expected): void
    {
        $actual = (string) $remote->selectOne('select current_schema() as name')->name;

        if ($actual !== $expected) {
            throw new SetupFailedException(
                "This credential lands in schema [{$actual}] but the file says [{$expected}]. "
                .'The role\'s search_path is not pinned — contact the developer who provisioned it.'
            );
        }
    }

    /**
     * @param  array{id: string, code: string, name: string, address: string|null}  $branch
     */
    private function assertBranch(Connection $remote, array $branch): void
    {
        $row = $remote->selectOne('select code from branches where id = ?', [$branch['id']]);

        if ($row === null) {
            throw new SetupFailedException(
                "The business database has no branch [{$branch['id']}]. "
                .'This credentials file does not belong to this business — check which file you imported.'
            );
        }

        if ((string) $row->code !== $branch['code']) {
            throw new SetupFailedException(
                "This branch is [{$row->code}] in the business database but [{$branch['code']}] in the credentials file. "
                .'Import the file issued for this branch.'
            );
        }
    }

    /**
     * The business settings, upserted BY KEY and restricted to the six business
     * keys.
     *
     * This is the one table where the local mirror is deliberately WIDER than
     * Postgres: the same settings table also holds this device's printer
     * configuration, which is hardware config and has no business-wide meaning.
     * A whole-table copy would erase it, which is exactly the kind of quiet loss
     * a re-hydrate must not cause.
     */
    private function copySettings(Connection $remote): int
    {
        $rows = $remote->table('settings')
            ->whereIn('key', BusinessSettings::KEYS)
            ->get(['id', 'key', 'value', 'created_at', 'updated_at']);

        $types = $this->localTypes('settings');

        foreach ($rows as $row) {
            $values = $this->normalise((array) $row, $types);

            // Matched on `key`, not `id`: a device that already carries a
            // settings row for this key — the defaults a seeded install writes —
            // must be updated rather than collided with. The upstream id comes
            // along, so a re-hydrate converges the row completely instead of
            // leaving two spellings of the same setting's identity around.
            DB::table('settings')->updateOrInsert(
                ['key' => $values['key']],
                [
                    'id' => $values['id'],
                    'value' => $values['value'],
                    'created_at' => $values['created_at'],
                    'updated_at' => $values['updated_at'],
                ],
            );
        }

        return $rows->count();
    }

    /**
     * @param  string  $branchId  Used only by the branch-scoped tables.
     */
    private function copyTable(Connection $remote, string $table, string $branchId): int
    {
        $types = $this->localTypes($table);
        $columns = array_keys($types);

        $query = $remote->table($table)->select($columns)->orderBy('id');

        $this->scopeToBranch($remote, $query, $table, $branchId);

        $copied = 0;
        $insertOnly = in_array($table, self::INSERT_ONLY, true);

        foreach ($query->cursor()->chunk(self::CHUNK) as $chunk) {
            $rows = $chunk
                ->map(fn (object $row): array => $this->normalise((array) $row, $types))
                ->all();

            // See the class docblock: an upsert on an immutable table fires its
            // BEFORE UPDATE trigger the moment a row is already local, which is
            // every row of a re-hydrate.
            $insertOnly
                ? DB::table($table)->insertOrIgnore($rows)
                : DB::table($table)->upsert($rows, ['id']);

            $copied += count($rows);
        }

        return $copied;
    }

    /**
     * Restrict $query to the rows belonging to this branch.
     *
     * The till holds only its own rows and has no `branch_id` column to put the
     * value in — it is stamped back on at PUSH time from `instance`, the single
     * place it lives locally. So the filter has to happen here, on the way down,
     * or another branch's history lands on this device with nothing to mark it
     * as anyone else's.
     *
     * Three cases, in the order they are tested: a table Postgres does not scope
     * at all (the catalog, users, settings — business-wide by definition and
     * copied whole); a child table that inherits its branch from a parent; and
     * everything else, which carries `branch_id` itself.
     */
    private function scopeToBranch(Connection $remote, Builder $query, string $table, string $branchId): void
    {
        if (isset(self::PARENT_SCOPED[$table])) {
            ['column' => $column, 'parent' => $parent] = self::PARENT_SCOPED[$table];

            $query->whereIn($column, fn (Builder $inner) => $inner
                ->select('id')
                ->from($parent)
                ->where('branch_id', $branchId));

            return;
        }

        if ($this->rows->isBranchScoped($remote, $table)) {
            $query->where('branch_id', $branchId);
        }
    }

    /**
     * Column => declared SQLite type for a local table.
     *
     * Delegates to {@see RowMapper}, which reads `pragma table_info` — the same
     * source the parity guard reads, so the type vocabulary here ('tinyint(1)',
     * 'datetime', …) is the vocabulary that guard already speaks. Wrapped only
     * to restate a missing table as a setup failure: during the wizard, "run the
     * app's migrations first" is an answer the developer can act on.
     *
     * @return array<string, string>
     */
    private function localTypes(string $table): array
    {
        try {
            return $this->rows->localTypes($table);
        } catch (RuntimeException $e) {
            throw new SetupFailedException(
                "The local table [{$table}] does not exist; run the app's migrations first.",
                previous: $e,
            );
        }
    }

    /**
     * Convert Postgres values to what the local column actually holds.
     *
     * The conversion itself lives in {@see RowMapper::inbound()}, beside its
     * inverse, because the two directions have to agree and M6 proved what
     * happens when they are written apart. The timezone is passed rather than
     * read from config for a reason specific to this class: on a fresh device
     * `config('app.timezone')` is still the build's default, and the business's
     * real answer only exists once {@see copySettings()} has run — which is why
     * `settings` is copied first and $timezone is set from it.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function normalise(array $row, array $types): array
    {
        return $this->rows->inbound($row, $types, $this->timezone);
    }
}
