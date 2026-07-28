<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaDiff;

/**
 * Tests the schema parity guard itself, with schemas that are made up.
 *
 * SchemaParityTest proves the two REAL schemas agree. It cannot prove that the
 * comparison would have noticed if they did not — a green run there is equally
 * consistent with "the schemas match" and "the comparison stopped checking".
 * Only feeding the comparison a mismatch and requiring a complaint separates
 * those two, and this is where that happens.
 *
 * Deliberately credential-free (plain PHPUnit\Framework\TestCase, no container
 * — the IdentifierSafetyTest pattern), so it runs on a fresh clone and on every
 * push. The guard for the schemas needs a database; the guard for the guard does
 * not, and making it need one would put it back behind the same gate that left
 * the parity test unrun for three phases.
 *
 * Four tests, one per way the comparison could go blind — not one per
 * permutation. The first packs every column-level fault into a single table
 * because compareTable reports them all in one pass, so testing them separately
 * would restate the same call three more times.
 */
class SchemaDiffTest extends TestCase
{
    /**
     * A cut-down stand-in for SchemaParityTest::TYPE_MAP. Small on purpose: this
     * file tests the comparison, not the real map, and a copy of the real one
     * would drift into a second source of truth.
     */
    private const TYPE_MAP = [
        'varchar' => ['character varying', 'uuid'],
        'datetime' => ['timestamp with time zone'],
        'integer' => ['integer', 'bigint'],
    ];

    private function diff(): SchemaDiff
    {
        return new SchemaDiff(self::TYPE_MAP, ['branch_id']);
    }

    /**
     * Every column-level fault at once, in the order compareTable reports them:
     *
     * - `sku` exists only in the app — the motivating failure. Nothing breaks
     *   locally; the field is silently dropped on the way up and the loss
     *   surfaces in a report months later.
     * - `cost` disagrees on type. Both sides have the column, so a name-only
     *   comparison passes while every value crossing the wire is mangled.
     * - `payload` has a SQLite type the map does not cover. This must FAIL, not
     *   fall through — `?? null` followed by a skip reads as caution and behaves
     *   as a hole, and every future column of that type enters unchecked.
     * - `issued_by` exists only in Postgres, so the app cannot populate it.
     */
    public function test_every_kind_of_column_fault_is_reported(): void
    {
        $problems = $this->diff()->compareTable(
            'variants',
            ['id' => 'varchar', 'sku' => 'varchar', 'cost' => 'datetime', 'payload' => 'blob'],
            ['id' => 'uuid', 'cost' => 'integer', 'payload' => 'bytea', 'issued_by' => 'uuid'],
        );

        $this->assertSame([
            '[variants.sku] exists in the app but not in Postgres — a sync would drop it.',
            '[variants.cost] is datetime in SQLite but integer in Postgres.',
            'No type mapping for SQLite type [blob] (variants.payload).',
            '[variants] has Postgres-only columns the app cannot populate: issued_by',
        ], $problems);
    }

    /**
     * The documented exception, and the one this must NOT flag: one Postgres
     * schema holds every branch's rows while a till's SQLite holds only its own,
     * so branch_id has nothing to correspond to locally.
     *
     * This is also the only test that requires agreement to be reported as
     * agreement — a comparison that complained about everything would pass all
     * three of the others.
     */
    public function test_branch_id_alone_on_the_postgres_side_is_allowed(): void
    {
        $problems = $this->diff()->compareTable(
            'transactions',
            ['id' => 'varchar', 'created_at' => 'datetime'],
            ['id' => 'uuid', 'created_at' => 'timestamp with time zone', 'branch_id' => 'uuid'],
        );

        $this->assertSame([], $problems);
    }

    public function test_a_table_on_no_allowlist_is_reported(): void
    {
        $undocumented = $this->diff()->undocumentedTables(
            ['transactions', 'sync_outbox', 'cache'],
            ['transactions'],
            ['cache'],
        );

        $this->assertSame(['sync_outbox'], $undocumented);
    }

    /**
     * A dropped migration removes a table without touching any list, so the
     * allowlist has to be read as a promise as well as a permission.
     */
    public function test_an_allowlisted_table_that_vanished_is_reported(): void
    {
        $missing = $this->diff()->missingTables(
            ['transactions'],
            ['transactions', 'schema_meta'],
        );

        $this->assertSame(['schema_meta'], $missing);
    }
}
