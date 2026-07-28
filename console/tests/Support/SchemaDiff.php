<?php

namespace Tests\Support;

/**
 * The column-by-column comparison behind the schema parity guard (§13).
 *
 * Extracted from SchemaParityTest so that the comparison itself can be tested.
 * That test builds both real schemas and asserts they agree — which proves the
 * schemas agree TODAY, and proves nothing about the comparison. A refactor that
 * quietly gutted it (an early return, a loop over the wrong array, an allowlist
 * that swallows everything) would leave the suite green forever while the guard
 * checked nothing. Drift would then resume undetected, which is the exact
 * failure M4 exists to end.
 *
 * So this class holds the logic and nothing else: no database, no container, no
 * Laravel. Tests\Unit\SchemaDiffTest feeds it INVENTED schemas that disagree in
 * each way that matters and requires it to complain about each — the same
 * reasoning SchemaInvariantsTest states for constraints. Asserting that a rule
 * exists passes just as happily against a rule that enforces nothing; attempting
 * the violation does not.
 *
 * The allowlists arrive as constructor arguments rather than living here. They
 * are documentation as much as configuration — `docs/glossary.md` §3 and
 * `MULTIBRANCH-BRAINSTORM.md` §11 both anchor on `SchemaParityTest::MIRRORED`
 * and its siblings — so they stay on the test that reads the real schemas, next
 * to the prose explaining each membership.
 *
 * Every method returns a list of human-readable problems and is empty on
 * agreement, so a caller asserts `assertSame([], ...)` and gets the whole story
 * in one failure instead of stopping at the first bad column.
 */
final class SchemaDiff
{
    /**
     * @param  array<string, list<string>>  $typeMap  SQLite declared type => Postgres types that faithfully carry it
     * @param  list<string>  $postgresOnlyColumns  columns Postgres may add to a mirrored table
     */
    public function __construct(
        private readonly array $typeMap,
        private readonly array $postgresOnlyColumns,
    ) {}

    /**
     * Compare one mirrored table's columns across the two schemas.
     *
     * @param  array<string, string>  $sqlite  column => declared SQLite type
     * @param  array<string, string>  $postgres  column => information_schema data_type
     * @return list<string>
     */
    public function compareTable(string $table, array $sqlite, array $postgres): array
    {
        $problems = [];

        foreach ($sqlite as $column => $sqliteType) {
            if (! \array_key_exists($column, $postgres)) {
                $problems[] = "[{$table}.{$column}] exists in the app but not in Postgres — a sync would drop it.";

                continue;
            }

            // An unmapped type is a failure, never a pass. Falling through
            // silently is how a `blob` or a `json` column would sail past a
            // guard whose whole job is to notice it.
            if (! \array_key_exists($sqliteType, $this->typeMap)) {
                $problems[] = "No type mapping for SQLite type [{$sqliteType}] ({$table}.{$column}).";

                continue;
            }

            if (! \in_array($postgres[$column], $this->typeMap[$sqliteType], true)) {
                $problems[] = "[{$table}.{$column}] is {$sqliteType} in SQLite but {$postgres[$column]} in Postgres.";
            }
        }

        // ...and nothing extra on the Postgres side that the app does not know
        // how to fill, beyond the documented additions.
        $extra = array_values(array_diff(
            array_keys($postgres),
            array_keys($sqlite),
            $this->postgresOnlyColumns,
        ));

        if ($extra !== []) {
            $problems[] = "[{$table}] has Postgres-only columns the app cannot populate: ".implode(', ', $extra);
        }

        return $problems;
    }

    /**
     * Tables present in a schema that appear on none of its allowlists.
     *
     * The guard runs in both directions and on both sides: a table nobody
     * classified is a table nobody decided about, and the decision — mirror it,
     * or write down why not — is the thing worth forcing.
     *
     * @param  list<string>  $actual  every table the schema really contains
     * @param  list<string>  ...$allowed  one or more allowlists, unioned
     * @return list<string>
     */
    public function undocumentedTables(array $actual, array ...$allowed): array
    {
        return array_values(array_diff($actual, ...$allowed));
    }

    /**
     * Tables an allowlist promises but the schema does not contain.
     *
     * The reverse of undocumentedTables(), and not redundant with it: a dropped
     * migration removes a table without touching any list, so only a check that
     * reads the list as a promise will notice.
     *
     * @param  list<string>  $actual
     * @param  list<string>  $expected
     * @return list<string>
     */
    public function missingTables(array $actual, array $expected): array
    {
        return array_values(array_diff($expected, $actual));
    }
}
