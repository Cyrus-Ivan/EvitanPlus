<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Tests\Concerns\AssertsBranchGrants;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;

/**
 * `schema:reapply-grants` (M6.5) — the command that closes a hole M2 opened and
 * M3, M4, M6 and the console menu all had to write around.
 *
 * `BranchRole::reapplyGrants()` has existed since M2 and nothing called it, so
 * the only way to give an existing branch a privilege it lacked was
 * `branch:rotate` — which changes the password and therefore obliges a fresh
 * AppImage and a new credentials JSON to every till. M6.5 widened the matrix
 * for every already-provisioned business, which made "no way to deliver a
 * GRANT" an immediate problem rather than a documented one.
 *
 * The test that matters is the middle one: a role really is missing a privilege
 * beforehand and really has it afterwards. Asserting only that the command exits
 * zero would pass on a command that did nothing at all.
 */
class SchemaReapplyGrantsTest extends TestCase
{
    use AssertsBranchGrants, ProvisionsBusiness;

    /**
     * ProvisionsBusiness is lazy but NOT self-cleaning: its docblock requires
     * every using class to call this from its own tearDownAfterClass. This class
     * did not, and the cost was invisible because nothing fails — the suite went
     * green while leaving one real `biz_*` schema and one branch role behind in
     * Supabase on every single run. Eight had accumulated before anyone looked.
     */
    public static function tearDownAfterClass(): void
    {
        self::tearDownProvisionedBusiness();

        parent::tearDownAfterClass();
    }

    public function test_it_restores_a_privilege_that_was_revoked_by_hand(): void
    {
        $schema = $this->schema();
        $role = $this->roleName();

        // Take a grant away behind the command's back — the same state a
        // migration that adds a table leaves every existing role in.
        DB::connection('pgsql_admin')->statement(
            'revoke insert on '.$this->qualified($schema, 'categories').' from '.$this->quote($role)
        );

        $this->assertFalse($this->canInsertCategories($role, $schema), 'Fixture failed: the grant was not removed.');

        $this->assertSame(0, Artisan::call('schema:reapply-grants', [
            '--schema' => $schema,
            '--actor' => 'reapply-grants-test',
        ]));

        $this->assertTrue($this->canInsertCategories($role, $schema));

        // And not merely that one privilege: the whole matrix is current again.
        $this->assertGrantsMatchMatrix($role, $schema);
    }

    public function test_it_is_idempotent(): void
    {
        $schema = $this->schema();

        foreach (range(1, 2) as $ignored) {
            $this->assertSame(0, Artisan::call('schema:reapply-grants', ['--schema' => $schema]));
        }

        $this->assertGrantsMatchMatrix($this->roleName(), $schema);
    }

    public function test_it_records_one_audit_row_per_role(): void
    {
        $schema = $this->schema();

        $before = $this->auditCount($schema);

        Artisan::call('schema:reapply-grants', ['--schema' => $schema, '--actor' => 'reapply-grants-test']);

        // One branch in the fixture, so exactly one row — the per-role shape,
        // which is what makes a partial failure legible.
        $this->assertSame($before + 1, $this->auditCount($schema));

        $row = SchemaConnection::pin(SchemaName::CONSOLE)
            ->table('provisioning_audit')
            ->where('schema_name', $schema)
            ->where('action', 'branch.reapply_grants')
            ->orderByDesc('created_at')
            ->first();

        $this->assertSame(ProvisioningAudit::SUCCEEDED, $row->outcome);
        $this->assertSame($this->roleName(), $row->role_name);
    }

    public function test_it_refuses_all_combined_with_a_target(): void
    {
        $this->assertNotSame(0, Artisan::call('schema:reapply-grants', [
            '--all' => true,
            '--schema' => $this->schema(),
        ]));
    }

    public function test_it_refuses_a_schema_that_does_not_exist(): void
    {
        $this->assertNotSame(0, Artisan::call('schema:reapply-grants', [
            '--schema' => 'biz_0000000000000000000000000000dead',
        ]));
    }

    private function canInsertCategories(string $role, string $schema): bool
    {
        return (bool) DB::connection('pgsql_admin')->selectOne(
            'select has_table_privilege(?, ?, \'INSERT\') as v',
            [$role, $schema.'.categories'],
        )->v;
    }

    private function auditCount(string $schema): int
    {
        return SchemaConnection::pin(SchemaName::CONSOLE)
            ->table('provisioning_audit')
            ->where('schema_name', $schema)
            ->where('action', 'branch.reapply_grants')
            ->count();
    }

    private function qualified(string $schema, string $table): string
    {
        return $this->quote($schema).'.'.$this->quote($table);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
