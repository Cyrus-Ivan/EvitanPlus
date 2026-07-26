<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Provisioning\Support\BranchGrants;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;
use Throwable;

/**
 * The least-privilege boundary (§7), asserted as the branch role itself.
 *
 * Pulled forward from M12 for the same reason the parity guard was pulled
 * forward from M12 into M1: the grant matrix is written once, and the moment to
 * prove it is the moment it is authored. A grant is also the kind of thing that
 * looks right and is not — which is why every assertion here runs on a
 * connection authenticated AS the branch role. An admin connection bypasses
 * grants entirely and would pass all of this vacuously.
 *
 * Refusals are attempted inside a nested transaction (a SAVEPOINT), the pattern
 * SchemaInvariantsTest established: in Postgres a failed statement poisons the
 * enclosing transaction, so without the savepoint every later assertion would
 * fail for the wrong reason.
 */
class BranchRoleGrantsTest extends TestCase
{
    use ProvisionsBusiness;

    /** A second business, to prove cross-schema isolation. */
    private static ?string $otherSchema = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$otherSchema !== null) {
            try {
                DB::connection('pgsql_admin')->statement(
                    'drop schema if exists "'.self::$otherSchema.'" cascade'
                );
            } catch (Throwable) {
            }

            self::$otherSchema = null;
        }

        self::tearDownProvisionedBusiness();

        parent::tearDownAfterClass();
    }

    public function test_the_role_lands_in_its_own_business_without_being_told(): void
    {
        $branch = $this->branch();

        $this->assertSame($this->roleName(), $branch->selectOne('select current_user as v')->v);

        // The connection was opened with NO search_path, so this can only pass
        // because of the server-side ALTER ROLE pin. If it regressed, a real
        // till would land in `public` and find nothing.
        $this->assertSame($this->schema(), $branch->selectOne('select current_schema() as v')->v);
    }

    public function test_the_role_can_read_the_seeded_business(): void
    {
        $branch = $this->branch();

        // Unqualified, exactly as the shipped app queries.
        $this->assertSame(1, (int) $branch->selectOne('select count(*) as v from users')->v);
        $this->assertSame(6, (int) $branch->selectOne('select count(*) as v from settings')->v);
        $this->assertSame('FIX', $branch->selectOne('select code from branches')->code);
        $this->assertSame(1, (int) $branch->selectOne('select schema_version as v from schema_meta')->v);
    }

    #[DataProvider('readableTables')]
    public function test_every_mirrored_table_is_readable(string $table): void
    {
        $this->assertIsNumeric(
            $this->branch()->selectOne("select count(*) as v from \"{$table}\"")->v
        );
    }

    /** @return iterable<string, array{string}> */
    public static function readableTables(): iterable
    {
        foreach (BranchGrants::SELECT as $table) {
            yield $table => [$table];
        }
    }

    public function test_it_can_append_its_own_history_and_maintain_its_own_state(): void
    {
        $branch = $this->branch();
        $branchId = (string) $this->provisioned()['branch']['id'];

        // The heartbeat row (§14): inserted once, then updated every cycle.
        $this->assertAllowed(fn () => $branch->table('branch_status')->insert([
            'branch_id' => $branchId,
            'app_version' => '1.0.0',
            'schema_version' => 1,
            'unsynced_count' => 0,
            'connection_state' => 'online',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertAllowed(fn () => $branch->table('branch_status')
            ->where('branch_id', $branchId)
            ->update(['unsynced_count' => 4, 'last_seen_at' => now()]));

        // Login history is a local write that syncs upward (§5.2).
        $this->assertAllowed(fn () => $branch->table('login_history')->insert([
            'id' => (string) Str::orderedUuid(),
            'branch_id' => $branchId,
            'user_id' => $branch->selectOne('select id from users')->id,
            'event_type' => 'login',
            'occurred_at' => now(),
            'created_at' => now(),
        ]));
    }

    /**
     * The grant M8 depends on, and the reason it is four columns rather than the
     * one §7 names: `inventory_update_queue_resolved_at` requires resolved_at to
     * move in the same statement as status, so a status-only grant could never
     * resolve a queue row at all.
     */
    public function test_it_can_resolve_a_queued_adjustment_but_not_rewrite_one(): void
    {
        $branch = $this->branch();
        $queued = $this->seedQueuedAdjustment();

        $this->assertAllowed(fn () => $branch->table('inventory_update_queue')
            ->where('id', $queued)
            ->update([
                'status' => 'rejected',
                'resolved_at' => now(),
                'rejection_reason' => 'Would drive stock negative.',
            ]));

        $this->assertSame(
            'rejected',
            $branch->selectOne('select status from inventory_update_queue where id = ?', [$queued])->status,
        );

        // The columns it must never touch: what was adjusted, by how much, for
        // whom. Only the resolution is the branch's business.
        $this->assertRefused(fn () => $branch->table('inventory_update_queue')
            ->where('id', $queued)
            ->update(['quantity' => 0]));

        $this->assertRefused(fn () => $branch->table('inventory_update_queue')
            ->where('id', $queued)
            ->update(['notes' => 'tampered']));
    }

    #[DataProvider('forbiddenStatements')]
    public function test_the_role_is_refused(string $sql): void
    {
        $this->assertRefused(fn () => $this->branch()->statement($sql));
    }

    /**
     * Every statement a leaked branch credential must not be able to run.
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenStatements(): iterable
    {
        // Immutable history: the DB grants say no, independently of the triggers
        // and of the app's model guards.
        yield 'delete transactions' => ['delete from transactions'];
        yield 'delete inventory_ledger' => ['delete from inventory_ledger'];
        yield 'delete audit_logs' => ['delete from audit_logs'];
        yield 'delete login_history' => ['delete from login_history'];
        // Not even its own mutable state may be deleted — there is no DELETE
        // anywhere in the matrix.
        yield 'delete branch_status' => ['delete from branch_status'];
        yield 'delete stock_snapshots' => ['delete from stock_snapshots'];
        yield 'truncate transactions' => ['truncate transactions'];

        // Online-authoritative data arrives by pull and is never written locally.
        yield 'update users' => ["update users set name = 'x'"];
        yield 'update settings' => ["update settings set value = 'x'"];
        yield 'update items' => ["update items set name = 'x'"];
        yield 'update variants' => ['update variants set selling_price = 1'];
        yield 'update branches' => ["update branches set code = 'ZZZ'"];
        yield 'update schema_meta' => ['update schema_meta set min_app_version = 0'];
        yield 'insert users' => [
            "insert into users (id, username, password, role) values (gen_random_uuid(), 'x', 'x', 'admin')",
        ];
        yield 'insert settings' => ["insert into settings (id, key) values (gen_random_uuid(), 'x')"];
        yield 'insert branches' => [
            "insert into branches (id, code, name) values (gen_random_uuid(), 'ZZZ', 'x')",
        ];
        yield 'insert items' => ["insert into items (id, name) values (gen_random_uuid(), 'x')"];

        // The migration ledger is console territory.
        yield 'select migrations' => ['select count(*) from migrations'];

        // No DDL, at all.
        yield 'create table' => ['create table grant_probe (id int)'];
        yield 'drop table' => ['drop table settings'];
        yield 'alter table' => ['alter table settings add column probe int'];
        yield 'create schema' => ['create schema grant_probe'];
        yield 'create index' => ['create index grant_probe on settings (key)'];

        // No escalation.
        yield 'create role' => ['create role grant_probe'];
        yield 'set role postgres' => ['set role postgres'];
    }

    public function test_it_cannot_reach_another_business(): void
    {
        $other = $this->otherBusinessSchema();
        $branch = $this->branch();

        $this->assertRefused(fn () => $branch->select("select * from \"{$other}\".probe"));

        // information_schema is privilege-filtered, so another business's shape
        // is invisible too, not merely unreadable.
        $this->assertSame(
            0,
            (int) $branch->selectOne(
                'select count(*) as v from information_schema.columns where table_schema = ?',
                [$other],
            )->v,
        );
    }

    /**
     * The honest limits of what grants can do, asserted so nobody later
     * "fixes" a passing test into a claim that is not true.
     */
    public function test_the_boundary_is_exactly_as_wide_as_documented(): void
    {
        $branch = $this->branch();

        // Cannot create anything anywhere it can see.
        $this->assertFalse((bool) $branch->selectOne(
            "select has_schema_privilege(current_user, 'public', 'create') as v"
        )->v);
        $this->assertFalse((bool) $branch->selectOne(
            'select has_schema_privilege(current_user, ?, \'create\') as v', [$this->schema()]
        )->v);

        // But pg_catalog does list other schemas' NAMES. That is metadata, not
        // data, and it cannot be revoked — documenting it beats pretending.
        $this->assertGreaterThan(0, (int) $branch->selectOne(
            "select count(*) as v from pg_namespace where nspname like 'biz\_%'"
        )->v);
    }

    /**
     * The live grants must equal BranchGrants exactly — so a privilege added by
     * hand, or a table a future migration adds and nobody grants, fails here.
     */
    public function test_the_live_grants_match_the_documented_matrix(): void
    {
        $rows = DB::connection('pgsql_admin')->select(
            'select table_name, privilege_type
             from information_schema.table_privileges
             where grantee = ? and table_schema = ?',
            [$this->roleName(), $this->schema()],
        );

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->privilege_type][] = $row->table_name;
        }

        foreach (['SELECT' => BranchGrants::SELECT,
            'INSERT' => BranchGrants::INSERT,
            'UPDATE' => BranchGrants::UPDATE] as $privilege => $expected) {
            $granted = $actual[$privilege] ?? [];
            sort($granted);
            $expected = array_values($expected);
            sort($expected);

            $this->assertSame($expected, $granted, "The live {$privilege} grants differ from BranchGrants.");
        }

        // Nothing beyond the three privileges above is granted at table level.
        $this->assertSame(
            ['INSERT', 'SELECT', 'UPDATE'],
            collect(array_keys($actual))->sort()->values()->all(),
        );

        // Column-level UPDATE is exactly the documented resolution columns.
        foreach (BranchGrants::UPDATE_COLUMNS as $table => $columns) {
            $granted = collect(DB::connection('pgsql_admin')->select(
                'select column_name
                 from information_schema.column_privileges
                 where grantee = ? and table_schema = ? and table_name = ? and privilege_type = \'UPDATE\'',
                [$this->roleName(), $this->schema(), $table],
            ))->pluck('column_name')->sort()->values()->all();

            $expected = $columns;
            sort($expected);

            $this->assertSame($expected, $granted, "Column UPDATE grants on {$table} differ from BranchGrants.");
        }
    }

    /**
     * An admin-committed pending queue row for the branch to resolve, which is
     * exactly how M8's flow begins (issued online, resolved at the branch).
     */
    private function seedQueuedAdjustment(): string
    {
        $business = SchemaConnection::pin($this->schema());

        $categoryId = (string) Str::orderedUuid();
        $itemId = (string) Str::orderedUuid();
        $variantId = (string) Str::orderedUuid();
        $queueId = (string) Str::orderedUuid();
        $now = now();

        $business->table('categories')->insert([
            'id' => $categoryId, 'name' => 'Queue Fixture', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $business->table('items')->insert([
            'id' => $itemId, 'category_id' => $categoryId, 'name' => 'Queue Fixture Item',
            'unit' => 'pc', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $business->table('variants')->insert([
            'id' => $variantId, 'item_id' => $itemId, 'sku' => 'QF-0001', 'selling_price' => 10,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $business->table('inventory_update_queue')->insert([
            'id' => $queueId,
            'branch_id' => $this->provisioned()['branch']['id'],
            'variant_id' => $variantId,
            'quantity' => -5,
            'status' => 'pending',
            'issued_by' => $business->selectOne('select id from users')->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $queueId;
    }

    /** A second, deliberately unmigrated schema — one table is enough to probe. */
    private function otherBusinessSchema(): string
    {
        if (self::$otherSchema === null) {
            $name = SchemaName::forBusiness((string) Str::uuid());

            DB::connection('pgsql_admin')->statement("create schema \"{$name}\"");
            DB::connection('pgsql_admin')->statement("create table \"{$name}\".probe (id int)");

            self::$otherSchema = $name;
        }

        return self::$otherSchema;
    }

    /**
     * Assert a statement is refused, inside a SAVEPOINT so the failure does not
     * poison the surrounding session.
     */
    private function assertRefused(callable $attempt): void
    {
        try {
            $this->branch()->transaction($attempt);
        } catch (Throwable $e) {
            $this->assertMatchesRegularExpression(
                '/permission denied|must be owner|denied to (set role|create role)/i',
                $e->getMessage(),
                'Refused, but not by the privilege system: '.$e->getMessage(),
            );

            return;
        }

        $this->fail('The branch role was ALLOWED an operation the grant matrix forbids.');
    }

    private function assertAllowed(callable $attempt): void
    {
        try {
            $this->branch()->transaction($attempt);
        } catch (Throwable $e) {
            $this->fail('The branch role was refused an operation it needs: '.$e->getMessage());
        }

        // "It did not throw" is the assertion; count it so a test made entirely
        // of these is not reported as assertion-free.
        $this->addToAssertionCount(1);
    }
}
