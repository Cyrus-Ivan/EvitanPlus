<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Provisioning\Support\SchemaStamp;
use Provisioning\Support\SchemaVersion;
use RuntimeException;
use Tests\TestCase;

/**
 * The version stamp (M9, docs/glossary.md §6).
 *
 * Until this existed, `schema_meta` was written once — by migration 000001, which
 * runs once per schema and never again — so the pair could be SET but never
 * RAISED. A twentieth migration would have left every provisioned business still
 * declaring schema version 1 and a min-app floor of 1.0.0, and a breaking change
 * could therefore never freeze the branches it needed to freeze. That failure is
 * silent in the worst way: a gate that never refuses is indistinguishable from
 * one with nothing to refuse, and the corruption it was guarding against shows up
 * as bad rows days later.
 *
 * These tests run against a plain migrated schema rather than a provisioned
 * business: the stamp is about `schema_meta` and nothing else, and provisioning a
 * business to test it would buy a branch role that none of this touches.
 */
class SchemaStampTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->migratedSchema();
    }

    protected function tearDown(): void
    {
        // Every test here rewrites schema_meta, and the class shares one schema.
        // Restoring is not tidiness — a test that left the row lowered would make
        // the next one pass against a fixture it did not set up.
        $this->restoreDeclaredPair();

        parent::tearDown();
    }

    public function test_a_fresh_schema_already_carries_the_declared_pair(): void
    {
        $this->assertSame(
            [
                'schema_version' => SchemaVersion::SCHEMA_VERSION,
                'min_app_version' => SchemaVersion::MIN_APP_VERSION,
            ],
            (new SchemaStamp)->read($this->db),
            'Migration 000001 must seed from SchemaVersion, or a fresh schema and a migrated one disagree.',
        );
    }

    public function test_migrating_restores_a_pair_that_has_fallen_behind(): void
    {
        $this->setPair(0, '0.9.0');
        $before = $this->appliedAt();

        $exit = Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $this->schemaName(),
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(
            [
                'schema_version' => SchemaVersion::SCHEMA_VERSION,
                'min_app_version' => SchemaVersion::MIN_APP_VERSION,
            ],
            (new SchemaStamp)->read($this->db),
        );

        // applied_at means "when this schema reached this version". A stamp that
        // changed the pair must move it.
        $this->assertNotSame($before, $this->appliedAt());
    }

    /**
     * The consequence is the point of printing anything at all: a raised floor
     * freezes tills, and this is the last moment anyone is thinking about it.
     */
    public function test_a_raised_floor_is_reported_with_what_it_costs(): void
    {
        $this->setPair(SchemaVersion::SCHEMA_VERSION, '0.9.0');

        Artisan::call('schema:migrate', ['--no-interaction' => true, '--schema' => $this->schemaName()]);

        $output = Artisan::output();

        $this->assertStringContainsString('0.9.0', $output);
        $this->assertStringContainsString(SchemaVersion::MIN_APP_VERSION, $output);
        $this->assertStringContainsString('minimum app version rose', $output);
        $this->assertStringContainsString('stop syncing until updated', $output);
    }

    public function test_an_unchanged_pair_leaves_applied_at_alone(): void
    {
        $before = $this->appliedAt();

        $result = (new SchemaStamp)->apply($this->db);

        $this->assertFalse($result['changed']);
        $this->assertSame(
            $before,
            $this->appliedAt(),
            'applied_at must mean "when this version was applied", not "when the command was last run".',
        );
    }

    /**
     * A schema ahead of this checkout was migrated by a newer console. Stamping
     * it down would un-freeze branches a breaking migration deliberately froze,
     * and the gate would then admit builds that cannot read the tables in front
     * of them — which is the corruption the gate exists to prevent, caused by the
     * tool meant to prevent it.
     */
    public function test_it_refuses_to_move_the_schema_version_backwards(): void
    {
        $this->setPair(SchemaVersion::SCHEMA_VERSION + 5, SchemaVersion::MIN_APP_VERSION);

        try {
            (new SchemaStamp)->apply($this->db);

            $this->fail('The stamp lowered a schema that was ahead of this checkout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AHEAD', $e->getMessage());
            $this->assertStringContainsString('Update this console checkout', $e->getMessage());
        }

        $this->assertSame(SchemaVersion::SCHEMA_VERSION + 5, (new SchemaStamp)->read($this->db)['schema_version']);
    }

    public function test_it_refuses_to_move_the_min_app_version_backwards(): void
    {
        $this->setPair(SchemaVersion::SCHEMA_VERSION, '99.0.0');

        $exit = Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $this->schemaName(),
        ]);

        $output = Artisan::output();

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('AHEAD', $output);

        // And the floor is still where the newer console left it. Half-applying
        // a refused stamp would be worse than either outcome.
        $this->assertSame('99.0.0', (new SchemaStamp)->read($this->db)['min_app_version']);
    }

    /** --pretend changes nothing, and the stamp is a change. */
    public function test_pretend_does_not_stamp(): void
    {
        $this->setPair(0, '0.9.0');

        Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $this->schemaName(),
            '--pretend' => true,
        ]);

        $this->assertSame(
            ['schema_version' => 0, 'min_app_version' => '0.9.0'],
            (new SchemaStamp)->read($this->db),
        );
    }

    private function setPair(int $schemaVersion, string $minAppVersion): void
    {
        $this->db->table('schema_meta')->where('id', 1)->update([
            'schema_version' => $schemaVersion,
            'min_app_version' => $minAppVersion,
        ]);
    }

    private function restoreDeclaredPair(): void
    {
        $this->db->table('schema_meta')->where('id', 1)->update([
            'schema_version' => SchemaVersion::SCHEMA_VERSION,
            'min_app_version' => SchemaVersion::MIN_APP_VERSION,
        ]);
    }

    private function appliedAt(): string
    {
        return (string) $this->db->selectOne('select applied_at from schema_meta')->applied_at;
    }
}
