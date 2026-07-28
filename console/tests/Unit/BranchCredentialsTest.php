<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Provisioning\Support\BranchCredentials;
use RuntimeException;

/**
 * The credentials file is the only copy of a branch's password (§9), so the
 * question these tests ask is not "does it write" but "does it ever claim to
 * have written when it did not".
 *
 * Extends PHPUnit's TestCase directly: reserve/write/discard touch the
 * filesystem and nothing else, so no container and no credentials are needed —
 * the same reason Tests\Unit\IdentifierSafetyTest does it.
 */
class BranchCredentialsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/evitan-credentials-'.bin2hex(random_bytes(6));
    }

    /**
     * Restores permissions before removing, because one test deliberately makes
     * the directory unwritable and a 0500 directory cannot be cleaned up.
     */
    #[After]
    protected function removeDirectory(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }

        @chmod($this->directory, 0700);

        foreach ((array) glob($this->directory.'/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->directory);
    }

    public function test_it_reserves_a_path_at_0600_inside_a_0700_directory(): void
    {
        $credentials = new BranchCredentials;
        $path = $credentials->reserve($this->directory.'/mnl.json');

        $this->assertFileExists($path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->directory)), -4));
    }

    /** An existing path is a refusal, never an overwrite — it may be a delivered credential. */
    public function test_it_refuses_a_path_that_already_exists(): void
    {
        $credentials = new BranchCredentials;
        $path = $credentials->reserve($this->directory.'/mnl.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        (new BranchCredentials)->reserve($path);
    }

    /**
     * BranchIssuer skips discarding the file once a rotation is irreversible,
     * because it holds the only copy of the new secret. That premise only holds
     * after write() succeeds — and `irreversible` is set several steps earlier,
     * so without this predicate a failure in between preserved an EMPTY file
     * and then advised re-running a rotation that reserve() would refuse.
     */
    public function test_a_reserved_but_unwritten_file_does_not_count_as_written(): void
    {
        $credentials = new BranchCredentials;
        $credentials->reserve($this->directory.'/mnl.json');

        $this->assertFalse($credentials->wasWritten());

        $credentials->write(['format' => 'evitan.branch-credentials']);

        $this->assertTrue($credentials->wasWritten());
    }

    public function test_writing_produces_the_complete_document(): void
    {
        $credentials = new BranchCredentials;
        $path = $credentials->reserve($this->directory.'/mnl.json');

        $credentials->write(['format' => 'evitan.branch-credentials', 'format_version' => 1]);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('evitan.branch-credentials', $decoded['format']);
        $this->assertSame(1, $decoded['format_version']);
    }

    /**
     * The defect this guards is specifically NOT "fwrite returned false".
     *
     * fwrite returns a BYTE COUNT and returns false only when nothing at all
     * could be written; PHP's stream layer returns a SHORT COUNT when the
     * underlying write stops making progress mid-buffer. The old check tested
     * only for false, so a partially written credential — the shape a full disk
     * or a full USB stick produces — was reported as a verified success, and on
     * a rotate that strands the branch permanently.
     *
     * ShortWriteStream accepts a fixed prefix and reports exactly that, which
     * is the case no simpler fixture reproduces.
     */
    public function test_a_partial_write_is_an_error_not_a_success(): void
    {
        $credentials = new BranchCredentials;
        $path = $credentials->reserve($this->directory.'/mnl.json');

        $this->replaceHandle($credentials, ShortWriteStream::open());

        // Captured rather than asserted inside the catch: PHPUnit's own
        // AssertionFailedError extends RuntimeException, so a $this->fail()
        // in the try block would be swallowed by the catch below it.
        $message = null;

        try {
            $credentials->write(['format' => 'evitan.branch-credentials', 'format_version' => 1]);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertNotNull($message, 'A truncated credential was reported as a success.');
        $this->assertStringContainsString('Wrote only', $message);
        $this->assertStringContainsString($path, $message);
        $this->assertFalse($credentials->wasWritten());
    }

    /** The disk-full case proper, where the write lands nothing at all. */
    public function test_a_write_that_lands_nothing_is_an_error(): void
    {
        if (! is_writable('/dev/full')) {
            $this->markTestSkipped('/dev/full is not available.');
        }

        $credentials = new BranchCredentials;
        $credentials->reserve($this->directory.'/mnl.json');

        $this->replaceHandle($credentials, fopen('/dev/full', 'w'));

        $this->expectException(RuntimeException::class);

        @$credentials->write(['format' => 'evitan.branch-credentials']);
    }

    /** @param  resource  $handle */
    private function replaceHandle(BranchCredentials $credentials, $handle): void
    {
        $property = new \ReflectionProperty(BranchCredentials::class, 'handle');

        fclose($property->getValue($credentials));

        $property->setValue($credentials, $handle);
    }

    public function test_discard_removes_the_file(): void
    {
        $credentials = new BranchCredentials;
        $path = $credentials->reserve($this->directory.'/mnl.json');

        $credentials->write(['format' => 'evitan.branch-credentials']);
        $credentials->discard();

        $this->assertFileDoesNotExist($path);
    }

    /**
     * Throws rather than swallowing: both rollback loops collect the message
     * into $failures, and that list is the only thing standing between a
     * surviving 0600 credentials file and a report saying "Nothing was left
     * behind." A read-only parent directory is the reproducible version of the
     * mount that went read-only after reserve() succeeded.
     */
    public function test_a_file_it_cannot_remove_is_reported_rather_than_swallowed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions.');
        }

        $credentials = new BranchCredentials;
        $path = $credentials->reserve($this->directory.'/mnl.json');
        $credentials->write(['format' => 'evitan.branch-credentials']);

        chmod($this->directory, 0500);

        $message = null;

        try {
            $credentials->discard();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        } finally {
            chmod($this->directory, 0700);
        }

        $this->assertNotNull($message, 'A credentials file that survived the rollback was reported as removed.');
        $this->assertStringContainsString($path, $message);
        $this->assertFileExists($path);
    }
}

/**
 * A stream that accepts only a prefix of what it is handed.
 *
 * This is the failure PHP's own stream layer surfaces as a short return from
 * fwrite, and there is no simpler way to produce one on demand — a closed
 * handle raises a TypeError and /dev/full refuses the whole buffer, so neither
 * exercises the byte-count comparison that is the actual fix.
 */
class ShortWriteStream
{
    /** Bytes this stream takes before it stops making progress. */
    public const ACCEPTS = 5;

    /** @var resource|null Set by PHP for stream wrappers; unused here. */
    public $context;

    private bool $stalled = false;

    /** @return resource */
    public static function open()
    {
        if (! in_array('evitan-short-write', stream_get_wrappers(), true)) {
            stream_wrapper_register('evitan-short-write', self::class);
        }

        return fopen('evitan-short-write://sink', 'w');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /**
     * Accepts a prefix, then stops making progress.
     *
     * The stall is the point. PHP's stream layer LOOPS on a short return from a
     * user wrapper and only gives up when a write makes no progress at all, so
     * a wrapper that merely returned a small count every time would still
     * deliver the whole buffer and prove nothing.
     */
    public function stream_write(string $data): int
    {
        if ($this->stalled) {
            return 0;
        }

        $this->stalled = true;

        return min(self::ACCEPTS, strlen($data));
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void {}

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
