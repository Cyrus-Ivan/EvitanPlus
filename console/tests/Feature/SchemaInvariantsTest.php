<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The invariants a business schema must carry (Phase M1).
 *
 * Every assertion here works by ATTEMPTING the violation and requiring the
 * database to refuse it. Asserting that an index or constraint merely exists
 * would pass just as happily against one with the wrong predicate — the
 * partial indexes in particular (barcode unique only when present, one live
 * Owner) are easy to write in a way that looks right and enforces nothing.
 *
 * These run as the ADMIN role, which bypasses grants. That is deliberate: it
 * proves the constraints hold against the most privileged connection in the
 * system, not merely against a least-privileged branch role.
 *
 * Nothing here is Supabase-specific — no pooler, no branch role, no pg_roles,
 * just DDL and the constraints it carries — so it would run against any
 * Postgres. It runs against the Supabase project anyway, because that is the
 * only Postgres this project keeps, and because a second one would be a second
 * thing to install, configure and debug for a guard that reads
 * information_schema. M4's contribution was not a new place to run it: it was
 * putting it behind `composer verify:console`, so it runs at all.
 */
class SchemaInvariantsTest extends TestCase
{
    private Connection $db;

    private string $schema;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->migratedSchema();
        $this->schema = $this->schemaName();

        // Every test's writes live inside one transaction that tearDown rolls
        // back, so the class's single migrated schema is reusable and each
        // test still starts from the same clean fixtures.
        $this->db->beginTransaction();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel() > 0) {
            $this->db->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The minimum graph every test needs: one branch, one owner, one item with
     * one variant. Kept tiny so each test's own inserts are the interesting part.
     */
    private function seedFixtures(): void
    {
        $this->ids = [
            'branch' => (string) Str::uuid(),
            'branch2' => (string) Str::uuid(),
            'owner' => (string) Str::uuid(),
            'item' => (string) Str::uuid(),
            'variant' => (string) Str::uuid(),
        ];

        $this->db->table('branches')->insert([
            ['id' => $this->ids['branch'], 'code' => 'MNL', 'name' => 'Manila'],
            ['id' => $this->ids['branch2'], 'code' => 'CEB', 'name' => 'Cebu'],
        ]);

        $this->db->table('users')->insert([
            'id' => $this->ids['owner'], 'username' => 'owner', 'password' => 'hash', 'role' => 'owner',
        ]);

        $this->db->table('items')->insert([
            'id' => $this->ids['item'], 'name' => 'Coffee', 'unit' => 'pc',
        ]);

        $this->db->table('variants')->insert([
            'id' => $this->ids['variant'], 'item_id' => $this->ids['item'],
            'sku' => 'COFFEE-1', 'selling_price' => '25.00',
        ]);
    }

    /**
     * Assert the given write is refused by the database.
     *
     * Runs inside a nested transaction — a SAVEPOINT, since setUp already
     * opened one — because in Postgres a failed statement poisons the whole
     * enclosing transaction: without the savepoint, every later statement in
     * the test would fail with "current transaction is aborted" and the test
     * would appear to pass for entirely the wrong reason.
     */
    private function assertRejected(callable $write, string $because): void
    {
        try {
            $this->db->transaction(static fn () => $write());
        } catch (QueryException) {
            // The refusal IS the assertion; record it so PHPUnit does not
            // report these tests as assertion-free.
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("Expected the database to reject this write: {$because}");
    }

    public function test_the_schema_carries_every_expected_table(): void
    {
        $tables = array_map(
            fn (object $row): string => $row->table_name,
            $this->db->select(
                'select table_name from information_schema.tables where table_schema = ? order by table_name',
                [$this->schema],
            ),
        );

        foreach ([
            'audit_logs', 'branch_status', 'branches', 'categories', 'import_history',
            'inventory_ledger', 'inventory_update_queue', 'items', 'login_history',
            'sale_return_items', 'sale_returns', 'schema_meta', 'settings',
            'stock_snapshots', 'transaction_items', 'transactions', 'users', 'variants',
        ] as $expected) {
            $this->assertContains($expected, $tables);
        }
    }

    public function test_the_schema_reports_its_version(): void
    {
        $meta = $this->db->selectOne('select schema_version, min_app_version from schema_meta');

        $this->assertSame(1, $meta->schema_version);
        $this->assertSame('1.0.0', $meta->min_app_version);

        // A second row would make "the" schema version ambiguous exactly when a
        // branch is deciding whether it may sync.
        $this->assertRejected(
            fn () => $this->db->table('schema_meta')->insert([
                'id' => 2, 'schema_version' => 2, 'min_app_version' => '2.0.0',
            ]),
            'schema_meta must hold exactly one row',
        );
    }

    public function test_there_can_only_ever_be_one_owner(): void
    {
        $this->assertRejected(
            fn () => $this->db->table('users')->insert([
                'id' => (string) Str::uuid(), 'username' => 'owner2', 'password' => 'hash', 'role' => 'owner',
            ]),
            'a business has exactly one Owner',
        );

        // ...but the constraint is partial, so an archived owner does not block
        // a live one — otherwise ownership could never be transferred.
        $this->db->table('users')->where('id', $this->ids['owner'])->update(['deleted_at' => now()]);

        $this->db->table('users')->insert([
            'id' => (string) Str::uuid(), 'username' => 'owner2', 'password' => 'hash', 'role' => 'owner',
        ]);

        $this->assertSame(1, $this->db->table('users')->where('role', 'owner')->whereNull('deleted_at')->count());
    }

    public function test_usernames_are_unique_across_the_business(): void
    {
        $this->assertRejected(
            fn () => $this->db->table('users')->insert([
                'id' => (string) Str::uuid(), 'username' => 'owner', 'password' => 'hash', 'role' => 'cashier',
            ]),
            'usernames must be unique',
        );
    }

    public function test_item_names_are_unique_case_insensitively(): void
    {
        $this->assertRejected(
            fn () => $this->db->table('items')->insert([
                'id' => (string) Str::uuid(), 'name' => 'COFFEE', 'unit' => 'pc',
            ]),
            'item names are unique case-insensitively',
        );

        // Archiving does not release the name: history must never read as two
        // different products.
        $this->db->table('items')->where('id', $this->ids['item'])->update(['archived_at' => now()]);

        $this->assertRejected(
            fn () => $this->db->table('items')->insert([
                'id' => (string) Str::uuid(), 'name' => 'coffee', 'unit' => 'pc',
            ]),
            'an archived item still reserves its name',
        );
    }

    public function test_skus_are_required_uppercase_and_unique(): void
    {
        $this->assertRejected(
            fn () => $this->db->table('variants')->insert([
                'id' => (string) Str::uuid(), 'item_id' => $this->ids['item'],
                'sku' => 'lowercase-sku', 'selling_price' => '1.00',
            ]),
            'SKUs are stored uppercase',
        );

        $this->assertRejected(
            fn () => $this->db->table('variants')->insert([
                'id' => (string) Str::uuid(), 'item_id' => $this->ids['item'],
                'sku' => 'COFFEE-1', 'selling_price' => '1.00',
            ]),
            'SKUs are unique',
        );
    }

    public function test_barcodes_are_unique_only_when_present(): void
    {
        $this->db->table('variants')->where('id', $this->ids['variant'])->update(['barcode' => '4800001']);

        $this->assertRejected(
            fn () => $this->db->table('variants')->insert([
                'id' => (string) Str::uuid(), 'item_id' => $this->ids['item'],
                'sku' => 'COFFEE-2', 'selling_price' => '1.00', 'barcode' => '4800001',
            ]),
            'a duplicate barcode',
        );

        // Blank barcodes must never block selling, so any number of variants
        // may have none. A non-partial unique index would break on the second.
        foreach (['COFFEE-3', 'COFFEE-4'] as $sku) {
            $this->db->table('variants')->insert([
                'id' => (string) Str::uuid(), 'item_id' => $this->ids['item'],
                'sku' => $sku, 'selling_price' => '1.00', 'barcode' => null,
            ]);
        }

        $this->assertSame(2, $this->db->table('variants')->whereNull('barcode')->count());
    }

    public function test_transaction_serials_are_unique_per_branch_not_globally(): void
    {
        $this->insertTransaction($this->ids['branch'], '0000000001');

        // The second branch counts from 1 as well — uniqueness comes from the
        // branch-code prefix on the printed receipt, not from the database.
        $this->insertTransaction($this->ids['branch2'], '0000000001');

        $this->assertRejected(
            fn () => $this->insertTransaction($this->ids['branch'], '0000000001'),
            'a serial repeated within one branch',
        );
    }

    public function test_stock_can_never_go_negative(): void
    {
        $this->assertRejected(
            fn () => $this->db->table('stock_snapshots')->insert([
                'id' => (string) Str::uuid(), 'branch_id' => $this->ids['branch'],
                'variant_id' => $this->ids['variant'], 'quantity' => -1,
            ]),
            'a negative stock snapshot',
        );

        $this->assertRejected(
            fn () => $this->db->table('inventory_ledger')->insert([
                'id' => (string) Str::uuid(), 'branch_id' => $this->ids['branch'],
                'variant_id' => $this->ids['variant'], 'quantity' => -5, 'resulting_snapshot' => -5,
                'type' => 'sale', 'reference_type' => 'transaction', 'performed_by' => $this->ids['owner'],
            ]),
            'a ledger entry leaving stock negative',
        );
    }

    public function test_stock_is_tracked_per_branch(): void
    {
        foreach ([$this->ids['branch'], $this->ids['branch2']] as $branch) {
            $this->db->table('stock_snapshots')->insert([
                'id' => (string) Str::uuid(), 'branch_id' => $branch,
                'variant_id' => $this->ids['variant'], 'quantity' => 10,
            ]);
        }

        $this->assertSame(2, $this->db->table('stock_snapshots')->count());

        $this->assertRejected(
            fn () => $this->db->table('stock_snapshots')->insert([
                'id' => (string) Str::uuid(), 'branch_id' => $this->ids['branch'],
                'variant_id' => $this->ids['variant'], 'quantity' => 5,
            ]),
            'two snapshots for the same variant at the same branch',
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function immutableTables(): array
    {
        return array_map(fn (string $t): array => [$t], [
            'transactions', 'transaction_items', 'inventory_ledger',
            'sale_returns', 'sale_return_items', 'login_history', 'audit_logs',
        ]);
    }

    #[DataProvider('immutableTables')]
    public function test_append_only_tables_reject_update_and_delete(string $table): void
    {
        $this->seedImmutableRow($table);

        $this->assertRejected(
            fn () => $this->db->statement("update {$table} set created_at = now()"),
            "{$table} rows must be immutable",
        );

        $this->assertRejected(
            fn () => $this->db->statement("delete from {$table}"),
            "{$table} rows must never be deleted",
        );

        $this->assertSame(1, $this->db->table($table)->count());
    }

    private function insertTransaction(string $branch, string $number): string
    {
        $id = (string) Str::uuid();

        $this->db->table('transactions')->insert([
            'id' => $id, 'branch_id' => $branch, 'transaction_number' => $number,
            'payment_type' => 'cash', 'total' => '25.00', 'user_id' => $this->ids['owner'],
        ]);

        return $id;
    }

    /** One row in $table, creating whatever parents it requires. */
    private function seedImmutableRow(string $table): void
    {
        $transaction = $this->insertTransaction($this->ids['branch'], '0000000001');

        $transactionItem = (string) Str::uuid();
        $this->db->table('transaction_items')->insert([
            'id' => $transactionItem, 'transaction_id' => $transaction, 'variant_id' => $this->ids['variant'],
            'quantity' => 1, 'selling_price' => '25.00', 'total' => '25.00',
        ]);

        $saleReturn = (string) Str::uuid();
        $this->db->table('sale_returns')->insert([
            'id' => $saleReturn, 'branch_id' => $this->ids['branch'], 'return_number' => 'R0000000001',
            'transaction_id' => $transaction, 'type' => 'return', 'total_refund' => '25.00',
            'performed_by' => $this->ids['owner'],
        ]);

        match ($table) {
            'transactions', 'transaction_items', 'sale_returns' => null,
            'sale_return_items' => $this->db->table('sale_return_items')->insert([
                'id' => (string) Str::uuid(), 'sale_return_id' => $saleReturn,
                'transaction_item_id' => $transactionItem, 'variant_id' => $this->ids['variant'],
                'quantity' => 1, 'selling_price' => '25.00', 'refund_amount' => '25.00',
            ]),
            'inventory_ledger' => $this->db->table('inventory_ledger')->insert([
                'id' => (string) Str::uuid(), 'branch_id' => $this->ids['branch'],
                'variant_id' => $this->ids['variant'], 'quantity' => 5, 'resulting_snapshot' => 5,
                'type' => 'stock_adjustment', 'reference_type' => 'manual_adjustment',
                'performed_by' => $this->ids['owner'],
            ]),
            'login_history' => $this->db->table('login_history')->insert([
                'id' => (string) Str::uuid(), 'branch_id' => $this->ids['branch'],
                'user_id' => $this->ids['owner'], 'event_type' => 'login',
            ]),
            'audit_logs' => $this->db->table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'branch_id' => $this->ids['branch'],
                'user_id' => $this->ids['owner'], 'action' => 'login',
            ]),
        };

        // Each branch above inserts exactly one row into the table under test —
        // including the parents, which are themselves immutable tables — so the
        // count-of-1 assertion holds for every case.
    }
}
