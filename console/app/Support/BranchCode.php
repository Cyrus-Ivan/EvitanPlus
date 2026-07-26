<?php

namespace Provisioning\Support;

use InvalidArgumentException;

/**
 * Canonicalises and validates a branch code (§12).
 *
 * The code is load-bearing, not cosmetic: it prefixes every receipt serial the
 * branch prints (MNL-0000000001), and that prefix is the ONLY thing making
 * receipt numbers unique business-wide, since each branch generates its own
 * gapless serial offline. So a printed receipt number must have exactly one
 * spelling — which is why `branches` carries
 * `check (code = upper(code))` and why input is uppercased here rather than
 * being rejected for case.
 *
 * Unlike SchemaName and RoleName this is a VALUE, not an identifier: it is
 * always bound as a parameter. It is validated for the receipt's sake, not for
 * injection safety.
 */
final class BranchCode
{
    /** Matches the varchar(16) the branches table declares. */
    public const MAX_LENGTH = 16;

    /**
     * Uppercase letters, digits and dashes; must start alphanumeric.
     *
     * Deliberately narrow: the code is printed on receipts, read aloud, and
     * typed back in during support calls, so spaces, punctuation and lookalike
     * separators are more trouble than they are worth.
     */
    private const PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,15}$/';

    /** Trim and uppercase, the form that satisfies branches_code_uppercase. */
    public static function canonicalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Canonicalise, then accept only a well-formed code. Returns the canonical
     * form so callers store what was validated.
     */
    public static function validate(string $code): string
    {
        $code = self::canonicalize($code);

        if ($code === '') {
            throw new InvalidArgumentException('A branch code is required: it prefixes every receipt number.');
        }

        if (strlen($code) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "Branch code [{$code}] exceeds ".self::MAX_LENGTH.' characters.'
            );
        }

        if (! preg_match(self::PATTERN, $code)) {
            throw new InvalidArgumentException(
                "Branch code [{$code}] is not valid: use letters, digits and dashes, starting with a letter or digit."
            );
        }

        return $code;
    }
}
