<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\OverridesEnvironment;

/**
 * Base case for console tests that need the application but not a database.
 *
 * The console app boots without touching Postgres — `console/bootstrap/app.php`
 * is `Application::configure()->withExceptions()->create()`, nothing more — and
 * a good deal of the logic worth testing (SupabaseEndpoint, the shape of
 * console:ping's output) only reads config and env. Those tests belong here,
 * where they run on a fresh clone with no credentials and no database.
 *
 * This is the second half of the story Tests\Unit\IdentifierSafetyTest started:
 * that file stays credential-free by extending PHPUnit's TestCase directly, which
 * works only because RoleName and SchemaName need no container. Anything that
 * calls config() cannot do that, and would otherwise be pushed into the
 * credentials-gated Feature suite for no reason other than needing a booted app.
 *
 * ## Two deliberate inversions of Tests\TestCase — do not "fix" them back
 *
 * 1. **refreshApplication() is NOT overridden.** Its sibling shares one
 *    Application per class to conserve Supabase pooler sessions. These tests open
 *    no sessions, so there is nothing to conserve — and a fresh application per
 *    test is exactly what makes config() mutations self-isolating instead of
 *    leaking into whatever runs next.
 * 2. **setUp() does NOT gate on credentials.** That gate is the whole reason the
 *    Feature suite needs the Supabase project. Adding one here "for symmetry"
 *    would make this suite silently skip everything on a machine without
 *    credentials, which is why `composer verify:console` runs with
 *    --fail-on-skipped.
 *
 * Container note: this class leaves Container::getInstance() pointing at its last
 * (flushed) Application, but Tests\TestCase builds a fresh Application on the
 * first test of every class it runs, and Application::registerBaseBindings()
 * calls Container::setInstance($this). So the container self-heals at class
 * boundaries and PHPUnit never interleaves classes.
 */
abstract class CredentialFreeTestCase extends BaseTestCase
{
    use OverridesEnvironment;

    /**
     * Keys that must not leak in from the developer's own console/.env.
     *
     * Cleared before every test so a default is genuinely a default. Without
     * this, a machine with CONSOLE_BRANCH_DB_PORT set locally would fail every
     * assertion about what the port falls back to.
     */
    private const HERMETIC_KEYS = [
        'CONSOLE_BRANCH_DB_HOST',
        'CONSOLE_BRANCH_DB_PORT',
        'CONSOLE_SUPABASE_PROJECT_REF',
        'CONSOLE_DB_URL',
    ];

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::HERMETIC_KEYS as $key) {
            $this->fakeEnv($key, null);
        }
    }
}
