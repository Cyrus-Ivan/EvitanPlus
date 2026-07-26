<?php

namespace Tests\Feature\Backup;

use App\Models\Setting;
use App\Models\User;
use App\Support\Backup\DatabaseBackupManager;
use App\Support\Backup\DirectoryPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    private string $dbPath;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/evitan-backup-feat-'.uniqid();
        $this->backupDir = $this->workDir.'/backups';
        $this->dbPath = $this->workDir.'/nativephp.sqlite';

        mkdir($this->workDir, 0755, true);
        $this->writeEvitanDb($this->dbPath, 'live-data');

        // Point the controller's manager at throwaway paths so the real (and
        // in-memory test) database is never touched.
        $this->app->instance(DatabaseBackupManager::class, new DatabaseBackupManager($this->dbPath, $this->backupDir));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->workDir);

        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Bind a fake picker that returns a preset path (null = user cancelled),
     * standing in for the native OS dialog which needs the desktop runtime.
     */
    private function fakePicker(?string $directory, ?string $file): void
    {
        $this->app->instance(DirectoryPicker::class, new class($directory, $file) implements DirectoryPicker
        {
            public function __construct(private ?string $directory, private ?string $file) {}

            public function pickDirectory(?string $default = null): ?string
            {
                return $this->directory;
            }

            public function pickFile(?string $default = null): ?string
            {
                return $this->file;
            }
        });
    }

    /**
     * A real SQLite file carrying the core Evitan Plus tables (the restore
     * schema check requires them) and a marker row findable in the raw bytes.
     */
    private function writeEvitanDb(string $path, string $marker): void
    {
        if (is_file($path)) {
            unlink($path);
        }
        $pdo = new \PDO('sqlite:'.$path);
        foreach (['users', 'transactions', 'inventory_ledger', 'settings', 'variants', 'marker'] as $table) {
            $pdo->exec("CREATE TABLE {$table} (id TEXT)");
        }
        $pdo->exec("INSERT INTO marker (id) VALUES ('{$marker}')");
        unset($pdo);
    }

    public function test_owner_can_view_the_backup_page(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('backup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('backups'));
    }

    public function test_owner_can_create_a_backup(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('backup.store'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertNotEmpty(glob($this->backupDir.'/backup-*.sqlite'));
    }

    public function test_owner_can_restore_and_a_pre_restore_backup_is_taken(): void
    {
        $owner = User::factory()->owner()->create();

        // Create a backup of the current live data, then change the live file.
        $this->actingAs($owner)->post(route('backup.store'));
        $backupName = basename((string) glob($this->backupDir.'/backup-*.sqlite')[0]);
        $this->writeEvitanDb($this->dbPath, 'changed-data');

        $this->actingAs($owner)
            ->post(route('backup.restore'), ['filename' => $backupName])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success')
            // The backup page turns this into a persistent restart banner.
            ->assertSessionHas('restart_required', true);

        // Live DB restored to the backup's contents.
        $this->assertStringContainsString('live-data', (string) file_get_contents($this->dbPath));
        // A pre-restore safety backup captured the changed state first.
        $this->assertNotEmpty(glob($this->backupDir.'/pre-restore-*.sqlite'));
    }

    public function test_restore_with_a_bad_filename_reports_an_error(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('backup.restore'), ['filename' => 'does-not-exist.sqlite'])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');
    }

    public function test_owner_can_back_up_to_an_external_folder_and_the_folder_is_remembered(): void
    {
        $external = $this->workDir.'/usb';
        mkdir($external, 0755, true);
        $this->fakePicker($external, null);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('backup.external'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertNotEmpty(glob($external.'/backup-*.sqlite'));
        $this->assertSame($external, Setting::query()->where('key', 'backup_external_directory')->value('value'));
    }

    public function test_cancelling_the_external_backup_writes_nothing(): void
    {
        $this->fakePicker(null, null);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('backup.external'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'info');

        $this->assertEmpty(glob($this->backupDir.'/*.sqlite') ?: []);
        $this->assertDatabaseMissing('settings', ['key' => 'backup_external_directory']);
    }

    public function test_owner_can_restore_from_an_external_file_with_a_pre_restore_backup(): void
    {
        $external = $this->workDir.'/external-backup.sqlite';
        $this->writeEvitanDb($external, 'external-data');
        $this->fakePicker(null, $external);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('backup.restore-external'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success')
            ->assertSessionHas('restart_required', true);

        $this->assertStringContainsString('external-data', (string) file_get_contents($this->dbPath));
        $this->assertNotEmpty(glob($this->backupDir.'/pre-restore-*.sqlite'));
    }

    public function test_app_restart_is_unavailable_outside_the_native_runtime(): void
    {
        // The relaunch endpoint only exists for the desktop app; in a plain
        // web/test environment it must look like it isn't there at all.
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('app.restart'))
            ->assertNotFound();
    }

    public function test_app_restart_is_owner_only(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->cashier()->create()] as $user) {
            $this->actingAs($user)->post(route('app.restart'))->assertForbidden();
        }
    }

    public function test_admin_and_cashier_are_forbidden(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->cashier()->create()] as $user) {
            $this->actingAs($user)->get(route('backup'))->assertForbidden();
            $this->actingAs($user)->post(route('backup.store'))->assertForbidden();
            $this->actingAs($user)->post(route('backup.restore'), ['filename' => 'x.sqlite'])->assertForbidden();
            $this->actingAs($user)->post(route('backup.external'))->assertForbidden();
            $this->actingAs($user)->post(route('backup.restore-external'))->assertForbidden();
        }
    }
}
