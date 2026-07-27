<?php

namespace Provisioning\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates branch role passwords, and is the one place such a secret is
 * quoted into SQL.
 *
 * `CREATE ROLE ... PASSWORD '...'` takes a literal, not a placeholder, so this
 * is the console's second injection boundary after SchemaName/RoleName. Unlike
 * an identifier, though, a password is a value we get to CHOOSE — so instead of
 * escaping a hostile alphabet, the alphabet is restricted to one that is
 * unambiguous everywhere the secret travels:
 *
 *   - a single-quoted Postgres literal (no ' or \)
 *   - a libpq URI, which M5 may build (no : @ / ? # %)
 *   - a JSON string in the credentials file (no " or \)
 *   - a .env-style line in the app's encrypted store (no # = or whitespace)
 *   - a shell argument during manual psql verification (no $ ` ! * or space)
 *
 * Str::random is random_bytes-backed, so [A-Za-z0-9] at length 32 is ~190 bits
 * of entropy — far past anything brute-forceable, and the restricted alphabet
 * costs nothing next to that.
 */
final class BranchSecret
{
    public const LENGTH = 32;

    /** Anything shorter than this is a bug, not a policy choice. */
    private const MIN_LENGTH = 16;

    private const ALPHABET = '/^[A-Za-z0-9]+$/';

    /**
     * Domain separator for fingerprint(). Versioned, and shared verbatim with
     * the app side in M5 — the two must agree byte for byte or every comparison
     * reports a stale credential.
     */
    private const FINGERPRINT_DOMAIN = 'evitan.branch-credential.v1|';

    public static function generate(int $length = self::LENGTH): string
    {
        if ($length < self::MIN_LENGTH) {
            throw new InvalidArgumentException(
                'A branch secret must be at least '.self::MIN_LENGTH.' characters.'
            );
        }

        return Str::random($length);
    }

    /**
     * Quote a secret for use as a Postgres string literal.
     *
     * Asserts the alphabet FIRST and doubles quotes second. The assertion is
     * the real guard: if a secret ever arrives from somewhere other than
     * generate(), this crashes rather than quietly escaping it — because a
     * password that needs escaping also breaks the URI, JSON and .env paths
     * above, so silently accepting it would move the failure somewhere far
     * harder to diagnose.
     */
    public static function quote(string $secret): string
    {
        if (strlen($secret) < self::MIN_LENGTH || ! preg_match(self::ALPHABET, $secret)) {
            throw new InvalidArgumentException(
                'Refusing to quote a branch secret that is not at least '.self::MIN_LENGTH
                .' alphanumeric characters; generate it with BranchSecret::generate().'
            );
        }

        return "'".str_replace("'", "''", $secret)."'";
    }

    /**
     * A comparable, non-reversible marker for an issued secret (M3).
     *
     * Recorded in credential_issuance so the console can answer "is the
     * credential this branch is running the current one?" — M5 stores the same
     * value on the device and M10's heartbeat reports it back, so both sides
     * compare markers and neither ever transmits the password.
     *
     * That use dictates the algorithm. It must be DETERMINISTIC: bcrypt, or any
     * per-row salt, would make two independently computed fingerprints of the
     * same secret unequal, which is the only operation this value has. Nothing
     * here ever verifies a login, so the usual "never store a fast hash of a
     * password" rule is answering a different question — and the input is 32
     * characters of BranchSecret's alphabet, ~190 bits, so the digest is no
     * shortcut to the secret by any margin that will ever exist.
     *
     * The domain separator keeps this digest from colliding with a sha256 of
     * the same string computed for some other purpose, and the `sha256:` prefix
     * leaves room to move algorithms without guessing at stored rows.
     */
    public static function fingerprint(string $secret): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Refusing to fingerprint an empty secret.');
        }

        return 'sha256:'.hash('sha256', self::FINGERPRINT_DOMAIN.$secret);
    }
}
