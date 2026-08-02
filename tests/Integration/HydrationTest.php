<?php

namespace Tests\Integration;

use App\Models\Instance;
use App\Models\InstanceSecret;
use App\Models\Setting;
use App\Models\User;
use App\Models\Variant;
use App\Support\Printing\PrinterSettings;
use App\Support\Settings\BusinessSettings;
use App\Support\Sync\BranchCycle;
use App\Support\Sync\CredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Concerns\ProvisionsABusiness;
use Tests\TestCase;

/**
 * The setup wizard against a REAL provisioned business (M5 milestone).
 *
 * ## Why this exists and why it is separate
 *
 * Everything about hydration that can go wrong quietly is a Postgres value
 * shape: `must_change_password` arriving as the string 'f' and storing as
 * truthy, a `timestamptz` landing in a SQLite datetime column with an offset
 * still attached, a `numeric` price becoming a float. None of those reproduce
 * against a stand-in. So this test does the whole chain for real — provision a
 * throwaway business with the console, run the actual wizard route against the
 * emitted JSON, compare the local mirror to what was provisioned, then destroy
 * the schema and the role.
 *
 * It is tagged `supabase` and excluded from `composer verify`, which must stay
 * runnable on a machine with no credentials. `composer verify:hydrate` runs it,
 * with --fail-on-skipped, so on a machine that HAS credentials a silent skip is
 * a failure — M4's lesson about a guard nothing calls being indistinguishable
 * from one that was never written.
 *
 * ## Shape
 *
 * The business is provisioned ONCE for the class (~15s against a remote
 * database) and each test runs the wizard against its own copy of the JSON on a
 * fresh local SQLite. The console is invoked as a subprocess, never as code:
 * §18 forbids a runtime reference between the two apps, and shelling out is also
 * what a developer actually does.
 *
 * Cleanup is `drop schema ... cascade` plus `drop role`, the statement pair
 * BranchRole::cleanupSql documents, issued over the admin credentials in
 * console/.env. A cancelled run leaves a schema behind; the sweep is the same
 * one the console suite documents —
 *   select schema_name from information_schema.schemata where schema_name like 'biz_%';
 */
#[Group('supabase')]
class HydrationTest extends TestCase
{
    use ProvisionsABusiness, RefreshDatabase;

    /** Every test here starts from a device that has never been set up. */
    protected bool $hydrated = false;

    private const OWNER_USERNAME = 'hydration-owner';

    private const BUSINESS_NAME = 'Hydration Test Store';

    private const BRANCH_CODE = 'HYD';

    /** @var array<string, mixed>|null The provisioned credentials document. */
    private static ?array $document = null;

    private static ?string $schema = null;

    private static ?string $role = null;

    private static ?PDO $admin = null;

    /** Catalog ids seeded into the schema as an online Owner would have. */
    private static string $categoryId = '';

    private static string $itemId = '';

    private static string $variantId = '';

    private static string $otherBranchId = '';

    public static function tearDownAfterClass(): void
    {
        self::destroyBusiness(self::$admin, self::$schema, self::$role);

        self::$admin = null;
        self::$document = null;
        self::$schema = null;
        self::$role = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $env = $this->consoleEnv();

        if (($env['CONSOLE_DB_PASSWORD'] ?? '') === '') {
            $this->markTestSkipped('No console admin credentials in console/.env — this test provisions a real business.');
        }

        $this->directory = storage_path('framework/testing/hydration');
        File::ensureDirectoryExists($this->directory);

        if (self::$document === null) {
            self::$admin = $this->adminConnection($env);
            self::$document = $this->provisionBusiness(self::BUSINESS_NAME, self::OWNER_USERNAME, self::BRANCH_CODE, 'hydration-test');
            self::$schema = self::$document['business']['schema'];
            self::$role = self::$document['role']['name'];

            $this->seedCatalog();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_hydrates_the_business_baseline_onto_a_fresh_device(): void
    {
        $this->post(route('setup.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        // The Owner the console created, not one made here.
        $owner = User::query()->where('username', self::OWNER_USERNAME)->sole();
        $this->assertSame('owner', $owner->role->value);
        $this->assertSame(self::BUSINESS_NAME.' Owner', $owner->name);

        // THE BOOLEAN TRAP: pdo_pgsql can hand back 't'/'f', and `(bool) 'f'` is
        // true. A false that arrived as 'f' would force a password change on a
        // brand-new device with nothing in the logs to explain it.
        $this->assertFalse($owner->must_change_password);
        $this->assertTrue(
            User::query()->where('username', 'hydration-cashier')->sole()->must_change_password,
            'A user flagged for a password change upstream must arrive flagged.',
        );

        // Soft-deleted users come down too: archived users must still resolve in
        // sales, login history and audit logs.
        $this->assertSame(3, User::withTrashed()->count());
        $this->assertSoftDeleted('users', ['username' => 'hydration-leaver']);

        // Business identity, all six keys.
        $this->assertSame(self::BUSINESS_NAME, Setting::query()->where('key', 'business_name')->value('value'));
        $this->assertSame('Asia/Manila', Setting::query()->where('key', 'timezone')->value('value'));
        $this->assertSame('₱', Setting::query()->where('key', 'currency_symbol')->value('value'));
        $this->assertSame(6, Setting::query()->whereIn('key', BusinessSettings::KEYS)->count());

        // Catalog, with the decimal and the nullable columns intact.
        $variant = DB::table('variants')->where('id', self::$variantId)->sole();
        $this->assertSame('HYDSKU1', $variant->sku);
        $this->assertNull($variant->barcode);
        $this->assertNull($variant->archived_at);
        $this->assertSame(self::$itemId, $variant->item_id);

        // Prices, read the way the app reads them. SQLite's NUMERIC affinity
        // stores '25.50' as the number 25.5 — it does that for a price the POS
        // itself writes too, and the `decimal:2` cast is what makes both read
        // back identically. A hydrated row must be indistinguishable from a
        // locally written one, so the assertion goes through the model rather
        // than around it.
        $model = Variant::query()->findOrFail(self::$variantId);
        $this->assertSame('25.50', $model->selling_price);
        $this->assertSame('10.00', $model->cost);
        $this->assertEqualsWithDelta(25.50, (float) $variant->selling_price, 0.001);
        $this->assertSame(self::$categoryId, DB::table('items')->where('id', self::$itemId)->value('category_id'));

        // Timestamps land as the naive strings SQLite columns hold, with no
        // offset riding along…
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $variant->created_at,
        );

        // …and in the BUSINESS timezone, not UTC. This app has stored naive
        // local time since v1.0.0 (ApplyBusinessTimezone), so a UTC string here
        // would read eight hours early on every screen in Manila — consistently
        // enough to look like a plausible date everywhere it mattered. Only
        // asserting the shape above let that through until M6's push made the
        // round trip exist and disagree with itself.
        $upstream = $this->remoteCreatedAt('variants', self::$variantId);

        $this->assertSame(
            $upstream->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s'),
            (string) $variant->created_at,
        );

        // This branch's stock only. The other branch's snapshot for the same
        // variant must not be here — the till has no branch_id column to tell
        // them apart with.
        $this->assertSame(1, DB::table('stock_snapshots')->count());
        $this->assertSame(42, (int) DB::table('stock_snapshots')->where('variant_id', self::$variantId)->value('quantity'));

        // Identity, from the document and from schema_meta.
        $instance = Instance::current();
        $this->assertNotNull($instance);
        $this->assertSame(self::BRANCH_CODE, $instance->branch_code);
        $this->assertSame(self::$schema, $instance->schema_name);
        $this->assertSame(self::$document['branch']['id'], $instance->branch_id);

        // Against what the schema actually says, not a literal. The app cannot
        // import the console's SchemaVersion — §18 forbids a runtime reference
        // either way — and a hard-coded number here would have to be edited
        // every time the migration set grows, for a test that is about
        // HYDRATION. Reading upstream also proves the stronger thing: the number
        // was copied down, rather than agreeing with a constant by coincidence.
        $this->assertSame($this->remoteSchemaVersion(), $instance->schema_version);
        $this->assertNotNull($instance->hydrated_at);
        $this->assertTrue(Instance::isHydrated());

        // History is NOT hydrated: a fresh device has none of its own, and the
        // branch's history stays where consolidated reporting reads it.
        foreach (['transactions', 'transaction_items', 'inventory_ledger', 'sale_returns', 'login_history'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "[{$table}] must not be hydrated.");
        }

        // One audit entry, actor null — nobody has signed in on this device yet.
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.setup_completed', 'user_id' => null]);

        // And the wizard is now closed.
        $this->get(route('setup'))->assertRedirect(route('login'));
    }

    public function test_the_sealed_credential_can_open_a_connection_afterwards(): void
    {
        $this->post(route('setup.store'), $this->payload())->assertSessionHasNoErrors();

        $secret = InstanceSecret::current();
        $this->assertNotNull($secret);

        // The console can match this without either side transmitting the
        // password (credential_issuance.fingerprint).
        $this->assertSame(
            (new CredentialVault)->fingerprint(self::$document['connection']['password']),
            $secret->fingerprint,
        );

        // What the stored credential is FOR: opening the next cycle. This is
        // M6's whole starting point, so it is proved here rather than assumed.
        $schema = (new BranchCycle)->run(
            (new CredentialVault)->open(),
            fn ($remote) => $remote->selectOne('select current_schema() as name')->name,
        );

        $this->assertSame(self::$schema, $schema);

        // …and the cycle left nothing open behind it.
        $this->assertArrayNotHasKey('pgsql', DB::getConnections());
    }

    public function test_hydration_is_re_runnable_from_scratch(): void
    {
        $this->post(route('setup.store'), $this->payload())->assertSessionHasNoErrors();

        $before = DB::table('variants')->where('id', self::$variantId)->sole();

        // The local mirror is the one store in the system that can be thrown
        // away, so a second run must converge rather than double up or collide.
        Instance::query()->update(['hydrated_at' => null]);

        $this->post(route('setup.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(3, User::withTrashed()->count());
        $this->assertSame(1, DB::table('variants')->count());
        $this->assertSame(1, DB::table('instance')->count());
        $this->assertEquals($before, DB::table('variants')->where('id', self::$variantId)->sole());
    }

    public function test_the_printer_configuration_survives_the_settings_copy(): void
    {
        // settings is the one table where the local mirror is deliberately wider
        // than Postgres: the printer lives in it and has no upstream row. A
        // whole-table copy would erase it.
        $this->post(route('setup.store'), $this->payload([
            'configure_printer' => '1',
            'receipt_paper_size' => '80mm',
            'receipt_connection' => 'network',
            'receipt_printer_name' => '',
            'receipt_device_path' => '',
            'receipt_network_host' => '192.168.1.50',
            'receipt_network_port' => '9100',
            'auto_print' => '1',
            'open_cash_drawer' => '0',
        ]))->assertSessionHasNoErrors();

        $printer = app(PrinterSettings::class)->all();

        $this->assertSame('80mm', $printer['receipt_paper_size']);
        $this->assertSame('192.168.1.50', $printer['receipt_network_host']);

        // Both sets of keys, in the same table, neither having eaten the other.
        $this->assertSame(self::BUSINESS_NAME, Setting::query()->where('key', 'business_name')->value('value'));
    }

    public function test_the_credentials_file_is_deleted_when_asked(): void
    {
        $path = $this->writeCredentialsFile(self::$document, 'to-be-deleted.json');

        $this->post(route('setup.store'), [
            'credentials_path' => $path,
            'delete_after_import' => '1',
            'configure_printer' => '0',
        ])->assertSessionHasNoErrors();

        // §9: the file holds a live database password in plaintext, and the
        // device now holds the same credential sealed under its own key.
        $this->assertFileDoesNotExist($path);
        $this->assertTrue(Instance::isHydrated());
    }

    public function test_a_build_below_the_schemas_minimum_is_refused_and_writes_nothing(): void
    {
        // The gate reads schema_meta LIVE — this is the number the provisioned
        // schema really declares, not one from the JSON.
        config(['nativephp.version' => '0.0.1']);

        $this->post(route('setup.store'), $this->payload())
            ->assertSessionHasErrors('credentials_path');

        $this->assertDatabaseCount('instance', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('settings', 0);
        $this->assertFalse(Instance::isHydrated());
    }

    public function test_a_credentials_file_for_another_branch_is_refused(): void
    {
        $document = self::$document;
        $document['branch']['id'] = '00000000-0000-0000-0000-000000000000';

        $path = $this->directory.'/wrong-branch.json';
        File::put($path, (string) json_encode($document));

        $this->post(route('setup.store'), [
            'credentials_path' => $path,
            'delete_after_import' => '0',
            'configure_printer' => '0',
        ])->assertSessionHasErrors('credentials_path');

        $this->assertDatabaseCount('instance', 0);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Catalog, extra users and another branch's stock — the state an online
     * Owner would have created before this device was ever set up.
     *
     * Written with the ADMIN connection because the branch role deliberately
     * cannot write any of it: catalog and users are online-authoritative and
     * pulled, never pushed (BranchGrants).
     */
    /** A row's `created_at` straight out of Postgres, offset intact. */
    /** What the provisioned schema declares about itself, read live. */
    private function remoteSchemaVersion(): int
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        return (int) self::$admin->query('select schema_version from schema_meta')->fetchColumn();
    }

    private function remoteCreatedAt(string $table, string $id): \DateTimeImmutable
    {
        self::$admin->exec('set search_path to "'.self::$schema.'"');

        $statement = self::$admin->prepare("select created_at from {$table} where id = ?");
        $statement->execute([$id]);

        return new \DateTimeImmutable((string) $statement->fetchColumn());
    }

    private function seedCatalog(): void
    {
        self::$categoryId = $this->uuid();
        self::$itemId = $this->uuid();
        self::$variantId = $this->uuid();
        self::$otherBranchId = $this->uuid();

        $schema = self::$schema;
        $now = now()->toDateTimeString();

        self::$admin->exec("set search_path to \"{$schema}\"");

        $sql = [
            "insert into categories (id, name, created_at, updated_at) values ('".self::$categoryId."', 'Beverages', '{$now}', '{$now}')",
            "insert into items (id, name, unit, category_id, created_at, updated_at)
             values ('".self::$itemId."', 'Hydration Coffee', 'pc', '".self::$categoryId."', '{$now}', '{$now}')",
            "insert into variants (id, item_id, name, sku, barcode, selling_price, cost, low_stock_threshold, created_at, updated_at)
             values ('".self::$variantId."', '".self::$itemId."', null, 'HYDSKU1', null, 25.50, 10.00, 5, '{$now}', '{$now}')",

            // This branch's stock…
            "insert into stock_snapshots (id, branch_id, variant_id, quantity, created_at, updated_at)
             values ('".$this->uuid()."', '".self::$document['branch']['id']."', '".self::$variantId."', 42, '{$now}', '{$now}')",

            // …and a second branch's, which must NOT come down.
            "insert into branches (id, code, name, created_at, updated_at)
             values ('".self::$otherBranchId."', 'CEB', 'Cebu', '{$now}', '{$now}')",
            "insert into stock_snapshots (id, branch_id, variant_id, quantity, created_at, updated_at)
             values ('".$this->uuid()."', '".self::$otherBranchId."', '".self::$variantId."', 99, '{$now}', '{$now}')",

            // A user who must change their password, and one who has left.
            "insert into users (id, name, username, password, role, must_change_password, created_at, updated_at)
             values ('".$this->uuid()."', 'Hydration Cashier', 'hydration-cashier', '\$2y\$04\$abcdefghijklmnopqrstuv', 'cashier', true, '{$now}', '{$now}')",
            "insert into users (id, name, username, password, role, must_change_password, created_at, updated_at, deleted_at)
             values ('".$this->uuid()."', 'Hydration Leaver', 'hydration-leaver', '\$2y\$04\$abcdefghijklmnopqrstuv', 'cashier', false, '{$now}', '{$now}', '{$now}')",
        ];

        foreach ($sql as $statement) {
            self::$admin->exec($statement);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'credentials_path' => $this->writeCredentialsFile(self::$document),
            'delete_after_import' => '0',
            'configure_printer' => '0',
        ], $overrides);
    }
}
