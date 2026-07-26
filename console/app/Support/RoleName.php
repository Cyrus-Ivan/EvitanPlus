<?php

namespace Provisioning\Support;

use InvalidArgumentException;

/**
 * The one place a Postgres role identifier is derived or accepted.
 *
 * Sibling of SchemaName, and for the same reason: a role name reaches the
 * server inside raw DDL — `CREATE ROLE "x"`, `GRANT ... TO "x"`,
 * `ALTER ROLE "x" SET search_path`, `DROP ROLE "x"` — where an identifier
 * cannot be a bound parameter. Every branch role name funnels through here.
 *
 * ## Why the branch UUID and not the branch code
 *
 * Postgres roles are CLUSTER-wide, not per-database and certainly not
 * per-schema, while branch codes are only unique within one business (§12:
 * `branches_code_unique` is a per-schema index). A code-derived role name would
 * therefore collide the moment a second business also opens a branch called
 * MNL — and the collision would surface as one business's till authenticating
 * into another business's role. The branch UUID is globally unique by
 * construction, so it is the only safe source.
 */
final class RoleName
{
    /** Same 63-byte identifier limit that constrains schema names. */
    public const MAX_LENGTH = 63;

    /** Lowercase, digits and underscores only, always under the branch_ prefix. */
    private const PATTERN = '/^branch_[a-z0-9_]{1,55}$/';

    /**
     * The login role for one branch: branch_<32 hex>, derived from its UUID.
     *
     * Dashes are stripped exactly as SchemaName::forBusiness does, giving a
     * 39-character name that is comfortably inside MAX_LENGTH and maps back to
     * the branch it belongs to.
     */
    public static function forBranch(string $branchUuid): string
    {
        $normalized = strtolower(str_replace('-', '', trim($branchUuid)));

        if (! preg_match('/^[0-9a-f]{32}$/', $normalized)) {
            throw new InvalidArgumentException(
                "[{$branchUuid}] is not a UUID; a branch role is derived from the branch UUID."
            );
        }

        return 'branch_'.$normalized;
    }

    /**
     * Accept a role name only if it is safe to interpolate into DDL.
     *
     * Returns the name so callers can write `RoleName::validate($raw)` at the
     * point of use rather than validating and then forgetting to use the result.
     */
    public static function validate(string $role): string
    {
        if (strlen($role) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "Role name [{$role}] exceeds Postgres's ".self::MAX_LENGTH.'-byte identifier limit.'
            );
        }

        if (! preg_match(self::PATTERN, $role)) {
            throw new InvalidArgumentException(
                "Role name [{$role}] is not a valid branch role: expected branch_ followed by "
                .'lowercase letters, digits or underscores.'
            );
        }

        return $role;
    }
}
