<?php

namespace Provisioning\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Every business this Supabase project holds — the read no table backs.
 *
 * There is deliberately no businesses registry (see SchemaName::toBusinessUuid),
 * so "which businesses exist" is answered by enumerating `biz_*` schemas and
 * trusting each one's own rows for its name and branch count. That is the same
 * trust model BranchDirectory already works under, one level up.
 *
 * Nothing needed this before: every command took the business as an option, and
 * an operator who could not remember a business UUID had the runbook's SQL. The
 * menu (console:run) is what makes the list itself the starting point, and M9's
 * migrate-every-schema and M10's monitor both need the same enumeration.
 *
 * ## Why a half-built schema is listed rather than hidden
 *
 * A `biz_*` schema that is not a provisioned business is a real thing an
 * operator meets: a `schema:migrate` that died partway, or one of the test
 * suite's own `biz_test_*` fixtures that a failed run left behind (console
 * README §7 exists to sweep exactly those). Hiding them would make the one
 * command that could fix or find them unreachable from the menu, so they are
 * listed and flagged instead.
 */
final class BusinessDirectory
{
    /**
     * Businesses in schema-name order, which is stable but arbitrary — a
     * business UUID carries no meaning, so there is no "natural" order to
     * prefer, and a stable one at least keeps the menu from reshuffling
     * under the operator between actions.
     *
     * @return list<array{schema: string, business_id: string|null, name: string, branches: int, provisioned: bool}>
     */
    public static function all(): array
    {
        return array_map(self::describe(...), self::schemas());
    }

    /**
     * The `biz_*` schema names present in the cluster.
     *
     * A regex rather than LIKE: `_` is a LIKE wildcard, and an escaped one
     * ('biz\_%') is a detail worth not re-deriving every time this is read.
     * information_schema.schemata is the same source SchemaConnection::exists
     * uses, so the two agree about what "exists" means.
     *
     * @return list<string>
     */
    private static function schemas(): array
    {
        $rows = DB::connection('pgsql_admin')->select(
            "select schema_name from information_schema.schemata where schema_name ~ '^biz_' order by schema_name"
        );

        return array_map(fn (object $row): string => (string) $row->schema_name, $rows);
    }

    /**
     * @return array{schema: string, business_id: string|null, name: string, branches: int, provisioned: bool}
     */
    private static function describe(string $schema): array
    {
        $provisioned = BranchDirectory::isProvisionedBusiness($schema);

        return [
            'schema' => $schema,
            'business_id' => self::businessId($schema),
            'name' => $provisioned ? BranchDirectory::businessName($schema) : '',
            'branches' => $provisioned ? count(BranchDirectory::all($schema)) : 0,
            'provisioned' => $provisioned,
        ];
    }

    /**
     * The business UUID the schema name encodes, or null when it encodes none.
     *
     * The suite's `biz_test_<random>` fixtures satisfy SchemaName's pattern
     * without being UUID-derived, so this throws for schemas that legitimately
     * turn up in a real project. Null is the honest answer: such a schema can
     * still be targeted with --schema, just never with --business.
     */
    private static function businessId(string $schema): ?string
    {
        try {
            return SchemaName::toBusinessUuid($schema);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A business schema's version facts, or null when it has none to report.
     *
     * The same two numbers schema:version prints (§13), read here so the menu
     * can show them as context on the business screen instead of making the
     * operator run a command to find out. Failure is null rather than an
     * exception: an unreadable schema_meta is information the screen displays,
     * not a reason to refuse to draw it.
     *
     * @return array{schema_version: string, min_app_version: string, applied_at: string}|null
     */
    public static function version(string $schema): ?array
    {
        try {
            $meta = SchemaConnection::pin($schema)
                ->selectOne('select schema_version, min_app_version, applied_at from schema_meta');
        } catch (Throwable) {
            return null;
        }

        if ($meta === null) {
            return null;
        }

        return [
            'schema_version' => (string) ($meta->schema_version ?? '—'),
            'min_app_version' => (string) ($meta->min_app_version ?? '—'),
            'applied_at' => (string) ($meta->applied_at ?? '—'),
        ];
    }
}
