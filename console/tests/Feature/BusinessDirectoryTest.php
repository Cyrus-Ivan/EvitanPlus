<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Provisioning\Support\BranchDirectory;
use Provisioning\Support\BranchState;
use Provisioning\Support\BusinessDirectory;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\SchemaName;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;
use Throwable;

/**
 * The reads the menu navigates by (console:run).
 *
 * There is no businesses registry — deliberately — so "which businesses exist"
 * is derived by enumerating biz_* schemas and trusting each one's own rows. That
 * derivation is the part with edges: a schema that is not a provisioned
 * business, and a schema whose name encodes no business UUID, both turn up in a
 * real project and neither may throw.
 */
class BusinessDirectoryTest extends TestCase
{
    use ProvisionsBusiness;

    /** Bare biz_ schemas this class created, dropped together at the end. */
    private static array $scratchSchemas = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$scratchSchemas as $schema) {
            try {
                DB::connection('pgsql_admin')->statement('drop schema if exists "'.$schema.'" cascade');
            } catch (Throwable) {
            }
        }

        self::$scratchSchemas = [];

        self::tearDownProvisionedBusiness();

        parent::tearDownAfterClass();
    }

    public function test_it_lists_a_provisioned_business_with_its_name_and_branch_count(): void
    {
        $schema = $this->schema();

        $business = $this->find($schema);

        $this->assertNotNull($business, "[{$schema}] was not enumerated.");
        $this->assertTrue($business['provisioned']);
        $this->assertSame('Fixture Business', $business['name']);
        $this->assertSame(1, $business['branches']);
        $this->assertSame(SchemaName::toBusinessUuid($schema), $business['business_id']);
    }

    /**
     * The menu's business screen degrades to "not provisioned" rather than
     * erroring, so the one command that can fix it stays reachable.
     */
    public function test_an_empty_biz_schema_is_listed_as_not_provisioned(): void
    {
        $schema = $this->scratchSchema();

        $business = $this->find($schema);

        $this->assertNotNull($business, "[{$schema}] was not enumerated.");
        $this->assertFalse($business['provisioned']);
        $this->assertSame('', $business['name']);
        $this->assertSame(0, $business['branches']);
    }

    /**
     * The suite's own biz_test_* fixtures satisfy SchemaName's pattern without
     * being UUID-derived, so toBusinessUuid() throws for them. Null is the
     * honest answer — such a schema is targetable with --schema, never with
     * --business — and it must not take the whole listing down.
     */
    public function test_a_schema_that_encodes_no_uuid_gets_a_null_business_id(): void
    {
        $business = $this->find($this->scratchSchema());

        $this->assertNotNull($business);
        $this->assertNull($business['business_id']);
    }

    public function test_it_reports_a_provisioned_schemas_version(): void
    {
        $version = BusinessDirectory::version($this->schema());

        $this->assertNotNull($version);
        $this->assertNotSame('', $version['schema_version']);
        $this->assertNotSame('', $version['min_app_version']);
    }

    /** An unreadable schema_meta is something the screen shows, not something it throws on. */
    public function test_a_schema_with_no_meta_reports_no_version(): void
    {
        $this->assertNull(BusinessDirectory::version($this->scratchSchema()));
    }

    /**
     * The state words the menu and branch:list share, on the one branch every
     * business has: the first one, created by business:provision.
     *
     * The generation reads 1, and that is the fix. BusinessProvisioner used to
     * create the role and write the JSON without ever calling
     * CredentialIssuance::record — only BranchIssuer did — so a first branch
     * held a live credential the ledger had no row for. It reported "active"
     * and "never issued" at the same time, which the runbook says means no
     * credential was ever issued, and its first ROTATION was numbered
     * generation 1, making generation 1 mean two different secrets for that
     * branch permanently.
     */
    public function test_branch_state_describes_a_freshly_provisioned_branch(): void
    {
        $states = BranchState::forSchema($this->schema(), new CredentialIssuance);

        $this->assertCount(1, $states);
        $this->assertSame('FIX', $states[0]->code());
        $this->assertSame('active', $states[0]->credential);
        $this->assertSame($this->roleName(), $states[0]->role);
        $this->assertTrue($states[0]->canLogin);
        $this->assertSame('1', $states[0]->generation);
    }

    /**
     * A migrated schema with no branches is a provisioned business schema that
     * simply has no branches yet — which is exactly the state branch:provision
     * exists to leave, and used to be a state nothing could reach.
     *
     * isProvisionedBusiness required a `branches` ROW, so such a schema was
     * "not a business" to every caller: BranchIssuer refused to add the first
     * branch, business:provision refused it as create-only, and console:run
     * looped offering an upgrade that never changed the answer. The only exit
     * was a hand-written DROP SCHEMA.
     */
    public function test_a_migrated_schema_with_no_branches_is_still_a_business(): void
    {
        // The two things the predicate actually reads, and nothing else — a
        // full 19-migration run would cost ~15s to prove the same point.
        $schema = $this->scratchSchema();
        $admin = DB::connection('pgsql_admin');

        $admin->statement("create table \"{$schema}\".branches (id uuid primary key, code text)");
        $admin->statement("create table \"{$schema}\".schema_meta (schema_version int, min_app_version text)");
        $admin->statement("insert into \"{$schema}\".schema_meta values (1, '1.0.0')");

        $this->assertTrue(BranchDirectory::isProvisionedBusiness($schema));
    }

    /** An unmigrated biz_ schema is still refused — that is what the check is for. */
    public function test_an_empty_schema_is_not_a_business(): void
    {
        $this->assertFalse(BranchDirectory::isProvisionedBusiness($this->scratchSchema()));
    }

    /**
     * @return array{schema: string, business_id: string|null, name: string, branches: int, provisioned: bool}|null
     */
    private function find(string $schema): ?array
    {
        foreach (BusinessDirectory::all() as $business) {
            if ($business['schema'] === $schema) {
                return $business;
            }
        }

        return null;
    }

    /**
     * A fresh, bare biz_ schema. One per call rather than one per class: two of
     * these tests build different things inside theirs, and sharing would make
     * the result depend on the order PHPUnit happens to run them in.
     */
    private function scratchSchema(): string
    {
        $schema = 'biz_scratch_'.Str::lower(Str::random(12));

        DB::connection('pgsql_admin')->statement('create schema "'.$schema.'"');

        self::$scratchSchemas[] = $schema;

        return $schema;
    }
}
