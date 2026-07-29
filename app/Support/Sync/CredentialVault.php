<?php

namespace App\Support\Sync;

use App\Exceptions\SetupFailedException;
use App\Models\InstanceSecret;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The branch credential at rest (MULTIBRANCH-BRAINSTORM.md §9).
 *
 * The connection block is sealed with the device's per-install APP_KEY —
 * generated on first launch by EncryptionKeyBootstrapper and stripped from every
 * build by `nativephp.cleanup_env_keys`, so two devices never share a key and no
 * key ever ships. It is decrypted only inside a sync cycle, and the plaintext
 * lives no longer than that cycle's config array.
 *
 * This is a real but bounded protection: a stolen device gives up its APP_KEY
 * along with its SQLite file, so the encryption defends against a copied
 * database, not against the machine itself. What defends against the machine is
 * the console — the credential is one role, least-privileged to one schema, and
 * revocable in a single command.
 *
 * ## The fingerprint must match the console's byte for byte
 *
 * `credential_issuance.fingerprint` is how the console answers "is this device
 * still holding the credential I last issued?" without either side transmitting
 * the password. That only works while both compute it identically, which is why
 * the domain separator below is duplicated verbatim from
 * `Provisioning\Support\BranchSecret` and why a unit test reads that file and
 * asserts the two agree. §18 forbids either app referencing the other's code, so
 * a shared constant is not available even in principle.
 */
class CredentialVault
{
    /**
     * Domain separator, versioned. MUST equal BranchSecret::FINGERPRINT_DOMAIN
     * in the provisioning console — see the class docblock.
     */
    private const FINGERPRINT_DOMAIN = 'evitan.branch-credential.v1|';

    /**
     * Seal a connection block for storage.
     *
     * @param  array<string, mixed>  $connection
     */
    public function seal(array $connection): string
    {
        return Crypt::encrypt($connection);
    }

    /**
     * The stored connection block, or null on a device that has never been set up.
     *
     * @return array<string, mixed>|null
     *
     * @throws SetupFailedException When the ciphertext cannot be opened.
     */
    public function open(): ?array
    {
        $secret = InstanceSecret::current();

        if ($secret === null) {
            return null;
        }

        try {
            /** @var array<string, mixed> $connection */
            $connection = Crypt::decrypt($secret->dsn_ciphertext);
        } catch (Throwable $e) {
            // The realistic cause is a database file moved between installs:
            // the rows came along, the per-install APP_KEY did not. Say that,
            // because the remedy (re-import the credentials JSON) is not
            // guessable from a decryption error.
            throw new SetupFailedException(
                'The stored branch credential cannot be decrypted with this install\'s key. '
                .'Run setup again with the credentials file for this branch.',
                previous: $e,
            );
        }

        return $connection;
    }

    /**
     * A digest the console can compare against `credential_issuance`.
     *
     * Not a password hash and not doing a password hash's job: nothing here ever
     * verifies a login. It is an equality token over ~190 bits of random secret,
     * and it must be reproducible on both sides, which a salted hash would not be.
     */
    public function fingerprint(string $secret): string
    {
        return 'sha256:'.hash('sha256', self::FINGERPRINT_DOMAIN.$secret);
    }
}
