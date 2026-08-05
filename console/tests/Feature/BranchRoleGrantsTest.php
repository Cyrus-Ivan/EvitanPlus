<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Provisioning\Support\BranchConnection;
use Provisioning\Support\BranchGrants;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Provisioning\Support\SchemaVersion;
use Provisioning\Support\SupabaseEndpoint;
use Tests\Concerns\AssertsBranchGrants;
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
 *
 * This class doubles as the **transaction-pooler probe**, which is why it is the
 * one to run first after any change to the branch endpoint. Every assertion here
 * runs over `6543`, on one long-lived connection, firing many parameterised
 * queries — the exact shape that breaks if Supavisor stops tracking named
 * prepared statements, and the exact shape that would expose a `search_path` pin
 * that only worked because the client was setting it.
 */
class BranchRoleGrantsTest extends TestCase
{
    use AssertsBranchGrants;
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

    /**
     * The branch connection is the one a till will use, not the admin's.
     *
     * Otherwise invisible: every other test here would pass just as happily over
     * the console's session endpoint, and provisioning's verify() would keep
     * reporting success while proving nothing about the endpoint it ships.
     */
    public function test_the_branch_connection_uses_the_branch_endpoint(): void
    {
        $this->branch();

        /** @var array<string, mixed> $config */
        $config = config('database.connections.'.BranchConnection::NAME);

        $this->assertSame(SupabaseEndpoint::TRANSACTION_POOLER_PORT, (int) $config['port']);

        // No client-side pin: the server-side ALTER ROLE must do the work, which
        // is what the next test then proves against a live connection.
        $this->assertArrayNotHasKey('search_path', $config);

        // A CONSOLE_DB_URL would be merged over host, port AND username by
        // ConfigurationUrlParser, making this connection silently the admin —
        // and every refusal test in this class would then pass vacuously.
        $this->assertArrayNotHasKey('url', $config);
    }

    /**
     * Named prepared statements survive the transaction pooler.
     *
     * PDO_pgsql prepares server-side under a per-connection name. Under
     * transaction pooling that classically breaks — `42P05 already exists` when a
     * name collides on a reused backend, `26000 does not exist` when prepare and
     * execute land on different ones. Supavisor tracks them, so this passes; the
     * test exists so that if it ever stops tracking them, the failure arrives
     * here with a name rather than in the field as an intermittent query error.
     */
    public function test_repeating_a_parameterised_query_does_not_collide_in_the_pooler(): void
    {
        $branch = $this->branch();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(
                $this->schema(),
                $branch->selectOne('select current_schema() as v where ? = ?', ['x', 'x'])->v,
                "Repeat {$i} of an identical parameterised query failed.",
            );
        }
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
        $this->assertSame(
            SchemaVersion::SCHEMA_VERSION,
            (int) $branch->selectOne('select schema_version as v from schema_meta')->v,
        );
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
     * The M8 grant: a branch device ISSUES an adjustment against another branch.
     *
     * The credential doing it is an ordinary branch role, because there is no
     * other kind — an Owner or Admin issues these from the in-app admin screens
     * on a branch device (docs/glossary.md §8 "Issuing branch"), and the console
     * is developer-only and never ships. Until M8 nothing but the console's admin
     * connection could write this table, which made the feature unreachable from
     * the screens meant to offer it.
     *
     * The second half is the exposure that grant buys, asserted rather than
     * described: the same credential can queue a row against a branch that is NOT
     * its own, because grants stop at the schema boundary. That is an ACCEPTED
     * risk — row-level security is deliberately not planned (docs/glossary.md
     * §9) — so this assertion is the executable record of a decision, not of a
     * pending fix. A test that pretended otherwise would be worse than none.
     */
    public function test_it_can_issue_a_queued_adjustment_for_any_branch_of_its_business(): void
    {
        $branch = $this->branch();
        $variantId = $this->seedQueueFixtureVariant();
        $userId = $branch->selectOne('select id from users')->id;

        $issue = fn (string $branchId): bool => $branch->table('inventory_update_queue')->insert([
            'id' => (string) Str::orderedUuid(),
            'branch_id' => $branchId,
            'variant_id' => $variantId,
            'quantity' => -3,
            'status' => 'pending',
            'issued_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertAllowed(fn () => $issue($this->provisioned()['branch']['id']));

        // Another branch of the SAME business. Allowed, knowingly: this is the
        // accepted cross-branch reach, not an accident, and it is bounded — the
        // row is only ever `pending`, and the target branch is what decides
        // whether it becomes a ledger entry at all.
        $other = (string) Str::orderedUuid();
        SchemaConnection::pin($this->schema())->table('branches')->insert([
            'id' => $other, 'code' => 'GAP', 'name' => 'Other Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertAllowed(fn () => $issue($other));

        // What it still cannot do to this table.
        $this->assertRefused(fn () => $branch->table('inventory_update_queue')->delete());
    }

    /**
     * The grant resolving a queue row depends on, and the reason it is six
     * columns rather than the one §7 names.
     *
     * Two constraints force the shape. `inventory_update_queue_resolved_at`
     * requires resolved_at to move in the same statement as status, so a
     * status-only grant could never resolve a row at all. And since schema 21 a
     * PERSON answers these rather than a sync cycle, so a resolution also records
     * how much was actually agreed (`approved_quantity`, which is the whole of
     * how a partial approval is stored) and who agreed it (`resolved_by`, which
     * is a different user from `issued_by`).
     */
    public function test_it_can_resolve_a_queued_adjustment_but_not_rewrite_one(): void
    {
        $branch = $this->branch();
        $queued = $this->seedQueuedAdjustment();
        $resolver = (string) SchemaConnection::pin($this->schema())->selectOne('select id from users')->id;

        // A PARTIAL approval, which is the case that needs all six: agreed to 2
        // of the 3 that were asked for, and said why.
        $this->assertAllowed(fn () => $branch->table('inventory_update_queue')
            ->where('id', $queued)
            ->update([
                'status' => 'accepted',
                'resolved_at' => now(),
                'resolution_reason' => 'Only 2 of the 3 arrived intact.',
                'approved_quantity' => 2,
                'resolved_by' => $resolver,
                'ledger_entry_id' => $queued,
            ]));

        $row = $branch->selectOne(
            'select status, approved_quantity from inventory_update_queue where id = ?',
            [$queued],
        );

        $this->assertSame('accepted', $row->status);
        $this->assertSame(2, (int) $row->approved_quantity);

        // The columns it must never touch: what was adjusted, by how much, for
        // whom. Only the resolution is the branch's business — a branch that
        // could rewrite `quantity` could approve itself more than it was sent.
        $this->assertRefused(fn () => $branch->table('inventory_update_queue')
            ->where('id', $queued)
            ->update(['quantity' => 0]));

        $this->assertRefused(fn () => $branch->table('inventory_update_queue')
            ->where('id', $queued)
            ->update(['notes' => 'tampered']));

        // And it may not aim a row somewhere else, which the new columns make
        // worth stating: a transfer's counterparty decides where stock lands.
        $this->assertRefused(fn () => $branch->table('inventory_update_queue')
            ->where('id', $queued)
            ->update(['counterparty_branch_id' => null]));
    }

    /**
     * The M6.5 grants: shared data, written from a branch device while online.
     *
     * §5.1 always said these tables are "edited only online (Provisioning
     * Console or in-app admin while online)" and §6 said users are "managed
     * online (in-app admin, when connected)". The in-app admin runs on a branch
     * device and authenticates as this role, so until M6.5 those sentences
     * described operations the credential could not perform — and the app
     * quietly wrote them to local SQLite instead, where no other branch would
     * ever see them.
     *
     * Asserted as real statements rather than as matrix membership because a
     * grant is the kind of thing that looks right and is not; the matrix
     * equality test above would pass on a matrix that was wrong.
     */
    public function test_it_can_write_shared_data_online(): void
    {
        $branch = $this->branch();
        $categoryId = (string) Str::orderedUuid();
        $itemId = (string) Str::orderedUuid();

        $this->assertAllowed(fn () => $branch->table('categories')->insert([
            'id' => $categoryId, 'name' => 'Grants Probe', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->assertAllowed(fn () => $branch->table('items')->insert([
            'id' => $itemId, 'name' => 'Grants Probe Item', 'unit' => 'pc',
            'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->assertAllowed(fn () => $branch->table('variants')->insert([
            'id' => (string) Str::orderedUuid(), 'item_id' => $itemId, 'sku' => 'GRANTPROBE1',
            'selling_price' => 10, 'cost' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]));

        // A price change from a till — the edit that reaches every branch.
        $this->assertAllowed(fn () => $branch->table('variants')
            ->where('item_id', $itemId)->update(['selling_price' => 12]));

        // Archiving is an UPDATE, which is why withholding DELETE costs nothing.
        $this->assertAllowed(fn () => $branch->table('items')
            ->where('id', $itemId)->update(['archived_at' => now()]));

        $this->assertAllowed(fn () => $branch->table('users')->insert([
            'id' => (string) Str::orderedUuid(), 'name' => 'Grants Probe', 'username' => 'grants-probe',
            'password' => '$2y$04$abcdefghijklmnopqrstuv', 'role' => 'cashier',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        // Role changes and soft deletes, both UPDATEs.
        $this->assertAllowed(fn () => $branch->table('users')
            ->where('username', 'grants-probe')->update(['role' => 'admin']));

        $this->assertAllowed(fn () => $branch->table('users')
            ->where('username', 'grants-probe')->update(['deleted_at' => now()]));

        $this->assertAllowed(fn () => $branch->table('settings')
            ->where('key', 'business_name')->update(['value' => 'Renamed From A Till']));

        $this->assertAllowed(fn () => $branch->table('import_history')->insert([
            'id' => (string) Str::orderedUuid(),
            'branch_id' => (string) $this->provisioned()['branch']['id'],
            'user_id' => $branch->selectOne('select id from users where deleted_at is null limit 1')->id,
            'filename' => 'probe.csv', 'mode' => 'replace_stock_count', 'status' => 'success',
            'total_rows' => 0, 'success_count' => 0, 'error_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]));
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

        // Provisioning and version territory: a branch may not invent branches,
        // rename itself, or move the floor the version gate compares against.
        // These four stayed refused when M6.5 opened up the catalog, and the
        // distinction is the point — "online-authoritative" is not one bucket.
        yield 'update branches' => ["update branches set code = 'ZZZ'"];
        yield 'update schema_meta' => ['update schema_meta set min_app_version = 0'];
        yield 'insert branches' => [
            "insert into branches (id, code, name) values (gen_random_uuid(), 'ZZZ', 'x')",
        ];
        yield 'insert schema_meta' => [
            'insert into schema_meta (id, schema_version, min_app_version) values (2, 1, 1)',
        ];

        // Catalog, users and settings are NOT here any more — see
        // test_it_can_write_shared_data_online(). Deleting them still is.
        yield 'delete users' => ['delete from users'];
        yield 'delete items' => ['delete from items'];
        yield 'delete categories' => ['delete from categories'];
        yield 'delete variants' => ['delete from variants'];
        yield 'delete settings' => ['delete from settings'];
        yield 'delete import_history' => ['delete from import_history'];

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
        $this->assertGrantsMatchMatrix($this->roleName(), $this->schema());
    }

    /**
     * An admin-committed pending queue row for the branch to resolve, which is
     * exactly how M8's flow begins (issued online, resolved at the branch).
     */
    private function seedQueuedAdjustment(): string
    {
        $business = SchemaConnection::pin($this->schema());

        $variantId = $this->seedQueueFixtureVariant();
        $queueId = (string) Str::orderedUuid();
        $now = now();

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

    /**
     * A sellable variant to hang queue fixtures off. Names and SKU are unique per
     * call: the schema outlives each test, so a fixed SKU would collide with the
     * unique index the second time a test asked for one.
     */
    private function seedQueueFixtureVariant(): string
    {
        $business = SchemaConnection::pin($this->schema());

        $categoryId = (string) Str::orderedUuid();
        $itemId = (string) Str::orderedUuid();
        $variantId = (string) Str::orderedUuid();
        $suffix = strtoupper(Str::random(6));
        $now = now();

        $business->table('categories')->insert([
            'id' => $categoryId, 'name' => "Queue Fixture {$suffix}", 'created_at' => $now, 'updated_at' => $now,
        ]);
        $business->table('items')->insert([
            'id' => $itemId, 'category_id' => $categoryId, 'name' => "Queue Fixture Item {$suffix}",
            'unit' => 'pc', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $business->table('variants')->insert([
            'id' => $variantId, 'item_id' => $itemId, 'sku' => "QF-{$suffix}", 'selling_price' => 10,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $variantId;
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
