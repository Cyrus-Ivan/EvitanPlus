<?php

namespace App\Support\Backup;

/**
 * A native OS picker for choosing a backup destination folder or a database
 * file to restore from. Abstracted behind an interface so the backup controller
 * stays testable without the NativePHP desktop runtime (tests bind a fake).
 */
interface DirectoryPicker
{
    /**
     * Open a folder-selection dialog. Returns the chosen absolute path, or null
     * if the user cancelled.
     */
    public function pickDirectory(?string $default = null): ?string;

    /**
     * Open a file-selection dialog filtered to SQLite databases. Returns the
     * chosen absolute path, or null if the user cancelled.
     */
    public function pickFile(?string $default = null): ?string;

    /**
     * Open a file-selection dialog filtered to JSON, for the setup wizard's
     * branch credentials file (M5).
     *
     * Here rather than in a picker of its own because the wizard needs exactly
     * what backup/restore needs — an OS dialog inside the desktop runtime, and a
     * fake in tests — and a second interface would duplicate that binding to say
     * one different word in a title bar.
     */
    public function pickCredentialsFile(?string $default = null): ?string;
}
