<?php

namespace Tests\Feature\Build;

use Native\Electron\Traits\LocatesPhpBinary;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Release-runtime gate (MULTIBRANCH-ROADMAP.md M0, MULTIBRANCH-BRAINSTORM.md §5).
 *
 * The packaged app talks to local SQLite *and* to branch Postgres, so the one
 * PHP binary NativePHP bundles must carry both drivers. NativePHP's own
 * nativephp/php-bin zips ship pdo_sqlite and no pgsql, which is why we vendor a
 * custom static build under build/php — see build/php/README.md.
 *
 * There is no "combining" of runtimes at build time: php.js unzips exactly one
 * archive into resources/php/php. Whatever that binary lacks, the app lacks —
 * and it lacks it at runtime, in front of a cashier, not here. Hence this test.
 */
class PhpBinaryTest extends TestCase
{
    /**
     * Extraction costs ~60MB of I/O; do it once for the whole class.
     */
    private static ?string $extractedBinary = null;

    /**
     * The zip NativePHP will actually reach for on this host, resolved through
     * the real trait rather than a hardcoded path, so a change to NativePHP's
     * lookup shows up here instead of in a broken AppImage.
     */
    private function binaryZipPath(): string
    {
        $locator = new class
        {
            use LocatesPhpBinary;
        };

        // php.js appends "<os>/<arch>/php-<minor>.zip" to what the PHP side
        // hands it (BuildCommand passes sourcePath(phpBinaryPath()), i.e. this
        // path resolved against the project root — NOT the build copy).
        return base_path($locator->phpBinaryPath())
            .'linux/x64/php-'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.zip';
    }

    /**
     * Unpacks the shipped zip to a temp dir and returns the executable path.
     */
    private function shippedBinary(): string
    {
        if (self::$extractedBinary !== null) {
            return self::$extractedBinary;
        }

        $zipPath = $this->binaryZipPath();
        $target = sys_get_temp_dir().'/evitan-php-binary-test-'.getmypid();

        if (! is_dir($target) && ! mkdir($target, 0755, true) && ! is_dir($target)) {
            throw new RuntimeException("Could not create [{$target}].");
        }

        $zip = new ZipArchive;

        $this->assertTrue($zip->open($zipPath) === true, "Could not open [{$zipPath}].");
        $this->assertTrue($zip->extractTo($target), "Could not extract [{$zipPath}].");

        $zip->close();

        $binary = $target.'/php';

        $this->assertFileExists($binary, 'The shipped zip must contain a single `php` executable.');

        chmod($binary, 0755);

        return self::$extractedBinary = $binary;
    }

    /**
     * Runs a snippet through the *shipped* binary, not the host PHP.
     */
    private function runInShippedBinary(string $code): string
    {
        $command = escapeshellarg($this->shippedBinary()).' -r '.escapeshellarg($code).' 2>&1';
        $output = shell_exec($command);

        $this->assertIsString($output, 'The shipped PHP binary produced no output — is it executable on this host?');

        return trim($output);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The vendored binary is a Linux x64 static build; the release target
        // is Linux only (README, "Building a release").
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            $this->markTestSkipped('The shipped PHP runtime is a Linux x64 static build.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$extractedBinary !== null) {
            @unlink(self::$extractedBinary);
            @rmdir(dirname(self::$extractedBinary));
            self::$extractedBinary = null;
        }

        parent::tearDownAfterClass();
    }

    public function test_the_build_points_at_the_vendored_runtime(): void
    {
        $configured = env('NATIVEPHP_PHP_BINARY_PATH');

        $this->assertNotEmpty(
            $configured,
            'NATIVEPHP_PHP_BINARY_PATH must point at build/php/, or the build silently '
            .'falls back to nativephp/php-bin, which has no Postgres driver.'
        );

        // PrunesVendorDirectory removes <build copy>/<this path>; with './' that
        // is the entire copied app and the build dies mid-flight.
        $this->assertNotSame('./', $configured, 'NATIVEPHP_PHP_BINARY_PATH must never be "./".');
        $this->assertStringEndsWith('/', (string) $configured, 'NativePHP concatenates "bin/" onto this path.');
    }

    public function test_a_runtime_exists_for_this_hosts_php_minor(): void
    {
        $zipPath = $this->binaryZipPath();

        // Host-minor coupling: NativePHP asks for php-<host minor>.zip. A dev
        // machine on a different minor builds against a missing file and the
        // failure only surfaces as an app that will not boot.
        $this->assertFileExists(
            $zipPath,
            'No shipped PHP runtime for host PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'. '
            .'Either build one (build/php/README.md) or match your dev PHP minor to the vendored zip.'
        );
    }

    public function test_the_shipped_runtime_provides_both_sqlite_and_postgres_drivers(): void
    {
        $drivers = explode(',', $this->runInShippedBinary('echo implode(",", PDO::getAvailableDrivers());'));

        $this->assertContains('sqlite', $drivers, 'The app stores every local record in SQLite.');
        $this->assertContains('pgsql', $drivers, 'Branch sync talks to Supabase Postgres directly.');
    }

    public function test_the_shipped_runtime_can_actually_open_both_connections(): void
    {
        // Loaded-extension lists can lie about a driver that fails to initialise;
        // instantiating is the honest check. Postgres gets no server here, so a
        // connection-level failure is a pass — "could not find driver" is not.
        $this->assertSame(
            'sqlite:ok pgsql:driver-present',
            $this->runInShippedBinary(<<<'PHP'
                $sqlite = new PDO("sqlite::memory:");
                $sqlite->exec("create table t (id integer primary key)");
                $sqlite->exec("insert into t (id) values (1)");
                echo "sqlite:", ($sqlite->query("select count(*) from t")->fetchColumn() === 1 ? "ok" : "bad");
                try {
                    new PDO("pgsql:host=127.0.0.1;port=1;dbname=nope", "nope", "nope");
                    echo " pgsql:driver-present";
                } catch (PDOException $e) {
                    echo " pgsql:", str_contains($e->getMessage(), "could not find driver")
                        ? "MISSING"
                        : "driver-present";
                }
            PHP)
        );
    }

    /**
     * Extensions NativePHP's own runtime ships that ours may omit, each with a
     * reason. Anything not listed here is a regression.
     *
     * @var array<string, string>
     */
    private const ALLOWED_GAPS = [
        // Nothing in the app compresses with bzip2, or with anything else.
        'bz2' => 'unused: no code path in the app compresses data',
    ];

    /**
     * php-extensions.txt names build flags, not always module names.
     *
     * @var array<string, string>
     */
    private const MODULE_ALIASES = [
        'pdo' => 'PDO',
        'opcache' => 'Zend OPcache',
    ];

    public function test_the_shipped_runtime_covers_nativephps_stock_extension_set(): void
    {
        $stock = array_filter(array_map(
            'trim',
            explode(',', (string) file_get_contents(base_path('vendor/nativephp/php-bin/php-extensions.txt')))
        ));

        $modules = array_map('strtolower', explode("\n", trim($this->runInShippedBinary(
            'echo implode("\n", get_loaded_extensions());'
        ))));

        foreach ($stock as $extension) {
            if (array_key_exists($extension, self::ALLOWED_GAPS)) {
                continue;
            }

            // mbregex is a mbstring build flag, not a separate module.
            if ($extension === 'mbregex') {
                $this->assertSame(
                    'true',
                    $this->runInShippedBinary('echo function_exists("mb_ereg") ? "true" : "false";'),
                    'The shipped runtime was built without mbregex support.'
                );

                continue;
            }

            $expected = strtolower(self::MODULE_ALIASES[$extension] ?? $extension);

            $this->assertContains(
                $expected,
                $modules,
                "The shipped PHP runtime is missing [{$extension}], which NativePHP's own runtime provides. "
                .'Rebuild it (build/php/README.md) or add a documented entry to ALLOWED_GAPS.'
            );
        }
    }

    /**
     * @return list<array{0: string}>
     */
    public static function knownAbsentExtensions(): array
    {
        return array_map(fn (string $extension): array => [$extension], array_keys(self::ALLOWED_GAPS));
    }

    /**
     * Keeps ALLOWED_GAPS honest: once a gap is closed by a rebuild, the waiver
     * should be deleted rather than left to hide a future regression.
     */
    #[DataProvider('knownAbsentExtensions')]
    public function test_waived_extensions_are_still_actually_absent(string $extension): void
    {
        $this->assertSame(
            'false',
            $this->runInShippedBinary('echo extension_loaded("'.$extension.'") ? "true" : "false";'),
            "[{$extension}] is now present in the shipped runtime — drop it from ALLOWED_GAPS."
        );
    }

    /**
     * Honesty guard. This test reconstructs NativePHP's binary lookup; if
     * NativePHP changes it, the reconstruction stops proving anything. Fail
     * loudly instead, and re-derive binaryZipPath() from the new source.
     */
    public function test_nativephp_still_resolves_the_runtime_the_way_this_test_does(): void
    {
        $trait = (new ReflectionClass(LocatesPhpBinary::class))->getFileName();

        $this->assertIsString($trait);

        $source = (string) file_get_contents($trait);

        $this->assertStringContainsString("env('NATIVEPHP_PHP_BINARY_PATH'", $source);
        $this->assertStringContainsString("binaryPackageDirectory().'bin/'", $source);

        $js = (string) file_get_contents(base_path('vendor/nativephp/electron/resources/js/php.js'));

        $this->assertStringContainsString("'php-' + phpVersion + '.zip'", $js);
        $this->assertStringContainsString('join(phpBinaryPath, platform.os, platform.arch, phpVersionZip)', $js);
    }
}
