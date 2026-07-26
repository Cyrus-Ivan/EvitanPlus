<?php

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Provisioning\Support\BranchConnection;
use Provisioning\Support\BranchRole;
use Throwable;

/**
 * Provisions ONE real business per test class, and cleans it up afterwards.
 *
 * ## Why once per class
 *
 * Same economics that shaped Tests\TestCase: a full provision applies 19
 * migrations against a remote Postgres (~15s) and consumes pooler sessions,
 * and Supabase's session pooler allows 15 clients. So each class pays for one
 * provision and asserts many things about it, rather than provisioning per test.
 *
 * ## Why these tests cannot use the base class's schema
 *
 * Tests\TestCase::migratedSchema() hands out a pre-migrated schema, but
 * provisioning must CREATE its own — that is the behaviour under test. Both are
 * lazy, so a class using this trait simply never calls migratedSchema() and the
 * base class's tearDownAfterClass finds nothing to drop. Nor can any of this run
 * inside a per-test transaction: it is DDL, and Laravel's migrator commits as it
 * goes.
 *
 * ## Cleanup is not optional
 *
 * Postgres roles are CLUSTER-wide, so a leaked branch_* role accumulates in the
 * real Supabase project on every run — unlike a leaked schema, it is not
 * namespaced by anything. Each using class must call
 * tearDownProvisionedBusiness() from its own tearDownAfterClass().
 */
trait ProvisionsBusiness
{
    /** The credentials document the command emitted. @var array<string, mixed>|null */
    private static ?array $provisioned = null;

    private static ?string $provisionedPath = null;

    private static ?Connection $branchConnection = null;

    /** The Owner password the fixture provisions with. */
    protected const OWNER_PASSWORD = 'fixture-Passw0rd!';

    /**
     * The emitted credentials document, provisioning on first use.
     *
     * @return array<string, mixed>
     */
    protected function provisioned(): array
    {
        if (self::$provisioned !== null) {
            return self::$provisioned;
        }

        // The audit store is a hard precondition of provisioning, so make sure
        // it exists rather than letting every class fail the preflight.
        Artisan::call('console:migrate');

        $path = sys_get_temp_dir().'/evitan-fixture-'.Str::lower(Str::random(12)).'.json';

        $exit = Artisan::call('business:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--owner-name' => 'Fixture Owner',
            '--username' => 'fixture_owner',
            '--owner-password' => self::OWNER_PASSWORD,
            '--business-name' => 'Fixture Business',
            '--business-address' => '1 Fixture Street',
            '--tin' => '000-111-222-333',
            '--receipt-footer' => 'Fixture footer',
            '--timezone' => 'Asia/Manila',
            '--currency-symbol' => '₱',
            '--branch-name' => 'Fixture Branch',
            // Lowercase on purpose: the fixture also proves canonicalisation.
            '--branch-code' => 'fix',
            '--branch-address' => '1 Fixture Street',
            '--out' => $path,
        ]);

        $this->assertSame(0, $exit, 'Provisioning the fixture business failed: '.Artisan::output());
        $this->assertFileExists($path);

        self::$provisionedPath = $path;
        self::$provisioned = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return self::$provisioned;
    }

    protected function credentialsPath(): string
    {
        $this->provisioned();

        return (string) self::$provisionedPath;
    }

    protected function schema(): string
    {
        return (string) $this->provisioned()['business']['schema'];
    }

    protected function roleName(): string
    {
        return (string) $this->provisioned()['role']['name'];
    }

    /**
     * A connection authenticated AS the provisioned branch role.
     *
     * search_path is intentionally left unset so every unqualified query here
     * also exercises the server-side ALTER ROLE pin.
     */
    protected function branch(): Connection
    {
        if (self::$branchConnection === null) {
            $connection = $this->provisioned()['connection'];

            self::$branchConnection = BranchConnection::open(
                (string) $connection['username'],
                (string) $connection['password'],
            );
        }

        return self::$branchConnection;
    }

    /**
     * Drop everything the fixture created. Best-effort per step: one failure
     * must not strand the others, and none of them may mask a test result.
     */
    protected static function tearDownProvisionedBusiness(): void
    {
        if (self::$provisioned === null) {
            return;
        }

        $schema = (string) self::$provisioned['business']['schema'];
        $role = (string) self::$provisioned['role']['name'];

        // First: an open branch session holds locks that block DROP SCHEMA.
        try {
            BranchConnection::close();
        } catch (Throwable) {
        }

        self::$branchConnection = null;

        try {
            DB::connection('pgsql_admin')->statement("drop schema if exists \"{$schema}\" cascade");
        } catch (Throwable) {
        }

        try {
            BranchRole::drop($role, $schema);
        } catch (Throwable) {
        }

        if (self::$provisionedPath !== null && is_file(self::$provisionedPath)) {
            @unlink(self::$provisionedPath);
        }

        self::$provisioned = null;
        self::$provisionedPath = null;
    }
}
