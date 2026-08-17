<?php

namespace Tests\Unit\Sync;

use App\Exceptions\VersionBlockedException;
use App\Support\Sync\VersionGate;
use Tests\TestCase;

/**
 * The version gate compares APP VERSION against MIN APP VERSION, and nothing
 * else (MULTIBRANCH-BRAINSTORM.md §13).
 *
 * Worth its own test because the failure mode is silent in both directions: a
 * gate that compares the wrong pair still passes on a matched pair, and a gate
 * that never refuses looks identical to one that has nothing to refuse.
 */
class VersionGateTest extends TestCase
{
    private function gate(string $appVersion): VersionGate
    {
        config(['nativephp.version' => $appVersion]);

        return new VersionGate;
    }

    public function test_a_newer_build_passes(): void
    {
        $this->gate('1.2.0')->assert('1.0.0');

        $this->addToAssertionCount(1);
    }

    public function test_an_equal_build_passes(): void
    {
        // The comparison is >=, so the boundary belongs on the passing side —
        // a schema declaring the version it was built against must not freeze
        // the very build it was built against.
        $this->gate('1.0.0')->assert('1.0.0');

        $this->addToAssertionCount(1);
    }

    public function test_an_older_build_is_refused_and_says_both_numbers(): void
    {
        try {
            $this->gate('1.0.0')->assert('1.2.0');

            $this->fail('A build below min_app_version must be refused.');
        } catch (VersionBlockedException $e) {
            $this->assertSame('1.0.0', $e->appVersion);
            $this->assertSame('1.2.0', $e->minAppVersion);

            // The person reading this is standing at a till; the remedy has to
            // be in the sentence.
            $this->assertStringContainsString('1.2.0', $e->getMessage());
            $this->assertStringContainsString('newer build', $e->getMessage());
        }
    }

    public function test_patch_and_minor_components_are_compared_numerically(): void
    {
        // The trap version_compare exists to avoid: '1.10.0' is NEWER than
        // '1.9.0' but sorts before it as a string.
        $this->gate('1.10.0')->assert('1.9.0');

        $this->expectException(VersionBlockedException::class);
        $this->gate('1.9.0')->assert('1.10.0');
    }

    public function test_the_app_version_comes_from_the_build(): void
    {
        $this->assertSame('9.9.9', $this->gate('9.9.9')->appVersion());
    }

    /**
     * A build that cannot say what version it is must not freeze the business.
     *
     * `version_compare('', '1.0.0', '<')` is TRUE, so an empty
     * `nativephp.version` — shipped with the env key blank, or a config cache
     * written before it existed — would block sync in both directions AND every
     * online write, at every branch at once, on a floor no migration raised. A
     * missing version is a packaging fault; the safe reading is "as old as the
     * product has ever been".
     */
    public function test_a_build_with_no_version_is_not_blocked_by_every_schema(): void
    {
        config(['nativephp.version' => '']);

        $gate = app(VersionGate::class);

        $this->assertSame('1.0.0', $gate->appVersion());

        // Clears the lowest floor...
        $gate->assert('1.0.0');

        // ...and still trips one a breaking migration really did raise.
        $this->expectException(VersionBlockedException::class);
        $gate->assert('1.1.0');
    }
}
