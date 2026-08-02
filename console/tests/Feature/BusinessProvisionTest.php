<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Provisioning\Support\BranchCredentials;
use Provisioning\Support\BranchSecret;
use Provisioning\Support\BusinessProvisioner;
use Provisioning\Support\BusinessSettingKeys;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaVersion;
use Provisioning\Support\SupabaseEndpoint;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;

/**
 * The M2 milestone (MULTIBRANCH-ROADMAP.md): one command yields a populated
 * schema, a least-privilege branch role, and a JSON a pgsql client can use to
 * connect and read the seeded data.
 *
 * One provision serves every assertion here — see ProvisionsBusiness for why.
 * The grant boundary itself is asserted separately in BranchRoleGrantsTest.
 */
class BusinessProvisionTest extends TestCase
{
    use ProvisionsBusiness;

    public static function tearDownAfterClass(): void
    {
        self::tearDownProvisionedBusiness();

        parent::tearDownAfterClass();
    }

    public function test_it_creates_and_fully_migrates_the_business_schema(): void
    {
        $this->assertTrue(SchemaConnection::exists($this->schema()));

        $business = SchemaConnection::pin($this->schema());

        // Against the constants, never literals: SchemaVersion is the single
        // declaration, and a test pinning the numbers a second time would fail
        // on every legitimate bump while proving nothing extra.
        $meta = $business->selectOne('select schema_version, min_app_version from schema_meta');
        $this->assertSame(SchemaVersion::SCHEMA_VERSION, (int) $meta->schema_version);
        $this->assertSame(SchemaVersion::MIN_APP_VERSION, $meta->min_app_version);

        // Every migration, not just enough of them to seed — and SCHEMA_VERSION
        // IS that count, which SchemaVersionTest keeps true.
        $applied = (int) $business->selectOne('select count(*) as total from migrations')->total;
        $this->assertSame(SchemaVersion::SCHEMA_VERSION, $applied);
    }

    public function test_it_seeds_exactly_one_owner_whose_password_the_app_can_verify(): void
    {
        $users = SchemaConnection::pin($this->schema())->table('users')->get();

        $this->assertCount(1, $users);
        $this->assertSame('owner', $users[0]->role);
        $this->assertSame('fixture_owner', $users[0]->username);
        $this->assertSame('Fixture Owner', $users[0]->name);
        $this->assertNull($users[0]->deleted_at);

        // must_change_password stays false: the developer sets the password
        // during provisioning and hands it over, so there is nothing to force.
        $this->assertFalse((bool) $users[0]->must_change_password);

        // The whole point of hashing here rather than in the app: the shipped
        // app's 'password' => 'hashed' cast must be able to verify this.
        $this->assertTrue(password_verify(self::OWNER_PASSWORD, $users[0]->password));
    }

    public function test_it_seeds_every_business_setting_as_a_string(): void
    {
        $settings = SchemaConnection::pin($this->schema())
            ->table('settings')
            ->pluck('value', 'key')
            ->all();

        $keys = array_keys($settings);
        $expected = BusinessSettingKeys::KEYS;
        sort($keys);
        sort($expected);
        $this->assertSame($expected, $keys, 'Exactly the app-recognised setting keys must be seeded.');

        $this->assertSame('Fixture Business', $settings['business_name']);
        $this->assertSame('Asia/Manila', $settings['timezone']);
        $this->assertSame('₱', $settings['currency_symbol']);
        $this->assertSame('000-111-222-333', $settings['tin']);

        foreach (BusinessSettingKeys::KEYS as $key) {
            $this->assertArrayHasKey($key, $settings);
            // Never null: the app applies read-time defaults, so a null would
            // surface as the default instead of the chosen value.
            $this->assertIsString($settings[$key]);
        }
    }

    public function test_it_seeds_the_first_branch_with_a_canonical_code(): void
    {
        $branches = SchemaConnection::pin($this->schema())->table('branches')->get();

        $this->assertCount(1, $branches);
        // Provisioned as 'fix'; the receipt prefix must have one spelling.
        $this->assertSame('FIX', $branches[0]->code);
        $this->assertSame('Fixture Branch', $branches[0]->name);
        $this->assertNull($branches[0]->disabled_at);
        // timestampsTz has no database default and nothing here goes through
        // Eloquent, so these prove the seed set them explicitly.
        $this->assertNotNull($branches[0]->created_at);
        $this->assertNotNull($branches[0]->updated_at);
    }

    public function test_it_does_not_seed_rows_that_belong_to_later_phases(): void
    {
        $business = SchemaConnection::pin($this->schema());

        // branch_status is upserted by the till on its first heartbeat (M10).
        $this->assertSame(0, (int) $business->selectOne('select count(*) as t from branch_status')->t);
        // No stock exists until a ledger entry says so.
        $this->assertSame(0, (int) $business->selectOne('select count(*) as t from stock_snapshots')->t);
        // The migration owns the singleton; a second insert would be rejected.
        $this->assertSame(1, (int) $business->selectOne('select count(*) as t from schema_meta')->t);
    }

    public function test_it_creates_a_login_role_with_no_cluster_powers(): void
    {
        $role = DB::connection('pgsql_admin')->selectOne(
            'select rolcanlogin, rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls, rolinherit, rolconfig
             from pg_roles where rolname = ?',
            [$this->roleName()],
        );

        $this->assertNotNull($role, 'The branch role was not created.');
        $this->assertTrue((bool) $role->rolcanlogin);

        foreach (['rolsuper', 'rolcreaterole', 'rolcreatedb', 'rolreplication', 'rolbypassrls', 'rolinherit'] as $attribute) {
            $this->assertFalse((bool) $role->{$attribute}, "{$attribute} must be off for a branch role.");
        }

        // The search_path pin lives on the ROLE, so a client that never sets it
        // still lands in the right business (§7).
        $this->assertStringContainsString('search_path='.$this->schema(), (string) $role->rolconfig);
    }

    public function test_it_writes_a_private_credentials_json_that_matches_the_database(): void
    {
        $path = $this->credentialsPath();
        $document = $this->provisioned();

        // The file holds a live credential: owner-only, always.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $this->assertSame(BranchCredentials::FORMAT, $document['format']);
        $this->assertSame(BranchCredentials::FORMAT_VERSION, $document['format_version']);

        $this->assertSame('FIX', $document['branch']['code']);
        $this->assertSame('Fixture Business', $document['business']['name']);
        $this->assertSame($this->schema(), $document['business']['schema']);

        // NULL, not the schema name: the pin is a role default applied by
        // Postgres at backend startup, which is what survives transaction
        // pooling. Substituting business.schema here would reintroduce a
        // client-side `SET search_path` that lands on a backend Supavisor is
        // about to hand back. M5's importer must assign this as-is.
        $this->assertNull($document['connection']['search_path']);

        // The JSON carries the endpoint the console would ISSUE, not the one it
        // administers through. Deriving the expectation from forBranch() is the
        // point: what this proves is that document() was built from the branch
        // endpoint rather than from pgsql_admin — the exact regression M3.1
        // fixed. WHICH port that is, and that it is never the admin's, is
        // settled deterministically in Tests\Unit\SupabaseEndpointTest, where
        // every configuration is reachable in one run.
        $endpoint = SupabaseEndpoint::forBranch();

        $this->assertSame($endpoint->host, $document['connection']['host']);
        $this->assertSame($endpoint->port, (int) $document['connection']['port']);
        $this->assertSame($endpoint->database, $document['connection']['database']);
        $this->assertSame($endpoint->sslmode, $document['connection']['sslmode']);

        // TLS must never silently downgrade. config/database.php warns that
        // 'prefer' does exactly that, and nothing enforced it.
        $this->assertContains(
            $document['connection']['sslmode'],
            ['require', 'verify-ca', 'verify-full'],
            'A branch credential must not ship a downgradeable TLS mode.',
        );
        $this->assertSame(SchemaVersion::SCHEMA_VERSION, $document['versions']['schema_version']);
        $this->assertSame(SchemaVersion::MIN_APP_VERSION, $document['versions']['min_app_version']);

        // Key-for-key the shipped app's pgsql connection, so M5 can assign it.
        $this->assertSame(
            ['driver', 'host', 'port', 'database', 'username', 'password', 'search_path', 'sslmode'],
            array_keys($document['connection']),
        );

        // The bare role and the login name are both present because a pooler
        // endpoint makes them differ. Asserting the EXACT login name, not a
        // prefix: assertStringStartsWith passed with or without the pooler
        // suffix, and a missing suffix is the one thing that can be wrong here —
        // it authenticates nowhere.
        $this->assertSame($this->roleName(), $document['role']['name']);
        $this->assertSame(
            $endpoint->loginName($this->roleName()),
            $document['connection']['username'],
        );

        // The ids in the JSON must be the rows that exist.
        $business = SchemaConnection::pin($this->schema());
        $this->assertSame(
            $document['branch']['id'],
            (string) $business->selectOne('select id from branches')->id,
        );
    }

    /**
     * The pooling-mode claim, against whatever project this suite is pointed at.
     *
     * States its precondition instead of asserting a falsehood. An earlier
     * version asserted unconditionally that the branch port differs from the
     * admin's — which is wrong for a legitimate configuration: with no Supavisor
     * in the path (a direct db.<ref>.supabase.co host, or a local Postgres)
     * there is no 6543 to move to and forBranch() inherits the admin port BY
     * DESIGN, so the assertion failed a correct setup.
     *
     * Both branches of that are proven deterministically in
     * Tests\Unit\SupabaseEndpointTest. This one exists to confirm the project
     * the developer is actually provisioning against is configured the way the
     * initiative intends.
     */
    public function test_a_branch_is_never_issued_the_console_admin_session_endpoint(): void
    {
        $endpoint = SupabaseEndpoint::forBranch();

        if ($endpoint->projectRef === null) {
            $this->markTestSkipped(
                'No pooler tenant on this project; the branch endpoint legitimately inherits the admin port.'
            );
        }

        if (trim((string) env('CONSOLE_BRANCH_DB_PORT')) !== '') {
            $this->markTestSkipped('CONSOLE_BRANCH_DB_PORT is set explicitly; the operator chose this port.');
        }

        $port = (int) $this->provisioned()['connection']['port'];

        $this->assertSame(SupabaseEndpoint::TRANSACTION_POOLER_PORT, $port);
        $this->assertNotSame((int) config('database.connections.pgsql_admin.port'), $port);
    }

    public function test_the_credentials_json_never_carries_admin_credentials(): void
    {
        $raw = (string) file_get_contents($this->credentialsPath());

        // §18: admin credentials never leave console/.env.
        foreach ([config('database.connections.pgsql_admin.password'),
            config('database.connections.pgsql_admin.username')] as $secret) {
            $this->assertIsString($secret);
            $this->assertStringNotContainsString($secret, $raw);
        }

        // Nor the Owner's password: it is not needed to connect, and §9 does not
        // list it.
        $this->assertStringNotContainsString(self::OWNER_PASSWORD, $raw);
    }

    public function test_it_records_one_successful_audit_entry(): void
    {
        // Filtered by action since M9: business:provision calls schema:migrate,
        // which now writes its own `schema.migrate` row for the same schema.
        // An unfiltered ->first() has no ORDER BY, so Postgres was free to hand
        // back either — and did.
        $entry = SchemaConnection::pinConsole()
            ->table('provisioning_audit')
            ->where('schema_name', $this->schema())
            ->where('action', BusinessProvisioner::ACTION)
            ->first();

        $this->assertNotNull($entry, 'The provision was not audited.');
        $this->assertSame(BusinessProvisioner::ACTION, $entry->action);
        $this->assertSame(ProvisioningAudit::SUCCEEDED, $entry->outcome);
        $this->assertSame('FIX', $entry->branch_code);
        $this->assertSame($this->roleName(), $entry->role_name);
        $this->assertNotEmpty($entry->actor);

        // The trail must never become a place secrets accumulate.
        $this->assertStringNotContainsString(
            (string) $this->provisioned()['connection']['password'],
            (string) $entry->detail,
        );
        $this->assertStringNotContainsString(self::OWNER_PASSWORD, (string) $entry->detail);
    }

    /**
     * The first branch's credential is on the issuance ledger, as generation 1.
     *
     * It used not to be: only BranchIssuer recorded, so every business's first
     * branch held a live, verified, delivered credential the ledger had no row
     * for. branch:list reported it as `active` and `never issued` at the same
     * time — and per the runbook the second of those means no credential was
     * ever issued, so the operator's next move would be to issue one. Its first
     * ROTATION then recorded generation 1, making generation 1 name two
     * different secrets for that branch permanently, which is exactly the
     * question this table exists to answer.
     */
    public function test_the_first_branchs_credential_is_on_the_issuance_ledger(): void
    {
        $issuance = new CredentialIssuance;
        $branchId = (string) $this->provisioned()['branch']['id'];

        $current = $issuance->current($branchId);

        $this->assertNotNull($current, 'The provisioned credential was never recorded as issued.');
        $this->assertSame(1, (int) $current->generation);
        $this->assertSame($this->roleName(), $current->role_name);
        $this->assertSame(1, $issuance->generations($branchId));
    }

    /** The fingerprint identifies the credential without ever carrying it. */
    public function test_the_recorded_fingerprint_is_not_the_password(): void
    {
        $current = (new CredentialIssuance)->current((string) $this->provisioned()['branch']['id']);
        $password = (string) $this->provisioned()['connection']['password'];

        $this->assertNotNull($current);
        $this->assertStringStartsWith('sha256:', (string) $current->fingerprint);
        $this->assertStringNotContainsString($password, (string) $current->fingerprint);
        $this->assertSame(BranchSecret::fingerprint($password), $current->fingerprint);
    }
}
