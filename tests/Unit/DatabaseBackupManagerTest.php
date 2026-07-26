<?php

namespace Tests\Unit;

use App\Support\Backup\DatabaseBackupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseBackupManagerTest extends TestCase
{
    private string $workDir;

    private string $dbPath;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/evitan-backup-'.uniqid();
        $this->backupDir = $this->workDir.'/backups';
        $this->dbPath = $this->workDir.'/nativephp.sqlite';

        mkdir($this->workDir, 0755, true);
        // A real (minimal) Evitan-shaped SQLite DB: restore now validates the
        // schema, so magic-header stand-ins no longer pass.
        $this->writeEvitanDb($this->dbPath, 'original-data');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->workDir);

        parent::tearDown();
    }

    /**
     * A real SQLite file carrying the core Evitan Plus tables (the restore
     * schema check requires them) and a marker row whose text is findable in
     * the raw bytes, preserving the string-based content assertions.
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

    private function manager(): DatabaseBackupManager
    {
        return new DatabaseBackupManager($this->dbPath, $this->backupDir);
    }

    public function test_create_copies_the_database_into_the_backup_directory(): void
    {
        $filename = $this->manager()->create();

        $this->assertFileExists($this->backupDir.'/'.$filename);
        $this->assertSame(
            file_get_contents($this->dbPath),
            file_get_contents($this->backupDir.'/'.$filename),
        );
    }

    public function test_list_returns_backups_newest_first(): void
    {
        $manager = $this->manager();
        $first = $manager->create();
        touch($this->backupDir.'/'.$first, time() - 100);
        $second = $manager->create();

        $names = array_column($manager->list(), 'name');

        $this->assertSame([$second, $first], $names);
    }

    public function test_restore_takes_an_automatic_pre_restore_backup_then_swaps_contents(): void
    {
        $manager = $this->manager();

        // A backup captured while the DB held its original contents.
        $backupName = $manager->create();

        // The live DB then changes.
        file_put_contents($this->dbPath, "SQLite format 3\0changed-data");

        $manager->restore($backupName);

        // The live DB now matches the restored backup...
        $this->assertStringContainsString('original-data', (string) file_get_contents($this->dbPath));

        // ...and a pre-restore safety backup captured the changed state first.
        $preRestore = array_values(array_filter(
            $manager->list(),
            fn ($b) => str_starts_with($b['name'], 'pre-restore-'),
        ));
        $this->assertCount(1, $preRestore);
        $this->assertStringContainsString('changed-data', (string) file_get_contents($this->backupDir.'/'.$preRestore[0]['name']));
    }

    public function test_create_checkpoints_wal_content_into_the_backup(): void
    {
        // Switch the live DB to WAL (as the packaged NativePHP runtime does)
        // and write a row that, before a checkpoint, lives only in the -wal
        // sidecar a plain file copy would miss. The writer stays open so
        // SQLite cannot auto-checkpoint on connection close.
        $writer = new \PDO('sqlite:'.$this->dbPath);
        $writer->exec('PRAGMA journal_mode=WAL');
        $writer->exec("INSERT INTO marker (id) VALUES ('wal-only-data')");
        $this->assertFileExists($this->dbPath.'-wal');
        $this->assertStringNotContainsString('wal-only-data', (string) file_get_contents($this->dbPath));

        $filename = $this->manager()->create();

        $backup = new \PDO('sqlite:'.$this->backupDir.'/'.$filename);
        $found = $backup->query("SELECT COUNT(*) FROM marker WHERE id = 'wal-only-data'")->fetchColumn();
        $this->assertSame(1, (int) $found);
        unset($writer, $backup);
    }

    public function test_restore_removes_stale_wal_sidecars(): void
    {
        $manager = $this->manager();

        // A backup taken while the DB was still in rollback-journal mode.
        $backupName = $manager->create();

        // The live DB then moves to WAL and gains newer data; the open writer
        // keeps the -wal/-shm sidecars on disk, as in the running app.
        $writer = new \PDO('sqlite:'.$this->dbPath);
        $writer->exec('PRAGMA journal_mode=WAL');
        $writer->exec("INSERT INTO marker (id) VALUES ('newer-data')");
        $this->assertFileExists($this->dbPath.'-wal');
        $this->assertFileExists($this->dbPath.'-shm');

        $manager->restore($backupName);

        // Stale sidecars are gone — old WAL frames can never replay over the
        // restored file — and the restored contents win.
        $this->assertFileDoesNotExist($this->dbPath.'-wal');
        $this->assertFileDoesNotExist($this->dbPath.'-shm');

        $restored = new \PDO('sqlite:'.$this->dbPath);
        $newer = $restored->query("SELECT COUNT(*) FROM marker WHERE id = 'newer-data'")->fetchColumn();
        $this->assertSame(0, (int) $newer);

        // The pre-restore safety backup captured the newer data first (the
        // checkpoint folded it into the copied file).
        $preRestore = array_values(array_filter(
            $manager->list(),
            fn ($b) => str_starts_with($b['name'], 'pre-restore-'),
        ));
        $this->assertCount(1, $preRestore);
        $safety = new \PDO('sqlite:'.$this->backupDir.'/'.$preRestore[0]['name']);
        $kept = $safety->query("SELECT COUNT(*) FROM marker WHERE id = 'newer-data'")->fetchColumn();
        $this->assertSame(1, (int) $kept);
        unset($writer, $restored, $safety);
    }

    public function test_export_copies_the_database_into_an_external_directory(): void
    {
        $externalDir = $this->workDir.'/usb';
        mkdir($externalDir, 0755, true);

        $path = $this->manager()->export($externalDir);

        $this->assertSame($externalDir, dirname($path));
        $this->assertNotEmpty(glob($externalDir.'/backup-*.sqlite'));
        $this->assertSame(file_get_contents($this->dbPath), file_get_contents($path));
    }

    public function test_export_rejects_a_non_existent_destination(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->export($this->workDir.'/not-mounted');
    }

    public function test_restore_from_path_takes_a_pre_restore_backup_then_swaps_contents(): void
    {
        $manager = $this->manager();

        // An external file standing in for a backup on a USB drive.
        $external = $this->workDir.'/external-backup.sqlite';
        $this->writeEvitanDb($external, 'external-data');

        $manager->restoreFromPath($external);

        $this->assertStringContainsString('external-data', (string) file_get_contents($this->dbPath));

        $preRestore = array_values(array_filter(
            $manager->list(),
            fn ($b) => str_starts_with($b['name'], 'pre-restore-'),
        ));
        $this->assertCount(1, $preRestore);
        $this->assertStringContainsString('original-data', (string) file_get_contents($this->backupDir.'/'.$preRestore[0]['name']));
    }

    public function test_restore_from_path_rejects_a_non_sqlite_file(): void
    {
        $external = $this->workDir.'/junk.sqlite';
        file_put_contents($external, 'not a database');

        $this->expectException(RuntimeException::class);

        $this->manager()->restoreFromPath($external);
    }

    public function test_restore_from_path_rejects_a_foreign_sqlite_database(): void
    {
        // Perfectly valid SQLite, but some other app's schema: restoring it
        // would brick the POS, so it must be refused before the swap.
        $external = $this->workDir.'/foreign.sqlite';
        $pdo = new \PDO('sqlite:'.$external);
        $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        unset($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an Evitan Plus database backup');

        $this->manager()->restoreFromPath($external);
    }

    public function test_restore_rejects_path_traversal(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->restore('../nativephp.sqlite');
    }

    public function test_restore_rejects_a_missing_backup(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->restore('does-not-exist.sqlite');
    }

    public function test_restore_rejects_a_non_sqlite_file(): void
    {
        mkdir($this->backupDir, 0755, true);
        file_put_contents($this->backupDir.'/junk.sqlite', 'not a database');

        $this->expectException(RuntimeException::class);

        $this->manager()->restore('junk.sqlite');
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
}
