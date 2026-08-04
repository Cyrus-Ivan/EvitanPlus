<?php

namespace App\Support\Setup;

use Native\Laravel\Dialog;
use RuntimeException;

/**
 * NativePHP-backed {@see CredentialsFilePicker}. The native dialogs only exist
 * inside the desktop runtime, so it refuses when the app is served in a plain
 * browser (e.g. `php artisan serve`), where the developer types the path
 * instead.
 */
class NativeCredentialsFilePicker implements CredentialsFilePicker
{
    public function pick(?string $default = null): ?string
    {
        if (! config('nativephp-internal.running')) {
            throw new RuntimeException('Browsing for a file is only available in the desktop app.');
        }

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
}
