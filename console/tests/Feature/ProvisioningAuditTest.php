<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Provisioning\Support\BranchRole;
use Provisioning\Support\BusinessProvisioner;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\RoleName;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The console's self-audit store (§8.6) and the compensating-cleanup path that
 * writes to it.
 *
 * The rollback case is the reason this class provisions at all: a failure AFTER
 * the schema exists is the only situation in which "transactional" has to be
 * delivered by compensation rather than by the database, so it is the one worth
 * proving. It is induced by making a progress step throw, which is deterministic
 * and needs no fault injection into Postgres itself.
 */
class ProvisioningAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The audit store is the precondition for provisioning at all.
        Artisan::call('console:migrate');
    }

    public function test_console_migrate_is_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('console:migrate'));
        $this->assertSame(0, Artisan::call('console:migrate'));

        $console = SchemaConnection::pinConsole();

        $this->assertTrue(
            $console->getSchemaBuilder()->hasTable('provisioning_audit'),
            'console:migrate must leave the audit table in place.'
        );
    }

    public function test_the_audit_store_is_not_readable_by_public(): void
    {
        $granted = DB::connection('pgsql_admin')->select(
            'select privilege_type from information_schema.usage_privileges
             where object_schema = ? and grantee = ?',
            [SchemaName::CONSOLE, 'PUBLIC'],
        );

        $this->assertSame([], $granted, 'PUBLIC must hold nothing on the console schema.');
    }

    public function test_audit_entries_can_never_be_changed_or_removed(): void
    {
        $console = SchemaConnection::pinConsole();
        $audit = new ProvisioningAudit('immutability-test');

        $audit->record('test.immutability', ProvisioningAudit::SUCCEEDED, [
            'schema_name' => 'biz_'.str_repeat('0', 32),
        ]);

        $id = $console->table('provisioning_audit')
            ->where('action', 'test.immutability')
            ->value('id');

        $this->assertNotNull($id);

        // Append-only, enforced by trigger — so it binds the console itself,
        // which is the only thing that ever reaches this schema.
        foreach ([
            fn () => $console->table('provisioning_audit')->where('id', $id)->update(['actor' => 'someone else']),
            fn () => $console->table('provisioning_audit')->where('id', $id)->delete(),
        ] as $mutation) {
            try {
                $console->transaction($mutation);
                $this->fail('The provisioning audit trail accepted a mutation.');
            } catch (Throwable $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }

    public function test_a_failure_after_the_schema_exists_rolls_everything_back(): void
    {
        $businessId = (string) Str::orderedUuid();
        $branchId = (string) Str::orderedUuid();

        $schema = SchemaName::forBusiness($businessId);
        $role = RoleName::forBranch($branchId);
        $path = sys_get_temp_dir().'/evitan-rollback-'.Str::lower(Str::random(8)).'.json';

        $provisioner = new BusinessProvisioner(new ProvisioningAudit('rollback-test'), new CredentialIssuance('rollback-test'));

        try {
            $provisioner->provision(
                [
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'owner_name' => 'Rollback Owner',
                    'username' => 'rollback_owner',
                    'password' => 'rollback-Passw0rd!',
                    'business_name' => 'Rollback Business',
                    'business_address' => '',
                    'tin' => '',
                    'receipt_footer' => '',
                    'timezone' => 'Asia/Manila',
                    'currency_symbol' => '₱',
                    'branch_name' => 'Rollback Branch',
                    'branch_code' => 'RBK',
                    'branch_address' => '',
                    'out' => $path,
                ],
                // Fail at the last possible moment: by now the schema is
                // migrated, seeded, and the role exists with its grants — so
                // every undo step has real work to do.
                function (string $step): void {
                    if (str_starts_with($step, 'Writing the credentials JSON')) {
                        throw new RuntimeException('induced failure');
                    }
                },
            );

            $this->fail('The induced failure did not surface.');
        } catch (RuntimeException $e) {
            // The ORIGINAL cause must be what the operator is told, not a
            // cleanup error that happened afterwards.
            $this->assertStringContainsString('induced failure', $e->getMessage());
            $this->assertStringContainsString('Nothing was left behind', $e->getMessage());
        }

        // Nothing survives: no schema, no role, no credentials file.
        $this->assertFalse(SchemaConnection::exists($schema), 'Rollback left the schema behind.');
        $this->assertFalse(BranchRole::exists($role), 'Rollback left the Postgres role behind.');
        $this->assertFileDoesNotExist($path, 'Rollback left a credentials file behind.');

        // And the attempt is on the record, naming what it was trying to build.
        $entry = SchemaConnection::pinConsole()
            ->table('provisioning_audit')
            ->where('schema_name', $schema)
            ->first();

        $this->assertNotNull($entry, 'A failed provision must still be audited.');
        $this->assertSame(BusinessProvisioner::ACTION, $entry->action);
        $this->assertSame(ProvisioningAudit::ROLLED_BACK, $entry->outcome);
        $this->assertSame('RBK', $entry->branch_code);
        $this->assertStringContainsString('induced failure', (string) $entry->detail);
    }

    /**
     * A failure DURING the migration must not orphan the schema.
     *
     * The undo for the schema used to be pushed after migrateSchema() returned,
     * which left the whole create-plus-19-migrations window uncompensated —
     * schema:migrate creates the schema first, and Laravel's migrator commits
     * one migration at a time. A failure in there therefore left a half-built
     * `biz_<uuid>` behind while the rollback reported "Nothing was left behind"
     * and the create-only preflight refused the retry.
     *
     * The failure is induced by pre-creating a `users` table the migration set
     * cannot create over. That is also why this test calls provision() directly:
     * the command's preflight would refuse an existing schema, which is the
     * whole reason the state was so hard to get out of.
     */
    public function test_a_failure_during_the_migration_does_not_orphan_the_schema(): void
    {
        $businessId = (string) Str::orderedUuid();
        $branchId = (string) Str::orderedUuid();

        $schema = SchemaName::forBusiness($businessId);
        $path = sys_get_temp_dir().'/evitan-orphan-'.Str::lower(Str::random(8)).'.json';

        $admin = DB::connection('pgsql_admin');
        $admin->statement("create schema \"{$schema}\"");
        $admin->statement("create table \"{$schema}\".users (wrong_shape int)");

        try {
            (new BusinessProvisioner(new ProvisioningAudit('orphan-test'), new CredentialIssuance('orphan-test')))
                ->provision([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'owner_name' => 'Orphan Owner',
                    'username' => 'orphan_owner',
                    'password' => 'orphan-Passw0rd!',
                    'business_name' => 'Orphan Business',
                    'business_address' => '',
                    'tin' => '',
                    'receipt_footer' => '',
                    'timezone' => 'Asia/Manila',
                    'currency_symbol' => '₱',
                    'branch_name' => 'Orphan Branch',
                    'branch_code' => 'ORP',
                    'branch_address' => '',
                    'out' => $path,
                ], function (string $step): void {});

            $failure = null;
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }

        // Assert before cleaning up, or the cleanup hides the answer.
        $survived = SchemaConnection::exists($schema);

        $admin->statement("drop schema if exists \"{$schema}\" cascade");
        @unlink($path);

        $this->assertNotNull($failure, 'Migrating over a conflicting table did not fail.');
        $this->assertFalse($survived, 'A failure during the migration left the schema behind.');
        $this->assertFileDoesNotExist($path, 'The rollback left a credentials file behind.');
    }
}
