<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * Backup and restore of the single SQLite database file (Phase 16, Owner-only).
 *
 * Evitan Plus is an offline, single-user desktop app, so a file copy taken from
 * an idle menu action is a consistent snapshot — but only after a WAL
 * checkpoint: the packaged NativePHP runtime forces journal_mode=WAL on every
 * boot, leaving recent commits in a -wal sidecar the copy would miss. Every
 * backup therefore checkpoints first (a no-op under dev's rollback journal),
 * and a restore removes the live database's stale -wal/-shm sidecars so old
 * WAL frames can never replay over the restored file. Before any restore we
 * first snapshot the current live file, so a restore can itself be undone.
 *
 * Paths are injectable so tests can point at a temporary database + directory
 * and never touch the real (or in-memory) application database.
 */
class DatabaseBackupManager
{
    /** SQLite files begin with this 16-byte magic header. */
    private const SQLITE_HEADER = "SQLite format 3\0";

    private string $databasePath;

    private string $backupDirectory;

    public function __construct(?string $databasePath = null, ?string $backupDirectory = null)
    {
        // The live file must come from the ACTIVE connection: the packaged
        // NativePHP runtime swaps the default to a "nativephp" connection
        // pointing at the per-install userData directory, where the dev-time
        // sqlite connection's path does not even exist.
        $this->databasePath = $databasePath
            ?? (string) config('database.connections.'.config('database.default').'.database');
        $this->backupDirectory = $backupDirectory ?? storage_path('backups');
    }

    public function databasePath(): string
    {
        return $this->databasePath;
    }

    public function backupDirectory(): string
    {
        return $this->backupDirectory;
    }

    /**
     * Existing backups, newest first.
     *
     * @return list<array{name: string, size: int, created_at: int}>
     */
    public function list(): array
    {
        if (! is_dir($this->backupDirectory)) {
            return [];
        }

        $backups = [];

        foreach (glob($this->backupDirectory.'/*.sqlite') ?: [] as $path) {
            $backups[] = [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'created_at' => (int) filemtime($path),
            ];
        }

        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * Copy the live database to a timestamped backup file.
     *
     * @param  string  $prefix  Distinguishes manual backups from automatic
     *                          pre-restore safety copies.
     * @return string The created backup's filename.
     */
    public function create(string $prefix = 'backup'): string
    {
        if (! is_file($this->databasePath)) {
            throw new RuntimeException('The database file does not exist.');
        }

        $this->checkpoint();

        $this->ensureDirectory();

        $filename = $this->newBackupFilename($prefix);

        if (! copy($this->databasePath, $this->backupDirectory.'/'.$filename)) {
            throw new RuntimeException('Failed to write the backup file.');
        }

        return $filename;
    }

    /**
     * Copy the live database into an external directory (e.g. a mounted USB
     * drive) chosen by the Owner. Unlike create(), the destination directory
     * must already exist — we never create folders on removable media.
     *
     * @return string The full path of the written backup file.
     */
    public function export(string $destinationDirectory, string $prefix = 'backup'): string
    {
        if (! is_file($this->databasePath)) {
            throw new RuntimeException('The database file does not exist.');
        }

        $this->checkpoint();

        if (! is_dir($destinationDirectory) || ! is_writable($destinationDirectory)) {
            throw new RuntimeException('The selected folder is not a writable directory.');
        }

        $destination = rtrim($destinationDirectory, '/\\').'/'.$this->newBackupFilename($prefix);

        if (! copy($this->databasePath, $destination)) {
            throw new RuntimeException('Failed to write the backup file to the selected folder.');
        }

        return $destination;
    }

    /**
     * Restore the database from a named backup, taking an automatic pre-restore
     * safety backup of the current file first.
     */
    public function restore(string $filename): void
    {
        $this->restoreFromPath($this->resolveBackupPath($filename));
    }

    /**
     * Restore the database from an arbitrary SQLite file (e.g. one picked from
     * an external drive), taking an automatic pre-restore safety backup of the
     * current file first.
     */
    public function restoreFromPath(string $source): void
    {
        if (! is_file($source)) {
            throw new RuntimeException('Backup file not found.');
        }

        if (! $this->looksLikeSqlite($source)) {
            throw new RuntimeException('The selected file is not a valid SQLite database.');
        }

        // Any valid SQLite file passes the magic check — including some other
        // app's database, which would replace ours and brick the app with no
        // in-app way back. Require the core Evitan Plus schema before touching
        // the live file.
        if (! $this->looksLikeEvitanSchema($source)) {
            throw new RuntimeException('The selected file is not an Evitan Plus database backup.');
        }

        // Safety net: snapshot the current database before overwriting it, so the
        // restore itself can be rolled back. create() checkpoints the live WAL
        // first, so the old database's sidecars are empty afterwards.
        if (is_file($this->databasePath)) {
            $this->create('pre-restore');
        }

        // Close the app's own handle on the live file before overwriting it —
        // but only when the active connection really is this file (tests
        // inject throwaway paths and must never lose their own connection; the
        // containerless unit tests have no facade root at all).
        if (Facade::getFacadeApplication() !== null
            && (string) config('database.connections.'.config('database.default').'.database') === $this->databasePath) {
            DB::purge((string) config('database.default'));
        }

        if (! copy($source, $this->databasePath)) {
            throw new RuntimeException('Failed to restore the database file.');
        }

        // Any leftover WAL/SHM sidecars belong to the overwritten database;
        // letting SQLite "recover" them over the restored file would corrupt it.
        @unlink($this->databasePath.'-wal');
        @unlink($this->databasePath.'-shm');
    }

    /**
     * Fold any WAL content into the main database file so a plain file copy is
     * a complete snapshot. Under dev's rollback journal this is a no-op; a
     * failed checkpoint must never block a backup — the main file is still a
     * consistent (if slightly earlier) snapshot.
     */
    private function checkpoint(): void
    {
        try {
            $pdo = new \PDO('sqlite:'.$this->databasePath, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec('PRAGMA busy_timeout=5000');
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\Throwable) {
            // Best effort — see docblock.
        }
    }

    /**
     * Resolve a backup filename to an absolute path inside the backup directory,
     * rejecting any attempt to escape it via path traversal.
     */
    private function resolveBackupPath(string $filename): string
    {
        // Only a bare filename is ever valid — no directory components.
        if (basename($filename) !== $filename || $filename === '' || str_contains($filename, "\0")) {
            throw new RuntimeException('Invalid backup filename.');
        }

        return $this->backupDirectory.'/'.$filename;
    }

    /**
     * The candidate must contain the tables every Evitan Plus database has had
     * since Phase 1 — read-only, before the live file is overwritten.
     */
    private function looksLikeEvitanSchema(string $path): bool
    {
        $required = ['users', 'transactions', 'inventory_ledger', 'settings', 'variants'];

        try {
            // Plain open (no readonly flag — deprecated constant churn across
            // PHP versions); the method only SELECTs, so the file is untouched.
            $pdo = new \PDO('sqlite:'.$path, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $placeholders = implode(',', array_fill(0, count($required), '?'));
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ({$placeholders})",
            );
            $statement->execute($required);

            return (int) $statement->fetchColumn() === count($required);
        } catch (\Throwable) {
            return false;
        }
    }

    private function looksLikeSqlite(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, strlen(self::SQLITE_HEADER));
        fclose($handle);

        return $header === self::SQLITE_HEADER;
    }

    /**
     * A unique, timestamped backup filename. The prefix distinguishes manual
     * backups from automatic pre-restore safety copies.
     */
    private function newBackupFilename(string $prefix): string
    {
        return $prefix.'-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6).'.sqlite';
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->backupDirectory)) {
            mkdir($this->backupDirectory, 0755, true);
        }
    }
}
