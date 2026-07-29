<?php

namespace App\Support\Backup;

use Native\Laravel\Dialog;
use RuntimeException;

/**
 * NativePHP-backed {@see DirectoryPicker}. The native dialogs only exist inside
 * the desktop runtime, so every method first refuses when the app is served in
 * a plain browser (e.g. `php artisan serve`).
 */
class NativeDialogPicker implements DirectoryPicker
{
    public function pickDirectory(?string $default = null): ?string
    {
        $this->ensureDesktop();

        $dialog = Dialog::new()
            ->title('Choose a folder for the backup')
            ->button('Back up here')
            ->folders();

        if ($default !== null && $default !== '') {
            $dialog->defaultPath($default);
        }

        return $dialog->open();
    }

    public function pickFile(?string $default = null): ?string
    {
        $this->ensureDesktop();

        $dialog = Dialog::new()
            ->title('Choose a database file to restore')
            ->button('Restore')
            ->files()
            ->filter('SQLite database', ['sqlite']);

        if ($default !== null && $default !== '') {
            $dialog->defaultPath($default);
        }

        return $dialog->open();
    }

    public function pickCredentialsFile(?string $default = null): ?string
    {
        $this->ensureDesktop();

        $dialog = Dialog::new()
            ->title('Choose the branch credentials file')
            ->button('Import')
            ->files()
            ->filter('Credentials JSON', ['json']);

        if ($default !== null && $default !== '') {
            $dialog->defaultPath($default);
        }

        return $dialog->open();
    }

    private function ensureDesktop(): void
    {
        if (! config('nativephp-internal.running')) {
            throw new RuntimeException('External backup is only available in the desktop app.');
        }
    }
}
