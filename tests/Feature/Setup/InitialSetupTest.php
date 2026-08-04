<?php

namespace Tests\Feature\Setup;

use App\Http\Requests\Setup\ImportCredentialsRequest;
use App\Models\Instance;
use App\Models\User;
use App\Support\Printing\PrinterSettings;
use App\Support\Setup\CredentialsFilePicker;
use App\Support\Sync\CredentialsDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The setup wizard (M5): a device that is not yet a branch imports a credentials
 * JSON, connects, and hydrates.
 *
 * What can be proved here without a Postgres server is everything up to the
 * connection — the gate that funnels a device here, the envelope rules, and the
 * guarantee that a failed attempt writes nothing — plus the connection itself
 * failing cleanly. What happens once a connection SUCCEEDS is proved against the
 * real Supabase project by tests/Integration/HydrationTest.php
 * (`composer verify:hydrate`), because hydration's remaining risks are Postgres
 * value shapes and nothing here would exercise them.
 */
class InitialSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every test here is about a device that has NOT been set up, so the base
     * case's hydrated instance would defeat all of them.
     */
    protected bool $hydrated = false;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/credentials');

        File::ensureDirectoryExists($this->directory);

        $this->fakePicker(null);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_device_that_is_not_a_branch_is_routed_to_the_wizard(): void
    {
        $this->assertDatabaseCount('instance', 0);

        $this->get(route('pos'))->assertRedirect(route('setup'));
        $this->get(route('login'))->assertRedirect(route('setup'));
        $this->get(route('setup'))->assertOk()->assertInertia(fn ($page) => $page->component('setup', false));
    }

    public function test_users_alone_no_longer_count_as_a_set_up_device(): void
    {
        // The gate moved off "a user exists" in M5 precisely because users
        // arrive DURING hydration: their presence would otherwise mark a device
        // ready part-way through the copy.
        User::factory()->cashier()->create();

        $this->get(route('pos'))->assertRedirect(route('setup'));
        $this->get(route('setup'))->assertOk();
    }

    public function test_the_wizard_is_unreachable_once_the_device_is_hydrated(): void
    {
        $this->hydratedDevice();

        $this->get(route('setup'))->assertRedirect(route('login'));
        $this->post(route('setup.store'), $this->payload())->assertRedirect(route('login'));
    }

    public function test_an_unhydrated_instance_row_still_routes_to_the_wizard(): void
    {
        // Belt and braces on a state the atomic import makes unreachable: if
        // identity ever existed without a baseline, the device must come back
        // to the wizard rather than into an empty catalog.
        $this->hydratedDevice(['hydrated_at' => null]);

        $this->get(route('pos'))->assertRedirect(route('setup'));
        $this->get(route('setup'))->assertOk();
    }

    public function test_a_missing_file_is_refused_and_writes_nothing(): void
    {
        $this->post(route('setup.store'), $this->payload(['credentials_path' => $this->directory.'/nope.json']))
            ->assertSessionHasErrors('credentials_path');

        $this->assertNothingPersisted();
    }

    public function test_a_file_that_is_not_json_is_refused(): void
    {
        $path = $this->write('garbage.json', 'this is not json');

        $this->post(route('setup.store'), $this->payload(['credentials_path' => $path]))
            ->assertSessionHasErrors('credentials_path');

        $this->assertNothingPersisted();
    }

    public function test_a_json_document_of_another_kind_is_refused(): void
    {
        $path = $this->write('other.json', (string) json_encode(['hello' => 'world']));

        $this->post(route('setup.store'), $this->payload(['credentials_path' => $path]))
            ->assertSessionHasErrors('credentials_path');

        $this->assertNothingPersisted();
    }

    public function test_a_newer_credentials_format_is_refused_rather_than_half_read(): void
    {
        $path = $this->write('v2.json', (string) json_encode($this->document(['format_version' => 2])));

        $this->post(route('setup.store'), $this->payload(['credentials_path' => $path]))
            ->assertSessionHasErrors('credentials_path');

        $this->assertNothingPersisted();
    }

    public function test_a_document_missing_a_required_value_is_refused(): void
    {
        $document = $this->document();
        unset($document['connection']['password']);

        $path = $this->write('partial.json', (string) json_encode($document));

        $this->post(route('setup.store'), $this->payload(['credentials_path' => $path]))
            ->assertSessionHasErrors('credentials_path');

        $this->assertNothingPersisted();
    }

    public function test_a_credential_that_cannot_connect_leaves_the_device_untouched(): void
    {
        $path = $this->write('unreachable.json', (string) json_encode($this->unreachable()));

        $this->post(route('setup.store'), $this->payload(['credentials_path' => $path]))
            ->assertSessionHasErrors('credentials_path');

        $this->assertNothingPersisted();

        // The file is never deleted on a failed import, whatever the checkbox
        // said: it may be the only copy of the credential.
        $this->assertFileExists($path);
    }

    public function test_a_failed_import_leaves_the_connection_fail_closed(): void
    {
        $this->post(route('setup.store'), $this->payload([
            'credentials_path' => $this->write('unreachable.json', (string) json_encode($this->unreachable())),
        ]));

        // The cycle restores the shipped (empty) connection config on the way
        // out, so nothing running afterwards inherits a live credential — the
        // §11.5 rule that a cycle owns its connection, seen from the config side.
        $this->assertEmpty(config('database.connections.pgsql.host'));
        $this->assertEmpty(config('database.connections.pgsql.password'));
    }

    public function test_the_browse_button_returns_the_picked_path(): void
    {
        $this->fakePicker('/media/usb/biz_abc-MNL.json');

        $this->post(route('setup.browse'))
            ->assertRedirect()
            ->assertSessionHas('picked_credentials_path', '/media/usb/biz_abc-MNL.json');
    }

    public function test_a_cancelled_picker_says_nothing(): void
    {
        $this->post(route('setup.browse'))
            ->assertRedirect()
            ->assertSessionMissing('picked_credentials_path')
            ->assertSessionHasNoErrors();
    }

    public function test_the_wizard_still_collects_the_printer_and_nothing_else(): void
    {
        // The Owner, the business identity and localization all come from
        // Postgres now. The printer is device hardware, which is why it stayed.
        $this->assertSame(
            ['credentials_path', 'delete_after_import', 'configure_printer'],
            array_keys((new ImportCredentialsRequest)->rules()),
        );

        $this->get(route('setup'))->assertInertia(
            fn ($page) => $page
                ->where('printerDefaults.receipt_paper_size', app(PrinterSettings::class)->all()['receipt_paper_size'])
                ->where('appVersion', config('nativephp.version'))
                ->has('desktop')
        );
    }

    private function assertNothingPersisted(): void
    {
        $this->assertDatabaseCount('instance', 0);
        $this->assertDatabaseCount('instance_secret', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('settings', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function hydratedDevice(array $overrides = []): void
    {
        Instance::query()->create(array_merge([
            'id' => Instance::ID,
            'business_id' => '00000000-0000-0000-0000-00000000b12b',
            'schema_name' => 'biz_test',
            'branch_id' => '00000000-0000-0000-0000-0000000b4a4c',
            'branch_code' => 'TST',
            'branch_name' => 'Test Branch',
            'schema_version' => 1,
            'min_app_version' => '1.0.0',
            'hydrated_at' => now(),
        ], $overrides));
    }

    private function fakePicker(?string $path): void
    {
        $this->app->instance(CredentialsFilePicker::class, new class($path) implements CredentialsFilePicker
        {
            public function __construct(private ?string $path) {}

            public function pick(?string $default = null): ?string
            {
                return $this->path;
            }
        });
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory.'/'.$name;

        File::put($path, $contents);

        return $path;
    }

    /**
     * A valid document pointed at a port nothing listens on, so it fails at
     * connect rather than at authentication. Which failure it is does not
     * matter here; that the failure path writes nothing does.
     *
     * @return array<string, mixed>
     */
    private function unreachable(): array
    {
        $document = $this->document();

        $document['connection']['host'] = '127.0.0.1';
        $document['connection']['port'] = 1;

        return $document;
    }

    /**
     * A structurally valid credentials document — the shape
     * Provisioning\Support\BranchCredentials::document() emits.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides = []): array
    {
        return array_merge([
            'format' => CredentialsDocument::FORMAT,
            'format_version' => CredentialsDocument::FORMAT_VERSION,
            'issued_at' => now()->toIso8601String(),
            'business' => [
                'id' => '11111111-1111-1111-1111-111111111111',
                'name' => 'Cruz Sari-Sari Store',
                'schema' => 'biz_test',
            ],
            'branch' => [
                'id' => '22222222-2222-2222-2222-222222222222',
                'code' => 'MNL',
                'name' => 'Manila',
                'address' => null,
            ],
            'connection' => [
                'driver' => 'pgsql',
                'host' => 'aws-0-ap-southeast-1.pooler.supabase.com',
                'port' => 6543,
                'database' => 'postgres',
                'username' => 'evitan_branch_x.projectref',
                'password' => 'a-branch-secret',
                'search_path' => null,
                'sslmode' => 'require',
            ],
            'role' => ['name' => 'evitan_branch_x'],
            'versions' => ['schema_version' => 1, 'min_app_version' => '1.0.0'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'credentials_path' => $this->directory.'/credentials.json',
            'delete_after_import' => '1',
            'configure_printer' => '0',
        ], $overrides);
    }
}
