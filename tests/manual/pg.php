<?php

declare(strict_types=1);

/**
 * The manual rig's Postgres half — catalog seeding and teardown.
 *
 *   php tests/manual/pg.php seed <branch-a.json> <branch-b.json> <ready|wizard>
 *   php tests/manual/pg.php drop <schema> [role...]
 *   php tests/manual/pg.php sweep
 *   php tests/manual/pg.php orphans
 *   php tests/manual/pg.php uuid
 *
 * ## Why this is a standalone script and not artisan or tinker
 *
 * Both verbs need the ADMIN Postgres credentials, which live only in
 * console/.env — the branch role deliberately cannot write catalog or stock
 * (BranchGrants: online-authoritative tables are pulled, never pushed), and it
 * certainly cannot drop a schema. But §18 forbids the POS app from referencing
 * console code, so this parses console/.env as PLAIN TEXT and opens a raw PDO,
 * exactly as tests/Integration/HydrationTest.php:473-512 does for the same
 * reason. Nothing here is autoloaded by the app and nothing ships.
 *
 * Plain `php file.php` also gives an honest exit code. `artisan tinker
 * --execute` does not — it exits 0 even when the required file throws, which is
 * why the rig's hydrate step needs a printed marker and this does not.
 *
 * ## Why `drop` cannot become a reaper
 *
 * It drops only the schema handed to it, which the rig recorded in its state
 * file before that schema existed. **There is no sweep over `biz_%` here and
 * there must never be one**: console/README.md §5 — "There is no reaper, by
 * choice" — because a `biz_%` schema is indistinguishable from a real
 * customer's. The identifier assertions below are the second guard: a corrupted
 * state file refuses rather than interpolating garbage into DDL.
 *
 * `drop` DOES read that one named schema's own `branches` rows to work out
 * which roles belong to it, and that is a different act from sweeping. The
 * schema is still chosen by the caller; the read only answers "what did this
 * schema's own rows say", scoped to an identifier that already passed the
 * assertion. Nothing outside the named schema is consulted or touched.
 *
 * ## Why `sweep` is not that reaper either
 *
 * `sweep` enumerates and destroys without being handed a name, which is exactly
 * what the paragraph above forbids — over `biz_%`. It operates over
 * `biz_test_%`, and the difference is one of kind rather than degree:
 * SchemaName::forBusiness() **cannot produce** a name in that namespace, so the
 * only door into it is the explicit `--disposable` option on
 * `business:provision`. A real business cannot land there by accident, whatever
 * its actor, name or branch code — which is precisely the guarantee those
 * free-text fields could never give, and the reason a sweep keyed on any of
 * them was rejected.
 *
 * Two properties follow, both load-bearing. The mark is the NAME, so it exists
 * from `CREATE SCHEMA` onward and a provision killed by a SIGINT is still
 * swept — a mark in the provisioning audit could not manage that, since
 * ProvisioningAudit writes only on a terminal outcome. And an UNMARKED schema is
 * invisible to `sweep` forever, which is the fail-safe direction: the cost of
 * the mark being missing is litter, never loss.
 *
 * Deliberately NOT guarded on `transactions = 0`. A rig business has sales in
 * it — you sell through it to look at the product — so that guard would refuse
 * exactly the schemas most worth sweeping. The namespace is the guard; the
 * counts are printed so the operator sees what went.
 *
 * `orphans` is the same distinction from the other side: it enumerates every
 * `biz_%` schema, and therefore it may only ever PRINT. It cannot classify a
 * schema safely — that is the whole reason there is no reaper — so it reports
 * what it can see and hands the operator the exact `drop` line to run, and a
 * human decides. Do not let it grow a `--force`. It stays for the case `sweep`
 * cannot cover: a `biz_<hex>` schema that never finished provisioning.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$verb = $argv[1] ?? '';

try {
    match ($verb) {
        'seed' => seedCatalog($root, $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? 'ready'),
        'drop' => dropObjects($root, array_slice($argv, 2)),
        'sweep' => sweepDisposable($root),
        'orphans' => listOrphans($root),
        'uuid' => print uuid()."\n",
        default => throw new RuntimeException(
            'usage: pg.php seed <a.json> <b.json> <ready|wizard> | pg.php drop <schema> [role...] '
            .'| pg.php sweep | pg.php orphans | pg.php uuid'
        ),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'pg.php: '.$e->getMessage()."\n");
    exit(1);
}

exit(0);

/**
 * Catalog, staff, different stock for each branch — and, in `ready` mode, a
 * year of trading behind all of it.
 *
 * A freshly provisioned business holds only its branches row, the Owner and
 * settings (BusinessProvisioner::seed) — no catalog at all. Hydrating that
 * gives you two tills with nothing to sell, so the rig seeds this in between.
 *
 * The per-branch quantities are the point: stock_snapshots is the one hydrated
 * table Postgres scopes by branch (Hydrator.php:177-182), so the same catalog
 * arriving on two devices with different numbers is the most legible possible
 * demonstration that these are two branches of one business. CEB is
 * deliberately short of a couple of things — one variant out of stock, one
 * under its low-stock threshold — so both of those UI states are visible on
 * arrival without anyone having to sell their way into them.
 *
 * ## Opening stock is a LEDGER ENTRY, not just a snapshot
 *
 * This seeder used to write `stock_snapshots` alone, and the consequence was
 * that no rig branch could ever sync a sale. MULTIBRANCH-ROADMAP.md's M6 note
 * states the rule and the reason: Postgres keeps no trigger on that table, so
 * the till maintains it — `RemoteStock::recompute()` sums Postgres's OWN ledger
 * after a push. Given a snapshot with no movement behind it, that sum is the
 * pushed sale alone, it goes negative, `stock_snapshots_non_negative` refuses
 * it, and the refusal takes the whole cycle with it. The branch then reports
 * `online` + `conflict` — correctly, and permanently.
 *
 * Found by driving the rig in M7 rather than by any test, because every suite
 * seeds its own opening stock properly. **Anything that puts opening stock into
 * a business schema owes it a ledger row.**
 *
 * ## The year, and why only `ready` gets one
 *
 * `ready` mode seeds 365 days of sales ({@see tests/manual/sales.php}) so that
 * `/sales`, `/reports` and the consolidated views have something in them. The
 * per-branch figures above become CLOSING stock: the opening quantity is that
 * figure minus everything the year moves, so the tills still come up on exactly
 * the numbers this table names.
 *
 * `wizard` mode seeds none of it, and that is deliberate rather than lazy.
 * `OpeningWindow::isOpen()` is derived from "this branch has no `type = 'sale'`
 * row", so a year of trading permanently closes M13.5's opening-stock import on
 * that device — no flag can reopen it, because a shelf with a past is precisely
 * what the pending-stock queue exists for. The two modes therefore mean two
 * different things: `ready` is a branch a year into its life, `wizard` is a
 * brand-new branch with its opening window still open.
 */
function seedCatalog(string $root, string $pathA, string $pathB, string $mode): void
{
    if (! in_array($mode, ['ready', 'wizard'], true)) {
        throw new RuntimeException("Unknown rig mode [{$mode}]; expected 'ready' or 'wizard'.");
    }

    require_once __DIR__.'/sales.php';

    $a = readDocument($pathA);
    $b = readDocument($pathB);

    $schema = $a['business']['schema'];

    if ($b['business']['schema'] !== $schema) {
        throw new RuntimeException('The two credentials files are for different businesses.');
    }

    // [category, item, unit, variant name, sku, price, cost, threshold, MNL, CEB, sells]
    //
    // `sells` is relative popularity, and it is what makes a year of sales read
    // like a shop rather than a random number generator: candy and water move
    // constantly, Party Size chips barely at all. It also decides the opening
    // stock, since that is now the closing figure plus everything the year sold.
    $catalog = [
        ['Beverages', 'Barako Coffee', 'cup', 'Small', 'BRK-S', '35.00', '12.00', 10, 60, 24, 22],
        ['Beverages', 'Barako Coffee', 'cup', 'Large', 'BRK-L', '55.00', '18.00', 10, 40, 8, 12],
        ['Beverages', 'Bottled Water 500ml', 'pc', null, 'WTR-500', '20.00', '9.00', 24, 120, 45, 20],
        ['Snacks', 'Potato Chips', 'pack', 'Regular', 'CHP-R', '32.00', '21.00', 12, 75, 30, 14],
        ['Snacks', 'Potato Chips', 'pack', 'Party Size', 'CHP-P', '89.00', '58.00', 6, 18, 0, 4],
        ['Snacks', 'Butter Biscuits', 'pack', null, 'BSC-1', '15.00', '8.50', 20, 90, 36, 12],
        ['Snacks', 'Sweet Candy', 'pc', null, 'CDY-1', '2.00', '1.10', 50, 400, 150, 26],
        ['Household', 'Dish Soap 250ml', 'bottle', null, 'DSH-250', '48.00', '31.00', 8, 25, 11, 5],
        ['Household', 'Laundry Detergent', 'sachet', 'Sachet', 'LDY-S', '12.00', '7.00', 30, 200, 64, 15],
        ['Household', 'Laundry Detergent', 'bar', 'Bar', 'LDY-B', '26.00', '16.00', 15, 55, 22, 7],
    ];

    // MNL is the busier branch. The difference is the whole point of the
    // consolidated per-branch table, which reads as a bug when both rows match.
    $branches = [
        ['id' => $a['branch']['id'], 'code' => $a['branch']['code'], 'per_day' => 12.0, 'column' => 8],
        ['id' => $b['branch']['id'], 'code' => $b['branch']['code'], 'per_day' => 9.0, 'column' => 9],
    ];

    $pdo = adminConnection($root);
    $pdo->exec('set search_path to '.quoteIdentifier($schema));

    // The business's own timezone rather than a constant repeated from rig.sh:
    // every timestamp below is written with this offset, and `RowMapper::inbound`
    // converts it back into the same zone on the way down to the tills.
    $zone = businessTimezone($pdo);

    $end = new DateTimeImmutable('now', $zone);
    $start = $end->setTime(0, 0)->modify('-'.(RIG_SALES_DAYS - 1).' days');
    // Opening stock lands the evening before the first sale, so the ledger reads
    // in the order the shop lived it.
    $opened = $start->modify('-1 day')->setTime(18, 0)->format('Y-m-d H:i:sP');

    $pdo->beginTransaction();

    try {
        [$variantIds, $variants] = insertCatalog($pdo, $catalog);
        $users = seedStaff($pdo, $opened);

        $insertStock = $pdo->prepare(
            'insert into stock_snapshots (id, branch_id, variant_id, quantity, created_at, updated_at)
             values (?, ?, ?, ?, ?, now())'
        );
        $insertLedger = $pdo->prepare(
            'insert into inventory_ledger
                (id, branch_id, variant_id, quantity, resulting_snapshot, type, sale_type,
                 reference_id, reference_type, notes, performed_by, created_at)
             values (?, ?, ?, ?, ?, \'stock_adjustment\', null, null, \'manual_adjustment\', ?, ?, ?)'
        );

        // One stream for both branches, seeded once, so CEB's year is its own
        // and not MNL's replayed at a different scale.
        mt_srand(RIG_SALES_SEED);

        $sales = 0;

        foreach ($branches as $branch) {
            $closing = [];

            foreach ($catalog as $index => $row) {
                $closing[$index] = (int) $row[$branch['column']];
            }

            $view = rigVariantView($variants, $closing);
            $plan = $mode === 'ready' ? rigPlanYear($branch, $view, $users, $start, $end) : [];

            $net = rigNetMovements($plan);
            $opening = [];

            foreach ($closing as $index => $quantity) {
                // Worked backwards: what the shelf must have held a year ago for
                // it to hold today's figure now. The snapshot is written with
                // today's figure, and the ledger below walks it there.
                $opening[$index] = $quantity - ($net[$index] ?? 0);

                $insertStock->execute([uuid(), $branch['id'], $variantIds[$index], $quantity, $opened]);

                // A zero opening balance is the absence of a movement, not a
                // movement of nothing — a variant with no stock and no sales gets
                // a snapshot of 0 and no ledger row, and the two still sum to the
                // same thing.
                if ($opening[$index] !== 0) {
                    $insertLedger->execute([
                        uuid(), $branch['id'], $variantIds[$index], $opening[$index], $opening[$index],
                        'Opening stock (manual rig)', $users[0]['id'], $opened,
                    ]);
                }
            }

            if ($plan === []) {
                continue;
            }

            $ended = rigWriteYear($pdo, $branch, $plan, $view, $variantIds, $opening);

            foreach ($closing as $index => $quantity) {
                if ($ended[$index] !== $quantity) {
                    throw new RuntimeException(
                        "Branch {$branch['code']} ends the year on {$ended[$index]} of {$variantIds[$index]}, "
                        ."not the {$quantity} its opening stock was sized for."
                    );
                }
            }

            $sales += count(array_filter($plan, static fn (array $e): bool => $e['kind'] === 'sale'));
        }

        assertStockAgreesWithLedger($pdo);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo 'seeded '.count($catalog).' variants and '.count($users)." users across two branches\n";

    if ($mode === 'ready') {
        echo 'seeded '.$sales.' sales over '.RIG_SALES_DAYS." days\n";
    }
}

/**
 * The catalog rows, and the two views of them the rest of this file needs.
 *
 * @param  list<array<int, mixed>>  $catalog
 * @return array{0: list<string>, 1: list<array{price: int, cost: int, weight: int}>}
 */
function insertCatalog(PDO $pdo, array $catalog): array
{
    $insertCategory = $pdo->prepare(
        'insert into categories (id, name, created_at, updated_at) values (?, ?, now(), now())'
    );
    $insertItem = $pdo->prepare(
        'insert into items (id, name, unit, category_id, created_at, updated_at)
         values (?, ?, ?, ?, now(), now())'
    );
    $insertVariant = $pdo->prepare(
        'insert into variants (id, item_id, name, sku, barcode, selling_price, cost, low_stock_threshold, created_at, updated_at)
         values (?, ?, ?, ?, null, ?, ?, ?, now(), now())'
    );

    $categories = [];
    $items = [];
    $ids = [];
    $variants = [];

    foreach ($catalog as $row) {
        [$category, $item, $unit, $variant, $sku, $price, $cost, $threshold] = $row;

        if (! isset($categories[$category])) {
            $categories[$category] = uuid();
            $insertCategory->execute([$categories[$category], $category]);
        }

        if (! isset($items[$item])) {
            $items[$item] = uuid();
            $insertItem->execute([$items[$item], $item, $unit, $categories[$category]]);
        }

        $variantId = uuid();

        // SKU is canonically uppercase — variants_sku_uppercase is a CHECK
        // constraint, so a lowercase one is a hard insert failure.
        $insertVariant->execute([
            $variantId, $items[$item], $variant, strtoupper($sku), $price, $cost, $threshold,
        ]);

        $ids[] = $variantId;
        $variants[] = [
            // Centavos from here on: the year's arithmetic is integer throughout.
            'price' => (int) round((float) $price * 100),
            'cost' => (int) round((float) $cost * 100),
            'weight' => (int) $row[10],
        ];
    }

    return [$ids, $variants];
}

/**
 * The catalog as the generator wants it: prices, popularity and the figure this
 * branch ends the year on.
 *
 * @param  list<array{price: int, cost: int, weight: int}>  $variants
 * @param  array<int, int>  $closing
 * @return list<array{price: int, cost: int, weight: int, final: int}>
 */
function rigVariantView(array $variants, array $closing): array
{
    $view = [];

    foreach ($variants as $index => $variant) {
        $view[] = $variant + ['final' => $closing[$index]];
    }

    return $view;
}

/**
 * The three staff accounts the rig adds to the provisioned Owner.
 *
 * Provisioning mints exactly one user, so without these every receipt in the
 * seeded year would name "Rig Owner" and there would be nobody to sign in as to
 * see a role-gated screen behave. Users are business-wide (§6), so both tills
 * hydrate all four; the weights are how much of the year each one rang up.
 *
 * bcrypt through PHP's own hasher rather than the framework's, which is not
 * loaded here. It is the same algorithm the app's `'password' => 'hashed'` cast
 * verifies against, and the cost need not match to verify — the same point
 * `BusinessProvisioner::seed()` makes where it mints the Owner.
 *
 * @return list<array{id: string, weight: int}> The Owner first.
 */
function seedStaff(PDO $pdo, string $stamp): array
{
    $owner = $pdo->query("select id from users where role = 'owner' limit 1")->fetchColumn();

    if ($owner === false) {
        throw new RuntimeException('No Owner in the schema; provision it before seeding.');
    }

    $insert = $pdo->prepare(
        'insert into users (id, name, username, password, role, must_change_password, created_at, updated_at, deleted_at)
         values (?, ?, ?, ?, ?, false, ?, ?, null)'
    );

    $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);
    $users = [['id' => (string) $owner, 'weight' => 10]];

    foreach ([
        ['Rig Admin', 'admin', 'admin', 15],
        ['Rig Cashier One', 'cashier1', 'cashier', 45],
        ['Rig Cashier Two', 'cashier2', 'cashier', 30],
    ] as [$name, $username, $role, $weight]) {
        $id = uuid();
        $insert->execute([$id, $name, $username, $hash, $role, $stamp, $stamp]);

        $users[] = ['id' => $id, 'weight' => $weight];
    }

    return $users;
}

/** The business timezone, as the provisioner wrote it into settings. */
function businessTimezone(PDO $pdo): DateTimeZone
{
    $value = $pdo->query("select value from settings where key = 'timezone'")->fetchColumn();

    if ($value === false || ! in_array((string) $value, timezone_identifiers_list(), true)) {
        throw new RuntimeException('The business has no usable timezone setting; provision it before seeding.');
    }

    return new DateTimeZone((string) $value);
}

/**
 * The invariant the whole seeding order exists to satisfy: every snapshot is
 * its own ledger sum, per branch and per variant.
 *
 * Checked before the commit, because the alternative is discovering it at a
 * till twenty minutes later as "Sync stuck" — `RemoteStock::recompute()` re-sums
 * this ledger after the first push and writes the answer over the snapshot. A
 * generator is exactly the kind of code that can be edited into disagreeing
 * with itself, so this asks the database rather than trusting the arithmetic.
 */
function assertStockAgreesWithLedger(PDO $pdo): void
{
    $rows = $pdo->query(
        'select coalesce(s.variant_id, l.variant_id) as variant_id,
                coalesce(s.quantity, 0) as snapshot,
                coalesce(l.total, 0) as ledger
           from stock_snapshots s
           full outer join (
                select branch_id, variant_id, sum(quantity) as total
                  from inventory_ledger
                 group by branch_id, variant_id
           ) l on l.branch_id = s.branch_id and l.variant_id = s.variant_id
          where coalesce(s.quantity, 0) <> coalesce(l.total, 0)'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        return;
    }

    $first = $rows[0];

    throw new RuntimeException(
        count($rows).' snapshot(s) disagree with the ledger behind them — the first is variant '
        ."{$first['variant_id']}, holding {$first['snapshot']} against a ledger summing to {$first['ledger']}. "
        .'A branch seeded this way can never sync.'
    );
}

/**
 * Drop this run's schema and its branch roles.
 *
 * Order and wording mirror console/app/Support/BranchRole.php:269-275. Schema
 * first is what lets the bare DROP ROLE succeed without superuser: CASCADE
 * takes the grants along with the tables, and a role still holding privileges
 * cannot be dropped.
 *
 * `if exists` throughout, so re-running `rig:clean` after a partial failure is
 * a no-op rather than an error.
 *
 * ## The roles are DISCOVERED, not only taken on trust
 *
 * Roles named on the command line are honoured, but the schema is also asked
 * which branches it holds, because the caller's list can be short of one. The
 * rig learns a role's name from the credentials JSON, which is not readable
 * until the provisioning command RETURNS — so a Ctrl-C during `branch:provision`
 * used to leave the second branch's role behind with its name recorded nowhere,
 * permanently: DROP SCHEMA ... CASCADE takes a role's grants but never the role.
 *
 * Reading `branches` closes that, and it is exact rather than heuristic because
 * both provisioning paths insert the row BEFORE creating the role —
 * BusinessProvisioner::seed then BranchRole::create (BusinessProvisioner.php:148-152),
 * BranchIssuer::insertBranch then BranchRole::create (BranchIssuer.php:178-186).
 * So every role that exists has a row naming it. The converse costs nothing:
 * a row whose role was never created drops to a no-op `drop role if exists`.
 *
 * The read is guarded because a schema interrupted early enough has no
 * `branches` table at all — that is the state this whole change exists for, and
 * it correctly yields no roles rather than an error.
 */
function dropObjects(string $root, array $identifiers): void
{
    $schema = array_shift($identifiers) ?? '';
    $roles = array_values(array_filter($identifiers, static fn (string $r): bool => $r !== ''));

    if (! isBusinessSchemaName($schema)) {
        throw new RuntimeException("Refusing to drop [{$schema}]: not a provisioned business schema name.");
    }

    foreach ($roles as $role) {
        if (! preg_match('/^branch_[0-9a-f]{32}$/', $role)) {
            throw new RuntimeException("Refusing to drop [{$role}]: not a branch role name.");
        }
    }

    $pdo = adminConnection($root);

    foreach (branchRoles($pdo, $schema) as $discovered) {
        if (! in_array($discovered, $roles, true)) {
            echo "found role {$discovered} in {$schema}.branches\n";
            $roles[] = $discovered;
        }
    }

    $pdo->exec('drop schema if exists '.quoteIdentifier($schema).' cascade');
    echo "dropped schema {$schema}\n";

    foreach ($roles as $role) {
        $pdo->exec('drop role if exists '.quoteIdentifier($role));
        echo "dropped role {$role}\n";
    }
}

/**
 * A schema name this file is willing to interpolate into a DROP.
 *
 * Two shapes, because there are two ways a business schema gets its name:
 *
 * - `biz_<32 hex>` — SchemaName::forBusiness, the ordinary case. Only ever
 *   reached by a caller that names it, never by `sweep`.
 * - `biz_test_<tail>` — the disposable namespace. The tail is deliberately
 *   loose: `business:provision --disposable` puts 32 hex there, but the console
 *   suite's own fixtures (console/tests/TestCase.php) put twelve random
 *   characters, and both are throwaway schemas that want sweeping.
 *
 * The point of the assertion is DDL safety, not classification — what makes a
 * schema sweepable is its prefix, checked separately in sweepDisposable().
 */
function isBusinessSchemaName(string $schema): bool
{
    return (bool) preg_match('/^biz_[0-9a-f]{32}$/', $schema)
        || (bool) preg_match('/^biz_test_[a-z0-9_]{1,53}$/', $schema);
}

/**
 * Destroy every schema in the disposable namespace — the ONLY enumerate-and-act
 * verb in this file, and see the header for why it is allowed to exist.
 *
 * Ordinary `drop` per schema, so roles are discovered from each schema's own
 * `branches` rows by the one implementation that has always done it. A fresh
 * connection per schema is a rounding error against fifteen-second provisions,
 * and it beats a second copy of the drop logic.
 *
 * Prints counts before each drop rather than asking about them. Nothing here
 * refuses on a non-zero transaction count: the namespace already answered the
 * only question that matters, and a rig business a year into its seeded life
 * has thousands of sales in it.
 */
function sweepDisposable(string $root): void
{
    $pdo = adminConnection($root);

    // The prefix is the whole test. `~` rather than LIKE because `_` is a LIKE
    // wildcard and an escaped one is a detail worth not re-deriving —
    // BusinessDirectory::schemas() makes the same call for the same reason.
    $schemas = $pdo->query(
        "select schema_name from information_schema.schemata
         where schema_name ~ '^biz_test_' order by schema_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($schemas === []) {
        echo "No disposable (biz_test_*) schemas on this project.\n";

        return;
    }

    echo count($schemas)." disposable schema(s) to destroy:\n\n";

    foreach ($schemas as $schema) {
        $schema = (string) $schema;

        printf("  %s\n      branches %s   transactions %s   schema_meta %s\n",
            $schema,
            countRows($pdo, $schema, 'branches') ?? '?',
            countRows($pdo, $schema, 'transactions') ?? '?',
            countRows($pdo, $schema, 'schema_meta') ?? '?',
        );
    }

    echo "\n";

    foreach ($schemas as $schema) {
        dropObjects($root, [(string) $schema]);
    }

    echo "\nswept ".count($schemas)." disposable schema(s).\n";
}

/**
 * The branch roles one schema's own rows account for — RoleName::forBranch
 * applied to every `branches.id`.
 *
 * Derived rather than looked up in `pg_roles`: role names are cluster-global and
 * every business's look alike, so matching them by prefix would be the sweep
 * this file must not perform. A branch UUID belongs to exactly one schema.
 *
 * @return list<string>
 */
function branchRoles(PDO $pdo, string $schema): array
{
    try {
        $ids = $pdo->query('select id from '.quoteIdentifier($schema).'.branches')
            ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        // No such schema, or no such table in a half-migrated one. Either way
        // there are no branches, so there are no roles.
        return [];
    }

    $roles = [];

    foreach ($ids as $id) {
        $hex = strtolower(str_replace('-', '', (string) $id));

        // Not an assertion: `branches.id` is a uuid column, so this cannot fail
        // for a row Postgres accepted. It is the same guard the caller's
        // identifiers get, applied to a value about to reach DDL.
        if (preg_match('/^[0-9a-f]{32}$/', $hex)) {
            $roles[] = 'branch_'.$hex;
        }
    }

    return $roles;
}

/**
 * Every surviving `biz_%` schema, annotated with what it is — READ-ONLY.
 *
 * The rig's safety net for the one thing its state file cannot cover: a run
 * killed so early that no state was written, or a leak from a build of the rig
 * that predates SCHEMA being recorded up front. It exists because `rig:clean`
 * used to answer "nothing to clean" to a question it had no way to answer.
 *
 * ## What each column is actually evidence of
 *
 * `last_touched` / `actors` come from a LEFT JOIN onto the console's own audit
 * trail — the same query console/README.md §7 documents for telling test litter
 * from a real business. **A schema with no audit row at all is the strongest
 * signal here**, and it is the signature of the exact bug this file was changed
 * for: BusinessProvisioner records only on a terminal outcome (success, or a
 * rollback from its `catch`), and a SIGINT produces neither. A schema created by
 * a completed console operation always has one.
 *
 * `branches` and `schema_meta` are BranchDirectory::isProvisionedBusiness's own
 * test, restated: a business that finished provisioning has both, so a schema
 * missing either never finished. `transactions` is the question to ask before
 * dropping anything — console/README.md §7 says so, and it is the only column
 * here that speaks for a business rather than about it.
 *
 * ## Why it prints and never acts
 *
 * None of that CLASSIFIES a schema, it only describes one. There is no registry
 * to ask (BusinessDirectory: "the read no table backs"), and the false positive
 * is not hypothetical: `verify:console` and `verify:hydrate` provision real
 * businesses through the real commands and drop them in tearDownAfterClass, so
 * running this DURING a suite lists healthy schemas mid-build under the same
 * flag as a leak. Observed while building this verb, which is why the printed
 * output says so first. It hands over the exact drop line and stops.
 */
function listOrphans(string $root): void
{
    $pdo = adminConnection($root);

    $rows = $pdo->query(
        "select s.schema_name,
                max(a.created_at)                 as last_touched,
                string_agg(distinct a.actor, ',') as actors
         from information_schema.schemata s
         left join evitan_console.provisioning_audit a on a.schema_name = s.schema_name
         where s.schema_name like 'biz\\_%'
         group by s.schema_name
         order by s.schema_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        echo "No biz_ schemas on this project.\n";

        return;
    }

    echo count($rows)." biz_ schema(s):\n\n";

    $suspect = [];

    foreach ($rows as $row) {
        $schema = (string) $row['schema_name'];

        $branches = countRows($pdo, $schema, 'branches');
        $transactions = countRows($pdo, $schema, 'transactions');
        $meta = countRows($pdo, $schema, 'schema_meta');

        // Never finished provisioning: no trail entry, or no branch to be a
        // business with. Either alone is enough to be worth a second look.
        // `?? 0` because a MISSING table counts the same as an empty one here —
        // a schema interrupted mid-migration has neither, and that is the case
        // most in need of the flag.
        $unfinished = $row['last_touched'] === null || ($branches ?? 0) === 0 || ($meta ?? 0) === 0;

        // A disposable schema needs no second look whatever its state: `sweep`
        // destroys it either way, so flagging it here would only send the
        // operator down a decision that has already been made.
        $disposable = str_starts_with($schema, 'biz_test_');

        if ($unfinished && ! $disposable) {
            $suspect[] = $schema;
        }

        printf("  %s %s%s\n", $unfinished && ! $disposable ? '!' : ' ', $schema,
            $disposable ? '   [disposable — `pg.php sweep` destroys it]' : '');
        printf("      branches %s   transactions %s   schema_meta %s\n",
            $branches ?? '?', $transactions ?? '?', $meta ?? '?');
        printf("      trail    %s\n",
            $row['last_touched'] === null
                ? 'NONE — no console operation ever completed against it'
                : $row['last_touched'].'  by '.$row['actors']);
    }

    if ($suspect === []) {
        echo "\nEvery schema that is not disposable has a completed trail entry, a schema_meta\n"
            ."row and at least one branch. Nothing here looks unfinished.\n";

        return;
    }

    echo "\n".count($suspect)." marked ! have not finished provisioning.\n\n"
        ."FIRST: is anything running? A rig, a `composer verify:console` or a\n"
        ."`verify:hydrate` provisions real businesses and drops them afterwards, and one\n"
        ."caught part-way through looks EXACTLY like a leak from here. Check with\n"
        ."`ps aux | grep -E 'phpunit|rig.sh'` and re-run this once it is finished.\n\n"
        ."Then confirm each is not a real business — the transaction count above, and\n"
        ."console/README.md §7 — before running:\n\n";

    foreach ($suspect as $schema) {
        echo "  php tests/manual/pg.php drop {$schema}\n";
    }

    echo "\nThat drops the schema and every branch role its own rows account for. Nothing\n"
        ."here does it for you: a biz_ schema is indistinguishable from a real customer's.\n"
        ."(A biz_test_ one is not, which is why `pg.php sweep` may destroy those unasked.)\n";
}

/** One table's row count, or null when the table is not there to be counted. */
function countRows(PDO $pdo, string $schema, string $table): ?int
{
    try {
        return (int) $pdo->query(
            'select count(*) from '.quoteIdentifier($schema).'.'.quoteIdentifier($table)
        )->fetchColumn();
    } catch (Throwable) {
        return null;
    }
}

/** A credentials JSON, read for the identifiers the rig needs. */
function readDocument(string $path): array
{
    if ($path === '' || ! is_file($path)) {
        throw new RuntimeException("Credentials file [{$path}] does not exist.");
    }

    $document = json_decode((string) file_get_contents($path), true);

    if (! is_array($document) || ! isset($document['business']['schema'], $document['branch']['id'], $document['branch']['code'])) {
        throw new RuntimeException("[{$path}] is not a branch credentials document.");
    }

    return $document;
}

/**
 * The admin connection, from console/.env.
 *
 * CONSOLE_DB_PORT is the SESSION endpoint (5432), never the transaction pooler
 * the branch credentials carry — DDL needs a session.
 */
function adminConnection(string $root): PDO
{
    $env = consoleEnv($root);

    foreach (['CONSOLE_DB_HOST', 'CONSOLE_DB_USERNAME', 'CONSOLE_DB_PASSWORD'] as $key) {
        if (($env[$key] ?? '') === '') {
            throw new RuntimeException("console/.env is missing {$key}; see console/.env.example.");
        }
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        $env['CONSOLE_DB_HOST'],
        $env['CONSOLE_DB_PORT'] ?? '5432',
        $env['CONSOLE_DB_DATABASE'] ?? 'postgres',
        $env['CONSOLE_DB_SSLMODE'] ?? 'require',
    );

    return new PDO($dsn, $env['CONSOLE_DB_USERNAME'], $env['CONSOLE_DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/** console/.env parsed as text — deliberately without loading console code. */
function consoleEnv(string $root): array
{
    $path = $root.'/console/.env';

    if (! is_file($path)) {
        throw new RuntimeException('console/.env does not exist; copy console/.env.example and fill it in.');
    }

    $values = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
}

/** Postgres identifier quoting: double the doubles, wrap in doubles. */
function quoteIdentifier(string $identifier): string
{
    return '"'.str_replace('"', '""', $identifier).'"';
}

/**
 * A TIME-ORDERED UUID — the stand-in for Str::orderedUuid(), which is what the
 * console and the app mint everywhere.
 *
 * Ordered rather than v4 for one reason that is not performance: the business
 * UUID becomes the schema NAME, so `select schema_name ... order by 1` and the
 * `orphans` listing come out in creation order. That is a real diagnostic — the
 * leak this file was changed for was first identified as "the schema immediately
 * before the run that succeeded", which a random v4 makes impossible to see.
 *
 * UUIDv7 layout: 48-bit big-endian milliseconds, then version 7 and the RFC 4122
 * variant, then random. Hex-sortable because the timestamp is the prefix.
 */
function uuid(): string
{
    $bytes = random_bytes(16);

    // Milliseconds since the epoch, big-endian, in the first six bytes.
    $ms = (int) (microtime(true) * 1000);

    for ($i = 5; $i >= 0; $i--) {
        $bytes[$i] = chr($ms & 0xFF);
        $ms >>= 8;
    }

    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

    return implode('-', array_map('bin2hex', [
        substr($bytes, 0, 4), substr($bytes, 4, 2), substr($bytes, 6, 2),
        substr($bytes, 8, 2), substr($bytes, 10, 6),
    ]));
}
