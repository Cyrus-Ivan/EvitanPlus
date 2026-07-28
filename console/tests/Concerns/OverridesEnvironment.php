<?php

namespace Tests\Concerns;

use PHPUnit\Framework\Attributes\After;

/**
 * Set and restore environment variables that `env()` will actually see.
 *
 * ## Why this is not just putenv()
 *
 * Laravel builds its env repository with `RepositoryBuilder::createWithDefaultAdapters()`
 * — ServerConst, then EnvConst — and only THEN appends PutenvAdapter. A
 * MultiReader returns the first adapter that has the key, so `$_SERVER` and
 * `$_ENV` both beat `putenv()`. Measured against the booted console app: an
 * absent key returns the putenv value, but a key present in `$_SERVER` as `''`
 * returns `''` no matter what putenv says.
 *
 * That is not a theoretical edge. `console/.env.example` ships
 * CONSOLE_BRANCH_DB_HOST, CONSOLE_BRANCH_DB_PORT and CONSOLE_SUPABASE_PROJECT_REF
 * **present but blank**, and Dotenv writes blanks into `$_SERVER`/`$_ENV`. So a
 * putenv-only override passes for a developer whose .env predates those keys and
 * fails for anyone who copied the example — the worst kind of flake, because it
 * is invisible on the machine that wrote the test.
 *
 * All three channels, every time, in both directions.
 *
 * ## Restoration runs on PHPUnit's lifecycle, not Laravel's
 *
 * #[After], deliberately — NOT beforeApplicationDestroyed(). Laravel only fires
 * those callbacks `if ($this->app)`, and Tests\TestCase::tearDown() nulls
 * $this->app first (on purpose, so the shared Application survives the test), so
 * the hook is silently dead for every Feature test. That is not hypothetical: it
 * leaked CONSOLE_BRANCH_DB_HOST=127.0.0.1 out of the live PingCommandTest and
 * into ProvisioningAuditTest, which then failed for a reason that had nothing to
 * do with what it was testing.
 *
 * #[After] runs regardless of the application's state, and regardless of whether
 * the test passed, failed or threw. PHPUnit's backupGlobals is off by default and
 * would not restore putenv anyway, so nothing else is going to undo this.
 */
trait OverridesEnvironment
{
    /**
     * Prior state per key: [putenv|server|env => string|null].
     *
     * @var array<string, array{putenv: string|false, server: mixed, env: mixed}>
     */
    private array $environmentOverrides = [];

    /**
     * Set an environment variable, or remove it entirely when $value is null.
     *
     * Removal is a real case, not a convenience: a test that asserts a DEFAULT
     * has to clear the key first, or a developer's own .env decides the result.
     */
    protected function fakeEnv(string $key, ?string $value): void
    {
        // First touch wins: a test that overrides the same key twice must be
        // restored to what it was before the test, not to its own first value.
        if (! \array_key_exists($key, $this->environmentOverrides)) {
            $this->environmentOverrides[$key] = [
                'putenv' => getenv($key),
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
            ];
        }

        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }

    #[After]
    protected function restoreEnvironment(): void
    {
        foreach ($this->environmentOverrides as $key => $prior) {
            $prior['putenv'] === false
                ? putenv($key)
                : putenv("{$key}={$prior['putenv']}");

            if ($prior['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $prior['server'];
            }

            if ($prior['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $prior['env'];
            }
        }

        $this->environmentOverrides = [];
    }
}
