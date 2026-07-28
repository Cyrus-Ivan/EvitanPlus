<?php

namespace Tests\Feature\Build;

use DirectoryIterator;
use Illuminate\Support\Str;
use Native\Electron\Traits\CopiesToBuildDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase M0 security gate (MULTIBRANCH-BRAINSTORM.md §18).
 *
 * Proves the Provisioning Console and its admin credentials cannot reach a
 * release build. This complements — it does not replace — the manual
 * AppImage-extraction check in README.md, "Building a release", step 4.
 */
class BuildExclusionTest extends TestCase
{
    /**
     * NativePHP's own hardcoded excludes merged with this project's config,
     * exactly as CopiesToBuildDirectory::copyToBuildDirectory() merges them.
     *
     * Read off the real trait rather than copy-pasted, so upstream's list is
     * part of the assertion instead of a stale duplicate.
     *
     * @return list<string>
     */
    private function patterns(): array
    {
        $copier = new class
        {
            use CopiesToBuildDirectory;

            protected function buildPath(string $path = ''): string
            {
                return $path;
            }

            protected function sourcePath(string $path = ''): string
            {
                return $path;
            }
        };

        return array_merge(
            $copier->cleanupExcludeFiles,
            config('nativephp.cleanup_exclude_files', []),
        );
    }

    /**
     * Model CopiesToBuildDirectory's RecursiveCallbackFilterIterator: a path is
     * dropped if it matches a pattern OR if ANY of its ancestors does, because
     * rejecting a directory prunes the whole subtree.
     *
     * The ancestor walk is load-bearing, not defensive coding. A naive
     * per-path fnmatch() reports `console/.env` as KEPT, because the pattern
     * matches the parent directory and never the file — the iterator simply
     * never descends far enough to look at it. That false negative is the
     * exact trap this test exists to catch.
     */
    private function isPruned(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        $patterns = $this->patterns();

        for ($depth = 1; $depth <= count($segments); $depth++) {
            $prefix = implode('/', array_slice($segments, 0, $depth));

            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function test_the_provisioning_console_exists_and_is_pruned_from_the_build(): void
    {
        $this->assertDirectoryExists(
            base_path('console'),
            'Phase M0 requires a separate provisioning console app at console/.'
        );

        // Walk the real repo root — the exact set of entries NativePHP's
        // iterator sees at depth 1. `console` must not be among the survivors.
        $survivors = [];

        foreach (new DirectoryIterator(base_path()) as $entry) {
            if (! $entry->isDot() && ! $this->isPruned($entry->getFilename())) {
                $survivors[] = $entry->getFilename();
            }
        }

        $this->assertNotContains('console', $survivors);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function prunedPaths(): array
    {
        return array_map(fn (string $path): array => [$path], [
            'console',
            'console/.env',
            'console/.env.example',
            'console/composer.json',
            'console/artisan',
            'console/bootstrap/app.php',
            'console/bootstrap/cache/packages.php',
            'console/config/database.php',
            'console/app/Console/Commands/PingCommand.php',
            'console/app/Console/Commands/BusinessProvisionCommand.php',
            'console/app/Support/BranchRole.php',
            'console/app/Support/BusinessProvisioner.php',
            'console/database/migrations/.gitkeep',
            'console/database/migrations-admin/2026_07_27_000001_create_provisioning_audit_table.php',
            'console/vendor/autoload.php',
            'console/storage/logs/laravel.log',
            // Phase M2's delivery artifact: a branch's credentials JSON holds a
            // live database password in plaintext. It must be as unshippable as
            // the console code that produced it.
            'console/storage/app/credentials/biz_deadbeef-MNL-20260727T091244Z.json',
            'MULTIBRANCH-BRAINSTORM.md',
            'MULTIBRANCH-ROADMAP.md',
        ]);
    }

    #[DataProvider('prunedPaths')]
    public function test_console_paths_are_pruned(string $path): void
    {
        $this->assertTrue($this->isPruned($path), "Expected [{$path}] to be excluded from the build.");
    }

    /**
     * Guards against an over-broad pattern quietly nuking shipped code — the
     * failure mode that would only surface as a broken AppImage.
     *
     * @return list<array{0: string}>
     */
    public static function keptPaths(): array
    {
        return array_map(fn (string $path): array => [$path], [
            'artisan',
            'composer.json',
            'config/database.php',
            'config/nativephp.php',
            'app/Models/User.php',
            'app/Http/Middleware/HandleInertiaRequests.php',
            'routes/console.php',
            'routes/web.php',
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'resources/js/app.tsx',
            'public/build/manifest.json',
            'vendor/autoload.php',
            'vendor/symfony/console/Command/Command.php',
            'vendor/laravel/framework/src/Illuminate/Console/Command.php',
        ]);
    }

    #[DataProvider('keptPaths')]
    public function test_shipped_application_paths_survive(string $path): void
    {
        $this->assertFalse($this->isPruned($path), "Expected [{$path}] to REMAIN in the build.");
    }

    public function test_the_bundle_finder_receives_a_bare_directory_name(): void
    {
        // BundleCommand also feeds cleanup_exclude_files to Symfony Finder's
        // exclude(), which only treats slash-free entries as directory names —
        // 'console/*' would be preg_quote()d into a regex that never matches.
        $patterns = config('nativephp.cleanup_exclude_files');

        $this->assertContains('console', $patterns);
        $this->assertNotContains('console/*', $patterns);
    }

    public function test_admin_and_branch_credentials_are_stripped_from_a_shipped_env(): void
    {
        $keys = config('nativephp.cleanup_env_keys', []);

        foreach ([
            'CONSOLE_DB_HOST',
            'CONSOLE_DB_USERNAME',
            'CONSOLE_DB_PASSWORD',
            // The branch endpoint the console emits into credentials JSONs. Named
            // CONSOLE_BRANCH_DB_* precisely so the existing 'CONSOLE_*' pattern
            // already covers it — these two assertions are what hold that claim
            // to account if the pattern is ever narrowed.
            'CONSOLE_BRANCH_DB_HOST',
            'CONSOLE_BRANCH_DB_PORT',
            'BRANCH_DB_HOST',
            'BRANCH_DB_USERNAME',
            'BRANCH_DB_PASSWORD',
            'SUPABASE_SERVICE_ROLE_KEY',
        ] as $key) {
            $this->assertTrue(Str::is($keys, $key), "Expected [{$key}] to be stripped from the shipped .env.");
        }

        // ...without collateral damage to the POS app's own local settings.
        foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_FOREIGN_KEYS', 'APP_NAME', 'APP_TIMEZONE'] as $key) {
            $this->assertFalse(Str::is($keys, $key), "[{$key}] must survive into the shipped .env.");
        }
    }

    /**
     * Honesty guard. isPruned() replicates NativePHP's matching semantics; if
     * NativePHP changes them, the replication silently stops proving anything.
     * Fail loudly instead, and re-derive isPruned() from the new source.
     */
    public function test_nativephp_still_uses_the_filter_semantics_this_test_replicates(): void
    {
        $file = (new ReflectionClass(CopiesToBuildDirectory::class))->getFileName();

        $this->assertIsString($file);

        $source = file_get_contents($file);

        $this->assertStringContainsString('RecursiveCallbackFilterIterator', $source);
        $this->assertStringContainsString("config('nativephp.cleanup_exclude_files', [])", $source);
        $this->assertStringContainsString('fnmatch($pattern, $relativePath)', $source);
        $this->assertStringNotContainsString('FNM_PATHNAME', $source);
        $this->assertTrue(property_exists(CopiesToBuildDirectory::class, 'cleanupExcludeFiles'));
    }
}
