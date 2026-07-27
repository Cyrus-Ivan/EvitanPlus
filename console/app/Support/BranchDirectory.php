<?php

namespace Provisioning\Support;

use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Reads the `branches` table of an already-provisioned business (M3).
 *
 * Every M3 command starts the same way: the operator names a business and a
 * branch, and the command needs the branch's row before it can do anything.
 * Centralising that here keeps one rule in one place — **the Postgres role is
 * always derived from the branch UUID, never from the branch code**.
 *
 * That rule is not a style preference. Postgres roles are cluster-wide while
 * branch codes are unique only within one business (§7 As built), so two
 * businesses each opening an "MNL" branch would want the same role name, and
 * the collision would be one business's till authenticating into another's
 * role. The code keeps its real job: the receipt serial prefix (§12).
 */
final class BranchDirectory
{
    /**
     * The branch a --branch / --branch-code pair names.
     *
     * Exactly one of the two, mirroring SchemaName::resolve's contract for
     * --business / --schema: accepting both invites a mismatch where the
     * operator believes they targeted a different branch than they did.
     */
    public static function resolve(string $schema, ?string $branchId, ?string $code): stdClass
    {
        $branchId = self::trimmed($branchId);
        $code = self::trimmed($code);

        if ($branchId !== null && $code !== null) {
            throw new InvalidArgumentException('Pass either --branch or --branch-code, not both.');
        }

        if ($branchId === null && $code === null) {
            throw new InvalidArgumentException('Name a branch with --branch=<uuid> or --branch-code=<code>.');
        }

        if ($branchId !== null) {
            // Validated by the same rule that mints role names, so an operator
            // typo cannot reach the query as something exotic.
            RoleName::forBranch($branchId);

            $row = self::query($schema, 'id = ?', [$branchId]);

            if ($row === null) {
                throw new RuntimeException("No branch [{$branchId}] in schema [{$schema}].");
            }

            return $row;
        }

        $canonical = BranchCode::validate((string) $code);

        $row = self::query($schema, 'code = ?', [$canonical]);

        if ($row === null) {
            throw new RuntimeException("No branch with code [{$canonical}] in schema [{$schema}].");
        }

        return $row;
    }

    /**
     * Every branch of a business, oldest first — the order they were opened in,
     * which is the order an operator thinks about them in.
     *
     * @return list<stdClass>
     */
    public static function all(string $schema): array
    {
        return array_values(SchemaConnection::pin($schema)
            ->select('select id, code, name, address, disabled_at, created_at from branches order by created_at'));
    }

    /**
     * Is this code already in use in this business?
     *
     * A courtesy check only. `branches_code_unique` remains the authority: a
     * code claimed between this call and the insert must still fail, and it
     * does — as a clean rollback rather than a duplicate row.
     */
    public static function codeTaken(string $schema, string $code): bool
    {
        return self::query($schema, 'code = ?', [BranchCode::validate($code)]) !== null;
    }

    /**
     * Has this schema actually been provisioned as a business?
     *
     * An empty-but-existing schema, or one whose migration died partway, is not
     * something a branch can be added to — the new branch's till would hydrate
     * from a catalog and a settings table that are not there.
     */
    public static function isProvisionedBusiness(string $schema): bool
    {
        try {
            $connection = SchemaConnection::pin($schema);

            return $connection->selectOne('select 1 as found from schema_meta') !== null
                && $connection->selectOne('select 1 as found from branches') !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The business's registered name, for confirmation prompts and the
     * credentials JSON. Read from `settings` because there is no businesses
     * registry — the console enumerates schemas and trusts each one's own rows.
     */
    public static function businessName(string $schema): string
    {
        $row = SchemaConnection::pin($schema)->selectOne(
            'select value from settings where key = ?',
            [BusinessSettingKeys::BUSINESS_NAME],
        );

        return $row === null ? '' : (string) $row->value;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private static function query(string $schema, string $where, array $bindings): ?stdClass
    {
        return SchemaConnection::pin($schema)->selectOne(
            "select id, code, name, address, disabled_at, created_at from branches where {$where}",
            $bindings,
        );
    }

    private static function trimmed(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
