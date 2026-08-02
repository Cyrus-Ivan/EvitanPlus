<?php

namespace Tests\Unit;

use Provisioning\Support\SchemaVersion;
use Tests\CredentialFreeTestCase;

/**
 * The version pair is well-formed, in step with the migration set, and buildable
 * (M9, and the follow-on that made the discipline more than a docblock).
 *
 * ## Why a tripwire rather than a checker
 *
 * Of the three ways the pair goes wrong, only two can be decided by a machine:
 *
 *   1. a migration added without raising SCHEMA_VERSION — checkable, and checked
 *      below;
 *   2. a BREAKING migration without raising MIN_APP_VERSION — **not** checkable
 *      by anything, ever. Whether a change breaks an older build is a claim
 *      about what that build's code does, and no amount of introspection here
 *      can answer it;
 *   3. a floor above the app this repo can build — checkable, and checked below.
 *
 * (2) is the dangerous one and the one nothing can catch. But the failure in
 * practice is *forgetting to ask*, not misjudging the answer — so (1) is worth
 * enforcing well beyond its own small consequence: the same edit that trips it
 * is the edit where (2) has to be considered, and its failure message is where
 * the question gets asked out loud.
 *
 * ## Why the format checks earn their place
 *
 * `version_compare` accepts almost any string and sorts unrecognised parts as
 * older than anything numeric, so a fat-fingered '1.0.O' compares as OLDER than
 * '1.0.0' and quietly stops freezing the branches a breaking migration was meant
 * to freeze. Nothing about that fails loudly; it just changes which builds are
 * admitted, on every branch of every business.
 */
class SchemaVersionTest extends CredentialFreeTestCase
{
    /**
     * The tripwire. Its failure message is the point of the test — it has to
     * name BOTH decisions, because the one that fails is never the dangerous
     * one.
     */
    public function test_the_schema_version_is_the_migration_count(): void
    {
        $onDisk = SchemaVersion::migrationCount();

        $this->assertGreaterThan(0, $onDisk, 'No migrations found — is SchemaVersion::migrationPath() right?');

        $this->assertSame(
            $onDisk,
            SchemaVersion::SCHEMA_VERSION,
            "The migration set has changed. Decide both of these now, in this order:\n"
            ."  1. Set SCHEMA_VERSION to {$onDisk}. Always — it is what a schema records about\n"
            ."     itself, and a version that drifts from the files describes nothing.\n"
            ."  2. Raise MIN_APP_VERSION ONLY IF an app build older than the next release cannot\n"
            ."     safely push to or pull from this change. An additive migration must leave it\n"
            .'     alone: raising it freezes the sync of every branch of every business until '
            ."each\n     device gets a new build.\n"
            .'Both live in Provisioning\Support\SchemaVersion.',
        );
    }

    public function test_the_min_app_version_is_plain_semver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            SchemaVersion::MIN_APP_VERSION,
            'min_app_version must be bare MAJOR.MINOR.PATCH — it is compared against the app build, '
            .'which config(nativephp.version) supplies in exactly that shape.',
        );
    }

    /**
     * A floor above the newest build in existence freezes every branch with
     * nothing to install, and no amount of waiting or re-syncing fixes it — the
     * remedy is a code change and a release. Unambiguously a bug, so it is
     * refused here rather than discovered at a till.
     *
     * The app's version is read as TEXT and never loaded: the console is a
     * separate application with its own autoloader, and §18 forbids a RUNTIME
     * reference in either direction. BusinessSettingKeysParityTest crosses the
     * same boundary the same way, for the same reason.
     */
    public function test_the_floor_is_not_above_the_app_this_repo_builds(): void
    {
        $appVersion = $this->appVersion();

        $this->assertTrue(
            version_compare($appVersion, SchemaVersion::MIN_APP_VERSION, '>='),
            'MIN_APP_VERSION is '.SchemaVersion::MIN_APP_VERSION." but this repo builds {$appVersion}. "
            .'Every branch would be version-blocked with no build to install. Bump the version '
            .'default in config/nativephp.php in the same change (README, "Building a release").',
        );
    }

    /**
     * `config/nativephp.php`'s default IS the app version: NATIVEPHP_APP_VERSION
     * is set nowhere in the repo, and README step 1 of "Building a release" names
     * that default as the thing you bump.
     */
    private function appVersion(): string
    {
        $path = dirname(__DIR__, 3).'/config/nativephp.php';

        $this->assertFileExists($path, 'The app config moved; this guard needs re-pointing.');

        $matched = preg_match(
            "/'version'\s*=>\s*env\(\s*'NATIVEPHP_APP_VERSION'\s*,\s*'([^']+)'/",
            (string) file_get_contents($path),
            $matches,
        );

        $this->assertSame(1, $matched, "Could not read the app's version default out of config/nativephp.php.");

        return $matches[1];
    }
}
