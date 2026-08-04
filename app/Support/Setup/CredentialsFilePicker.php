<?php

namespace App\Support\Setup;

/**
 * A native OS picker for the setup wizard's branch credentials file (M5).
 *
 * Abstracted behind an interface so SetupController stays testable without the
 * NativePHP desktop runtime — tests bind a fake.
 *
 * It lived in `App\Support\Backup` until M11.6, alongside the folder and
 * database-file pickers the backup feature's external-drive actions used. With
 * that feature removed this is the only OS dialog the app opens, so it moved to
 * the namespace of the one thing that asks for it.
 */
interface CredentialsFilePicker
{
    /**
     * Open a file-selection dialog filtered to JSON. Returns the chosen absolute
     * path, or null if the user cancelled.
     */
    public function pick(?string $default = null): ?string;
}
