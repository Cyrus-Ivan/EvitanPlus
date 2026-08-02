<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Provisioning\Support\BranchConnection;
use Provisioning\Support\BranchIssuer;
use Provisioning\Support\BranchRole;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\RoleName;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Provisioning\Support\SchemaVersion;
use RuntimeException;
use Tests\Concerns\AssertsBranchGrants;
use Tests\Concerns\ProvisionsBusiness;
use Tests\TestCase;
use Throwable;

/**
 * Adding a branch to a business that already exists (§8.2, Phase M3).
 *
 * The fixture business supplies the "already exists" half; every test here adds
 * to it. The assertion that matters most is the last one: unlike
 * business:provision, this command compensates inside a schema it did not
 * create, so a failed run must remove exactly what it made and leave the
 * pre-existing branch and its history untouched.
 */
class BranchProvisionTest extends TestCase
{
    use AssertsBranchGrants;
    use ProvisionsBusiness;

    /** @var list<string> Roles this class created outside the fixture. */
    private static array $rolesToDrop = [];

    public static function tearDownAfterClass(): void
    {
        // The business schema goes FIRST, and the ordering is not incidental:
        // CASCADE takes the tables and therefore the ACL entries referencing
        // these roles, and Postgres refuses to drop a role while any grant
        // still names it. Dropping the roles first leaves every one of them
        // behind — and roles are CLUSTER-wide, so the leak accumulates in the
        // real Supabase project on every run.
        self::tearDownProvisionedBusiness();

        foreach (self::$rolesToDrop as $role) {
            try {
                BranchRole::drop($role);
            } catch (Throwable) {
            }
        }

        self::$rolesToDrop = [];

        parent::tearDownAfterClass();
    }

    public function test_it_adds_a_branch_with_a_working_credential(): void
    {
        $path = $this->tempPath();
        $before = $this->branchCount();

        $exit = $this->provisionBranch('CEB', 'Cebu Branch', $path);

        $this->assertSame(0, $exit, 'branch:provision failed: '.Artisan::output());
        $this->assertFileExists($path);

        $document = $this->read($path);

        // The business half is recovered from the schema name and the settings
        // table — there is no registry to look it up in.
        $this->assertSame($this->schema(), $document['business']['schema']);
        $this->assertSame(SchemaName::toBusinessUuid($this->schema()), $document['business']['id']);
        $this->assertSame('Fixture Business', $document['business']['name']);

        $this->assertSame('CEB', $document['branch']['code']);
        $this->assertSame('Cebu Branch', $document['branch']['name']);

        // Read back from schema_meta, never assumed (§9 As built) — it is what
        // M5's version gate compares the app against.
        $this->assertSame(SchemaVersion::SCHEMA_VERSION, $document['versions']['schema_version']);
        $this->assertNotEmpty($document['versions']['min_app_version']);

        // The one copy of a live database password.
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $role = RoleName::forBranch($document['branch']['id']);
        self::$rolesToDrop[] = $role;

        $this->assertSame($role, $document['role']['name']);
        $this->assertTrue(BranchRole::exists($role));

        // No new schema and no re-migration: the branch joins the existing one,
        // which is what §8.2 asks for and what makes hydration (M5) work.
        $this->assertSame($before + 1, $this->branchCount());

        // The constant rather than a literal: schema version IS the migration
        // count (SchemaVersion), so a literal here would have to be edited by
        // every migration that ever lands — and would fail long after the change
        // that broke it, saying only "20 is not 19".
        $this->assertSame(SchemaVersion::SCHEMA_VERSION, (int) SchemaConnection::pin($this->schema())
            ->selectOne('select count(*) as v from migrations')->v);
    }

    public function test_the_new_role_connects_and_carries_the_full_grant_matrix(): void
    {
        $path = $this->tempPath();

        $this->assertSame(0, $this->provisionBranch('DVO', 'Davao Branch', $path));

        $document = $this->read($path);
        $role = RoleName::forBranch($document['branch']['id']);
        self::$rolesToDrop[] = $role;

        // Every branch of a business gets the same privileges — a second branch
        // is not a lesser one.
        $this->assertGrantsMatchMatrix($role, $this->schema());

        try {
            $connection = BranchConnection::open(
                (string) $document['connection']['username'],
                (string) $document['connection']['password'],
            );

            // Opened with NO search_path, so this can only pass because of the
            // server-side ALTER ROLE pin.
            $this->assertSame($this->schema(), $connection->selectOne('select current_schema() as v')->v);

            // It sees its own branch AND its siblings: one schema per business,
            // so the branch list and the catalog are shared. Asserted by naming
            // a sibling rather than by counting rows — other tests in this
            // class add branches too, and a total would only be testing them.
            $this->assertSame('DVO', $connection->selectOne(
                'select code from branches where code = ?', ['DVO']
            )->code);
            $this->assertSame('FIX', $connection->selectOne(
                'select code from branches where code = ?', ['FIX']
            )->code);
        } finally {
            BranchConnection::close();
        }
    }

    public function test_it_refuses_a_branch_code_already_used_in_this_business(): void
    {
        // FIX is the fixture branch's code. Codes prefix receipt serials (§12),
        // so a duplicate would make two branches' receipt numbers collide.
        $path = $this->tempPath();
        $before = $this->branchCount();

        $this->assertNotSame(0, $this->provisionBranch('FIX', 'Duplicate Branch', $path));

        $output = Artisan::output();
        $this->assertStringContainsString('already in use', $output);

        // Refused during validation, so nothing was created — not even the file.
        $this->assertFileDoesNotExist($path);
        $this->assertSame($before, $this->branchCount());
    }

    public function test_it_refuses_a_schema_that_is_not_a_business(): void
    {
        $missing = SchemaName::forBusiness((string) Str::uuid());

        $exit = Artisan::call('branch:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $missing,
            '--branch-name' => 'Nowhere Branch',
            '--branch-code' => 'NOW',
            '--out' => $this->tempPath(),
        ]);

        $this->assertNotSame(0, $exit);

        // The refusal names the command that would fix it.
        $this->assertStringContainsString('business:provision', Artisan::output());
    }

    public function test_it_requires_every_field_when_non_interactive(): void
    {
        $exit = Artisan::call('branch:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-name' => 'Incomplete Branch',
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('--branch-code', Artisan::output());
    }

    /**
     * The heart of M3's failure story.
     *
     * BusinessProvisioner can compensate by dropping the schema, because it made
     * it. This cannot: the schema holds another branch's history. So the undo
     * must remove exactly the three objects the run created and nothing more —
     * proven by asserting the pre-existing branch is still there afterwards.
     */
    public function test_a_failed_run_removes_only_what_it_created(): void
    {
        $schema = $this->schema();
        $path = $this->tempPath();
        $branchId = (string) Str::orderedUuid();
        $role = RoleName::forBranch($branchId);

        $issuer = new BranchIssuer(new ProvisioningAudit('test'), new CredentialIssuance('test'));

        $before = $this->branchCount();
        $auditBefore = $this->auditCount(BranchIssuer::PROVISION);

        try {
            $issuer->provision($schema, [
                'branch_id' => $branchId,
                'branch_name' => 'Doomed Branch',
                'branch_code' => 'DOOM',
                'branch_address' => '',
                'out' => $path,
            ], function (string $step) {
                // Fail as late as possible: the role, the branches row and the
                // reserved file all exist by now, so every undo has work to do.
                if ($step === 'Writing the credentials JSON') {
                    throw new RuntimeException('Simulated failure.');
                }
            });

            $this->fail('The issuer reported success despite a failing step.');
        } catch (RuntimeException $e) {
            // The ORIGINAL error is the headline, not a cleanup complaint.
            $this->assertStringContainsString('Simulated failure.', $e->getMessage());
            $this->assertStringContainsString('Nothing was left behind.', $e->getMessage());
        }

        // Everything the run created is gone.
        $this->assertFalse(BranchRole::exists($role), 'The role survived a rolled-back run.');
        $this->assertFileDoesNotExist($path);
        $this->assertNull(SchemaConnection::pin($schema)
            ->selectOne('select id from branches where id = ?', [$branchId]));

        // And nothing else is.
        $this->assertTrue(SchemaConnection::exists($schema), 'The rollback dropped a schema it did not create.');
        $this->assertSame($before, $this->branchCount());
        $this->assertNotNull(SchemaConnection::pin($schema)
            ->selectOne('select code from branches where code = ?', ['FIX']));

        // The failure is on the record, and marked as having left nothing.
        $this->assertSame($auditBefore + 1, $this->auditCount(BranchIssuer::PROVISION));

        // Scoped to THIS branch, not "the newest row of this action".
        // `provisioning_audit` is shared, append-only and never reset between
        // runs, so "newest" is only this run's row while every writer agrees
        // about the clock — and one did not: the integration suite shells out to
        // `console/artisan` from the POS app, whose putenv'd APP_TIMEZONE
        // overrode console/.env and stamped rows eight hours into the future.
        // That leak is fixed in Tests\Integration\Concerns\ProvisionsABusiness,
        // but a test that can be broken by an unrelated suite's clock was
        // fragile on its own terms. The branch id is unique to this run.
        $latest = SchemaConnection::pinConsole()->selectOne(
            'select outcome, detail from provisioning_audit where action = ? and branch_id = ?',
            [BranchIssuer::PROVISION, $branchId],
        );

        $this->assertSame(ProvisioningAudit::ROLLED_BACK, $latest->outcome);
        $this->assertStringContainsString('Simulated failure.', (string) $latest->detail);

        // A rolled-back provision issued no credential, so it recorded none.
        $this->assertSame(0, (new CredentialIssuance)->generations($branchId));
    }

    private function provisionBranch(string $code, string $name, string $path): int
    {
        return Artisan::call('branch:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--schema' => $this->schema(),
            '--branch-name' => $name,
            '--branch-code' => $code,
            '--branch-address' => '1 Test Road',
            '--out' => $path,
        ]);
    }

    private function branchCount(): int
    {
        return (int) SchemaConnection::pin($this->schema())
            ->selectOne('select count(*) as v from branches')->v;
    }

    private function auditCount(string $action): int
    {
        return (int) SchemaConnection::pinConsole()->selectOne(
            'select count(*) as v from provisioning_audit where action = ?',
            [$action],
        )->v;
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir().'/evitan-branch-'.Str::lower(Str::random(12)).'.json';

        // Registered for cleanup even when the test that made it fails.
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $path;
    }
}
