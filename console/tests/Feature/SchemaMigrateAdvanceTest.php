<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Provisioning\Support\SchemaConnection;
use Tests\Concerns\AssertsBranchGrants;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;

/**
 * Migrate-on-update leaves branch roles able to use what the migration added
 * (M9's milestone, §8.4).
 *
 * ## The hazard this closes
 *
 * Grants are enumerated per table on purpose — no `GRANT ... ON ALL TABLES`, no
 * `ALTER DEFAULT PRIVILEGES` — because both would silently extend a leaked
 * branch credential's reach to any table a future migration adds (BranchGrants,
 * and `docs/glossary.md` §9 on why that matrix is now the only boundary of its
 * kind). The price of that choice is that a migration adding a table leaves
 * every existing branch role with **no privilege on it at all**.
 *
 * What that looks like from the shop floor is the reason this is worth a test:
 * `permission denied` is SQLSTATE 42501, an access-rule violation, which the
 * sync cycle classifies as `conflict` — so the till reads **"Sync stuck"**,
 * online and refusing to clear, and nothing on screen suggests a GRANT. From M1
 * to M8 the only remedy was for someone to remember to run a second command.
 *
 * ## Why the fixture rolls a schema back rather than adding a migration
 *
 * The set is complete at 19, so a test needs a pending one. Dropping
 * `branch_status` and deleting its ledger row manufactures exactly the state a
 * new migration produces — a table the schema is about to gain and the branch
 * role holds nothing on — without shipping a twentieth migration for a reason
 * M9 does not have. `branch_status` is the right victim: nothing references it,
 * and it carries SELECT, INSERT and UPDATE in the matrix, so all three have to
 * come back.
 */
class SchemaMigrateAdvanceTest extends TestCase
{
    use AssertsBranchGrants, ProvisionsBusiness;

    private const ROLLED_BACK_MIGRATION = '2026_07_26_000018_create_branch_status_table';

    public static function tearDownAfterClass(): void
    {
        self::tearDownProvisionedBusiness();

        parent::tearDownAfterClass();
    }

    /**
     * The milestone, stated as one assertion: a schema that advanced comes back
     * with the whole matrix live, not merely with the table.
     */
    public function test_a_schema_that_advanced_is_re_granted_in_the_same_run(): void
    {
        $schema = $this->schema();

        $this->rollBackBranchStatus($schema);

        $exit = Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $schema,
            '--actor' => 'migrate-advance-test',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('advanced', $output);
        $this->assertTrue(SchemaConnection::hasTable($schema, 'branch_status'), 'The migration did not re-run.');

        $this->assertGrantsMatchMatrix($this->roleName(), $schema);
    }

    /**
     * The hazard is real, and this is what proves the test above is testing
     * something. A bare `migrate` — the same DDL, without M9's step — brings the
     * table back with the role holding nothing on it.
     */
    public function test_a_bare_migrate_leaves_the_role_with_no_privilege_on_the_new_table(): void
    {
        $schema = $this->schema();
        $role = $this->roleName();

        $this->rollBackBranchStatus($schema);

        SchemaConnection::pin($schema);

        Artisan::call('migrate', [
            '--database' => SchemaConnection::NAME,
            '--path' => 'database/migrations',
            '--force' => true,
        ]);

        $this->assertTrue(SchemaConnection::hasTable($schema, 'branch_status'));

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            $this->assertFalse(
                $this->hasPrivilege($role, $schema, 'branch_status', $privilege),
                "A bare migrate granted {$privilege} on a new table, which would mean the matrix is not "
                .'enumerated per table after all.',
            );
        }

        // Leave the fixture usable for whatever runs next, and demonstrate the
        // documented recovery while we are here.
        $this->assertSame(0, Artisan::call('schema:reapply-grants', ['--schema' => $schema]));
        $this->assertGrantsMatchMatrix($role, $schema);
    }

    /**
     * A re-run is a no-op, and says so. Re-granting unconditionally would be
     * harmless — GRANT is idempotent — but it would also make every routine
     * `schema:migrate` print a per-role report that has nothing to report, which
     * is how an operator learns to stop reading the output.
     */
    public function test_a_schema_that_did_not_advance_is_not_re_granted(): void
    {
        $exit = Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $this->schema(),
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('already current', $output);
        $this->assertStringNotContainsString('Re-applying branch grants', $output);
    }

    /** §8.6 names migrate among the audited actions; it wrote nothing until M9. */
    public function test_it_records_the_migration_in_the_provisioning_audit(): void
    {
        $schema = $this->schema();

        Artisan::call('schema:migrate', [
            '--no-interaction' => true,
            '--schema' => $schema,
            '--actor' => 'migrate-advance-test',
        ]);

        $row = SchemaConnection::pinConsole()
            ->table('provisioning_audit')
            ->where('schema_name', $schema)
            ->where('action', 'schema.migrate')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($row, 'schema:migrate wrote no audit row.');
        $this->assertSame('migrate-advance-test', $row->actor);

        $detail = json_decode((string) $row->detail, true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('advanced', $detail);
        $this->assertArrayHasKey('schema_version', $detail);
    }

    /**
     * Drop the table and forget the migration ever ran, which is what a schema
     * looks like one moment before a new migration adds a table to it.
     */
    private function rollBackBranchStatus(string $schema): void
    {
        $connection = SchemaConnection::pin($schema);

        $connection->statement('drop table if exists branch_status');
        $connection->table('migrations')->where('migration', self::ROLLED_BACK_MIGRATION)->delete();

        $this->assertFalse(
            SchemaConnection::hasTable($schema, 'branch_status'),
            'Fixture failed: branch_status is still there.',
        );
    }

    private function hasPrivilege(string $role, string $schema, string $table, string $privilege): bool
    {
        return (bool) DB::connection('pgsql_admin')->selectOne(
            'select has_table_privilege(?, ?, ?) as v',
            [$role, $schema.'.'.$table, $privilege],
        )->v;
    }
}
