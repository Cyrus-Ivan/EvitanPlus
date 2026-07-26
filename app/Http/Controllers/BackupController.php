<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Audit\AuditRecorder;
use App\Support\Backup\DatabaseBackupManager;
use App\Support\Backup\DirectoryPicker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Database backup & restore (Phase 16). Owner-only — the route sits inside the
 * `role:owner` group; the manager is resolved from the container so tests can
 * bind one pointed at temporary paths.
 */
class BackupController extends Controller
{
    /** Settings key remembering the last folder used for an external backup. */
    private const EXTERNAL_DIRECTORY_KEY = 'backup_external_directory';

    public function index(DatabaseBackupManager $manager): Response
    {
        return Inertia::render('backup', [
            'backups' => array_map(fn (array $backup) => [
                'name' => $backup['name'],
                'size' => $this->formatBytes($backup['size']),
                'created_at' => date(DATE_ATOM, $backup['created_at']),
            ], $manager->list()),
            // Native folder/file dialogs only exist in the desktop runtime; the
            // frontend disables the external actions when this is false.
            'isDesktop' => (bool) config('nativephp-internal.running'),
        ]);
    }

    public function store(DatabaseBackupManager $manager, AuditRecorder $audit): RedirectResponse
    {
        try {
            $filename = $manager->create();
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $audit->record(
            action: 'backup.created',
            description: "Created database backup \"{$filename}\".",
            new: ['filename' => $filename],
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Backup created.']);
    }

    public function restore(Request $request, DatabaseBackupManager $manager, AuditRecorder $audit): RedirectResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string'],
        ]);

        try {
            $manager->restore($validated['filename']);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $audit->record(
            action: 'backup.restored',
            description: "Restored database from backup \"{$validated['filename']}\".",
            new: ['filename' => $validated['filename']],
        );

        return $this->restoredResponse();
    }

    /**
     * Back up the database to an external drive: the Owner picks a destination
     * folder via a native dialog, and a timestamped copy is written there. The
     * chosen folder is remembered as the default for next time.
     */
    public function exportExternal(DirectoryPicker $picker, DatabaseBackupManager $manager, AuditRecorder $audit): RedirectResponse
    {
        try {
            $directory = $picker->pickDirectory($this->lastExternalDirectory());

            if ($directory === null) {
                return back()->with('toast', ['type' => 'info', 'message' => 'Backup cancelled.']);
            }

            $destination = $manager->export($directory);
            $this->rememberExternalDirectory($directory);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $audit->record(
            action: 'backup.exported',
            description: "Exported database backup to \"{$destination}\".",
            new: ['destination' => $destination],
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Backup saved to '.$directory.'.',
        ]);
    }

    /**
     * Restore the database from an arbitrary SQLite file (e.g. one on an
     * external drive) chosen via a native file dialog. A safety backup of the
     * current database is taken automatically first.
     */
    public function restoreExternal(DirectoryPicker $picker, DatabaseBackupManager $manager, AuditRecorder $audit): RedirectResponse
    {
        try {
            $file = $picker->pickFile($this->lastExternalDirectory());

            if ($file === null) {
                return back()->with('toast', ['type' => 'info', 'message' => 'Restore cancelled.']);
            }

            $manager->restoreFromPath($file);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        $audit->record(
            action: 'backup.restored',
            description: "Restored database from external file \"{$file}\".",
            new: ['source' => $file],
        );

        return $this->restoredResponse();
    }

    /**
     * The shared post-restore response: the app must be restarted so the
     * launch provisioning (migrations + stock reconcile) runs against the
     * restored file — the flag drives a persistent banner with a Restart
     * button on the backup page.
     */
    private function restoredResponse(): RedirectResponse
    {
        return back()
            ->with('toast', [
                'type' => 'success',
                'message' => 'Database restored. Restart Evitan Plus to finish — a safety backup of the previous database was taken automatically.',
            ])
            ->with('restart_required', true);
    }

    private function lastExternalDirectory(): ?string
    {
        return Setting::query()->where('key', self::EXTERNAL_DIRECTORY_KEY)->value('value');
    }

    private function rememberExternalDirectory(string $directory): void
    {
        Setting::updateOrCreate(['key' => self::EXTERNAL_DIRECTORY_KEY], ['value' => $directory]);
    }

    /**
     * Render a byte count as a compact human-readable size.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
