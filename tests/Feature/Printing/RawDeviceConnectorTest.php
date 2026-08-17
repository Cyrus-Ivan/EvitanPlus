<?php

namespace Tests\Feature\Printing;

use App\Support\Printing\Connectors\RawDeviceConnector;
use App\Support\Printing\PrinterException;
use PHPUnit\Framework\TestCase;

/**
 * The USB/Bluetooth connector, which is the DEFAULT receipt connection and was
 * the only one of the three with no timeout at all.
 *
 * No database and no application — this is a stream, so it extends PHPUnit's
 * base case directly rather than the app's.
 */
class RawDeviceConnectorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    /** @var list<resource> */
    private array $handles = [];

    protected function tearDown(): void
    {
        foreach ($this->handles as $handle) {
            @fclose($handle);
        }

        foreach ($this->paths as $path) {
            @unlink($path);
        }

        $this->handles = [];
        $this->paths = [];

        parent::tearDown();
    }

    public function test_it_writes_every_byte_to_the_device(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rawdev');
        $this->paths[] = $path;

        // Comfortably past one buffer's worth, so a short write would show up as
        // truncation rather than passing by luck.
        $bytes = str_repeat('ESC/POS', 40_000);

        (new RawDeviceConnector($path))->write($bytes);

        $this->assertSame($bytes, file_get_contents($path));
    }

    /**
     * A printer that has stopped accepting data gives up and says so.
     *
     * Simulated with a FIFO whose reader is open but idle: the writer's `fopen`
     * succeeds, the pipe fills at the kernel's buffer size (64 KiB by default),
     * and every `fwrite` after that accepts nothing — exactly what a thermal
     * printer that is out of paper or paused does. Before the bounded write loop
     * this parked `fwrite()` forever and took the PHP worker with it, so this
     * test did not hang: it never finished.
     *
     * The alarm below is what keeps a regression LOUD instead of silent. If the
     * non-blocking write is ever removed this test would otherwise hang the whole
     * suite, which is the one outcome worse than the bug.
     */
    public function test_a_device_that_accepts_nothing_fails_instead_of_hanging(): void
    {
        if (! extension_loaded('posix') || ! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('Needs POSIX FIFOs to simulate a printer that has stalled.');
        }

        $path = sys_get_temp_dir().'/rawdev-stalled-'.getmypid().'-'.uniqid();
        $this->assertTrue(posix_mkfifo($path, 0600), 'Could not create the FIFO standing in for the printer.');
        $this->paths[] = $path;

        // Open the read end first and never read from it, so the writer's open
        // succeeds and the pipe then fills and stays full.
        $reader = fopen($path, 'rb+');
        $this->assertNotFalse($reader);
        $this->handles[] = $reader;

        $this->guardAgainstHanging(seconds: 20);

        $started = microtime(true);

        try {
            // 1 MiB against a 64 KiB pipe that nobody is draining.
            (new RawDeviceConnector($path, timeout: 0.5))->write(str_repeat('x', 1_048_576));

            $this->fail('A printer accepting nothing should have raised a PrinterException.');
        } catch (PrinterException $e) {
            $this->assertStringContainsString('stopped accepting data', $e->getMessage());
        } finally {
            $this->releaseGuard();
        }

        $this->assertLessThan(
            10,
            microtime(true) - $started,
            'The connector honoured no deadline — it gave up only because the payload ran out.',
        );
    }

    /**
     * Turn a hang into a failure. `pcntl` ships with the packaged runtime and is
     * present on every dev machine here; where it is not, the wall-clock
     * assertion above is the remaining (weaker) net.
     */
    private function guardAgainstHanging(int $seconds): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function (): void {
            $this->fail('The connector blocked indefinitely on a device that accepts no data.');
        });
        pcntl_alarm($seconds);
    }

    private function releaseGuard(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
}
