<?php

namespace Provisioning\Support;

use InvalidArgumentException;

/**
 * The one place a Postgres schema identifier is derived or accepted.
 *
 * Schema names reach the server inside raw DDL — `CREATE SCHEMA "x"`,
 * `DROP SCHEMA "x" CASCADE`, `set search_path to "x"` — where placeholders do
 * not exist: an identifier cannot be a bound parameter. That makes this the
 * injection boundary for the whole console, so every command and test funnels
 * through validate() and nothing else builds an identifier by hand.
 *
 * The allowed shape is deliberately narrower than Postgres permits (no
 * uppercase, no dots, no quotes, no spaces), which means a validated name is
 * also immune to the folding and quoting rules that make Postgres identifiers
 * subtle.
 */
final class SchemaName
{
    /**
     * Postgres truncates identifiers at 63 bytes; a longer name would silently
     * become a different schema than the caller asked for.
     */
    public const MAX_LENGTH = 63;

    /** Lowercase, digits and underscores only, always under the biz_ prefix. */
    private const PATTERN = '/^biz_[a-z0-9_]{1,58}$/';

    /**
     * The schema holding one business: biz_<32 hex>, derived from its UUID.
     *
     * Dashes are stripped rather than replaced so the result is 36 characters,
     * comfortably inside MAX_LENGTH, and the mapping stays reversible.
     */
    public static function forBusiness(string $businessUuid): string
    {
        $normalized = strtolower(str_replace('-', '', trim($businessUuid)));

        if (! preg_match('/^[0-9a-f]{32}$/', $normalized)) {
            throw new InvalidArgumentException(
                "[{$businessUuid}] is not a UUID; a business schema is derived from the business UUID."
            );
        }

        return 'biz_'.$normalized;
    }

    /**
     * Accept a schema name only if it is safe to interpolate into DDL.
     *
     * Returns the name so callers can write `SchemaName::validate($raw)` at the
     * point of use rather than validating and then forgetting to use the result.
     */
    public static function validate(string $schema): string
    {
        if (strlen($schema) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "Schema name [{$schema}] exceeds Postgres's ".self::MAX_LENGTH.'-byte identifier limit.'
            );
        }

        if (! preg_match(self::PATTERN, $schema)) {
            throw new InvalidArgumentException(
                "Schema name [{$schema}] is not a valid business schema: expected biz_ followed by "
                .'lowercase letters, digits or underscores.'
            );
        }

        return $schema;
    }

    /**
     * Resolve the --business / --schema option pair used by the console
     * commands. Exactly one must be supplied: accepting both invites a
     * mismatch where the caller believes they targeted a different schema.
     */
    public static function resolve(?string $business, ?string $schema): string
    {
        $business = $business !== null && trim($business) !== '' ? trim($business) : null;
        $schema = $schema !== null && trim($schema) !== '' ? trim($schema) : null;

        if ($business !== null && $schema !== null) {
            throw new InvalidArgumentException('Pass either --business or --schema, not both.');
        }

        if ($business !== null) {
            return self::forBusiness($business);
        }

        if ($schema !== null) {
            return self::validate($schema);
        }

        throw new InvalidArgumentException('Target a schema with --business=<uuid> or --schema=<name>.');
    }
}
