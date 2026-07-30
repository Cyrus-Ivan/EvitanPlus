<?php

declare(strict_types=1);

/**
 * The manual rig's Postgres half — catalog seeding and teardown.
 *
 *   php tests/manual/pg.php seed <branch-a.json> <branch-b.json>
 *   php tests/manual/pg.php drop <schema> <role> [role...]
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
 * It drops only the identifiers handed to it, which the rig read out of the
 * credentials JSONs of the run it is tearing down. There is no sweep by name
 * pattern here and there must never be one: console/README.md §5 — "There is
 * no reaper, by choice" — because a `biz_%` schema is indistinguishable from a
 * real customer's. The identifier assertions below are the second guard: a
 * corrupted state file refuses rather than interpolating garbage into DDL.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
$verb = $argv[1] ?? '';

try {
    match ($verb) {
        'seed' => seedCatalog($root, $argv[2] ?? '', $argv[3] ?? ''),
        'drop' => dropObjects($root, array_slice($argv, 2)),
        default => throw new RuntimeException(
            'usage: pg.php seed <a.json> <b.json> | pg.php drop <schema> <role> [role...]'
        ),
    };
} catch (Throwable $e) {
    fwrite(STDERR, 'pg.php: '.$e->getMessage()."\n");
    exit(1);
}

exit(0);

/**
 * Catalog, and different stock for each branch.
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
 */
function seedCatalog(string $root, string $pathA, string $pathB): void
{
    $a = readDocument($pathA);
    $b = readDocument($pathB);

    $schema = $a['business']['schema'];

    if ($b['business']['schema'] !== $schema) {
        throw new RuntimeException('The two credentials files are for different businesses.');
    }

    $branchA = $a['branch']['id'];
    $branchB = $b['branch']['id'];

    // [category, item, unit, variant name, sku, price, cost, threshold, MNL, CEB]
    $catalog = [
        ['Beverages', 'Barako Coffee', 'cup', 'Small', 'BRK-S', '35.00', '12.00', 10, 60, 24],
        ['Beverages', 'Barako Coffee', 'cup', 'Large', 'BRK-L', '55.00', '18.00', 10, 40, 8],
        ['Beverages', 'Bottled Water 500ml', 'pc', null, 'WTR-500', '20.00', '9.00', 24, 120, 45],
        ['Snacks', 'Potato Chips', 'pack', 'Regular', 'CHP-R', '32.00', '21.00', 12, 75, 30],
        ['Snacks', 'Potato Chips', 'pack', 'Party Size', 'CHP-P', '89.00', '58.00', 6, 18, 0],
        ['Snacks', 'Butter Biscuits', 'pack', null, 'BSC-1', '15.00', '8.50', 20, 90, 36],
        ['Snacks', 'Sweet Candy', 'pc', null, 'CDY-1', '2.00', '1.10', 50, 400, 150],
        ['Household', 'Dish Soap 250ml', 'bottle', null, 'DSH-250', '48.00', '31.00', 8, 25, 11],
        ['Household', 'Laundry Detergent', 'sachet', 'Sachet', 'LDY-S', '12.00', '7.00', 30, 200, 64],
        ['Household', 'Laundry Detergent', 'bar', 'Bar', 'LDY-B', '26.00', '16.00', 15, 55, 22],
    ];

    $pdo = adminConnection($root);
    $pdo->exec('set search_path to '.quoteIdentifier($schema));
    $pdo->beginTransaction();

    try {
        $categories = [];
        $items = [];

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
        $insertStock = $pdo->prepare(
            'insert into stock_snapshots (id, branch_id, variant_id, quantity, created_at, updated_at)
             values (?, ?, ?, ?, now(), now())'
        );
        $insertLedger = $pdo->prepare(
            'insert into inventory_ledger
                (id, branch_id, variant_id, quantity, resulting_snapshot, type, sale_type,
                 reference_id, reference_type, notes, performed_by, created_at)
             values (?, ?, ?, ?, ?, \'stock_adjustment\', null, null, \'manual_adjustment\', ?, ?, now())'
        );

        // The Owner the provisioner seeded — the ledger's performed_by is a real
        // FK, and this schema has exactly one user at this point.
        $owner = $pdo->query("select id from users where role = 'owner' limit 1")->fetchColumn();

        if ($owner === false) {
            throw new RuntimeException('No Owner in the schema; provision it before seeding.');
        }

        // The movement that PRODUCED the opening stock, without which the
        // snapshot is a figure with no history and the first pushed sale takes
        // the branch's whole sync down. See the docblock above.
        $openStock = static function (string $branch, string $variantId, int $quantity) use ($insertStock, $insertLedger, $owner): void {
            $insertStock->execute([uuid(), $branch, $variantId, $quantity]);

            // A zero opening balance is the absence of a movement, not a
            // movement of nothing — CEB's out-of-stock variant gets a snapshot
            // of 0 and no ledger row, and the two still sum to the same thing.
            if ($quantity !== 0) {
                $insertLedger->execute([
                    uuid(), $branch, $variantId, $quantity, $quantity, 'Opening stock (manual rig)', $owner,
                ]);
            }
        };

        foreach ($catalog as [$category, $item, $unit, $variant, $sku, $price, $cost, $threshold, $stockA, $stockB]) {
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

            $openStock($branchA, $variantId, $stockA);
            $openStock($branchB, $variantId, $stockB);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo 'seeded '.count($catalog)." variants across two branches\n";
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
 */
function dropObjects(string $root, array $identifiers): void
{
    $schema = array_shift($identifiers) ?? '';
    $roles = array_values(array_filter($identifiers, static fn (string $r): bool => $r !== ''));

    if (! preg_match('/^biz_[0-9a-f]{32}$/', $schema)) {
        throw new RuntimeException("Refusing to drop [{$schema}]: not a provisioned business schema name.");
    }

    foreach ($roles as $role) {
        if (! preg_match('/^branch_[0-9a-f]{32}$/', $role)) {
            throw new RuntimeException("Refusing to drop [{$role}]: not a branch role name.");
        }
    }

    $pdo = adminConnection($root);

    $pdo->exec('drop schema if exists '.quoteIdentifier($schema).' cascade');
    echo "dropped schema {$schema}\n";

    foreach ($roles as $role) {
        $pdo->exec('drop role if exists '.quoteIdentifier($role));
        echo "dropped role {$role}\n";
    }
}

/** A credentials JSON, read for the identifiers the rig needs. */
function readDocument(string $path): array
{
    if ($path === '' || ! is_file($path)) {
        throw new RuntimeException("Credentials file [{$path}] does not exist.");
    }

    $document = json_decode((string) file_get_contents($path), true);

    if (! is_array($document) || ! isset($document['business']['schema'], $document['branch']['id'])) {
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

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

    return implode('-', array_map('bin2hex', [
        substr($bytes, 0, 4), substr($bytes, 4, 2), substr($bytes, 6, 2),
        substr($bytes, 8, 2), substr($bytes, 10, 6),
    ]));
}
