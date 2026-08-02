<?php

namespace Tests\Unit;

use Provisioning\Support\SchemaVersion;
use Tests\CredentialFreeTestCase;

/**
 * The declared version pair is well-formed (M9).
 *
 * A cheap test for an expensive typo. These two constants are stamped into every
 * business schema, and `min_app_version` is one half of the comparison the sync
 * gate makes on every cycle of every branch — so a malformed value does not fail
 * loudly here, it changes which builds are admitted, everywhere, silently.
 *
 * `version_compare` is forgiving enough to make that real: it accepts almost any
 * string and sorts unrecognised parts as older than anything numeric, so a
 * fat-fingered '1.0.O' would compare as OLDER than '1.0.0' and quietly stop
 * freezing the branches a breaking migration was meant to freeze.
 */
class SchemaVersionTest extends CredentialFreeTestCase
{
    public function test_the_schema_version_is_a_positive_integer(): void
    {
        $this->assertGreaterThan(0, SchemaVersion::SCHEMA_VERSION);
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
}
