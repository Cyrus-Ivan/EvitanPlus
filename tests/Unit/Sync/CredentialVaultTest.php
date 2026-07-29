<?php

namespace Tests\Unit\Sync;

use App\Exceptions\SetupFailedException;
use App\Models\InstanceSecret;
use App\Support\Sync\CredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branch credential at rest, and the fingerprint the console can match.
 */
class CredentialVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sealed_credential_round_trips(): void
    {
        $vault = new CredentialVault;

        $connection = ['driver' => 'pgsql', 'host' => 'pooler', 'password' => 'a-branch-secret', 'search_path' => null];

        InstanceSecret::query()->create([
            'id' => InstanceSecret::ID,
            'dsn_ciphertext' => $vault->seal($connection),
            'fingerprint' => $vault->fingerprint('a-branch-secret'),
            'imported_at' => now(),
        ]);

        $this->assertSame($connection, $vault->open());
    }

    public function test_the_password_is_not_readable_in_the_stored_row(): void
    {
        $vault = new CredentialVault;

        $ciphertext = $vault->seal(['password' => 'a-branch-secret']);

        $this->assertStringNotContainsString('a-branch-secret', $ciphertext);
    }

    public function test_a_device_that_has_never_been_set_up_has_nothing_to_open(): void
    {
        $this->assertNull((new CredentialVault)->open());
    }

    public function test_ciphertext_from_another_install_is_refused_with_the_remedy(): void
    {
        // The realistic cause is a SQLite file moved between installs: the rows
        // came along, the per-install APP_KEY did not.
        InstanceSecret::query()->create([
            'id' => InstanceSecret::ID,
            'dsn_ciphertext' => 'eyJpdiI6InNvbWV0aGluZyBlbHNlIn0=',
            'fingerprint' => 'sha256:whatever',
            'imported_at' => now(),
        ]);

        try {
            (new CredentialVault)->open();

            $this->fail('Undecryptable ciphertext must be refused.');
        } catch (SetupFailedException $e) {
            $this->assertStringContainsString('setup again', $e->getMessage());
        }
    }

    public function test_the_fingerprint_is_stable_and_domain_separated(): void
    {
        $vault = new CredentialVault;

        $this->assertSame($vault->fingerprint('secret'), $vault->fingerprint('secret'));
        $this->assertNotSame($vault->fingerprint('secret'), $vault->fingerprint('secret '));

        // Not a bare sha256 of the secret: the domain separator is what keeps
        // this digest from colliding with one computed elsewhere for another
        // purpose, and the prefix leaves room to change algorithm later.
        $this->assertNotSame('sha256:'.hash('sha256', 'secret'), $vault->fingerprint('secret'));
        $this->assertStringStartsWith('sha256:', $vault->fingerprint('secret'));
    }

    /**
     * The console computes the same digest for `credential_issuance`, and the
     * comparison it exists for only works while both sides agree byte for byte.
     * §18 forbids either app referencing the other's code, so the constant is
     * duplicated — and duplication without a guard is duplication that drifts.
     *
     * The console file is read as TEXT, never loaded: the same dev-time
     * file-read justification SchemaParityTest and BusinessSettingKeysParityTest
     * already document.
     */
    public function test_the_fingerprint_domain_matches_the_consoles(): void
    {
        $source = (string) file_get_contents(base_path('console/app/Support/BranchSecret.php'));

        preg_match("/FINGERPRINT_DOMAIN\s*=\s*'([^']+)'/", $source, $console);
        preg_match(
            "/FINGERPRINT_DOMAIN\s*=\s*'([^']+)'/",
            (string) file_get_contents(app_path('Support/Sync/CredentialVault.php')),
            $app,
        );

        $this->assertNotEmpty($console[1] ?? '', "Could not read the console's fingerprint domain.");
        $this->assertSame(
            $console[1],
            $app[1] ?? '',
            'The app and the console must compute credential fingerprints identically, '
            .'or every "is this device holding the credential I issued?" check reports a stale credential.',
        );
    }
}
