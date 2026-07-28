<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Provisioning\Support\BranchRole;
use Provisioning\Support\BusinessProvisioner;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Tests\TestCase;
use Throwable;

/**
 * "Nothing is created until input is complete and validated" (M2).
 *
 * Every case here must refuse AND leave no trace — no schema, no role, no
 * credentials file. Cheap by design: not one of these provisions anything, so
 * the whole class costs no migrations.
 */
class BusinessProvisionValidationTest extends TestCase
{
    /** @var list<string> */
    private static array $schemasToDrop = [];

    /** @var list<string> */
    private static array $rolesToDrop = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$schemasToDrop as $schema) {
            try {
                DB::connection('pgsql_admin')->statement("drop schema if exists \"{$schema}\" cascade");
            } catch (Throwable) {
            }
        }

        foreach (self::$rolesToDrop as $role) {
            try {
                DB::connection('pgsql_admin')->statement("drop role if exists \"{$role}\"");
            } catch (Throwable) {
            }
        }

        self::$schemasToDrop = [];
        self::$rolesToDrop = [];

        parent::tearDownAfterClass();
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidInput')]
    public function test_it_refuses_invalid_input_and_creates_nothing(array $overrides): void
    {
        $businessId = (string) Str::orderedUuid();
        $schema = SchemaName::forBusiness($businessId);

        $exit = Artisan::call('business:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--business' => $businessId,
            ...$overrides,
        ]);

        $this->assertNotSame(0, $exit, 'Invalid input must not provision. Output: '.Artisan::output());
        $this->assertFalse(SchemaConnection::exists($schema), 'A refused provision left a schema behind.');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidInput(): iterable
    {
        yield 'a password below the app minimum' => [self::input(['--owner-password' => 'short'])];
        yield 'a timezone that does not exist' => [self::input(['--timezone' => 'Mars/Olympus'])];
        yield 'a currency symbol beyond 5 characters' => [self::input(['--currency-symbol' => 'PESOS!'])];
        yield 'a branch code containing a space' => [self::input(['--branch-code' => 'MNL 1'])];
        yield 'a branch code containing a dot' => [self::input(['--branch-code' => 'MNL.1'])];
        yield 'a branch code beyond 16 characters' => [self::input(['--branch-code' => 'ABCDEFGHIJKLMNOPQ'])];
        yield 'an over-long business name' => [self::input(['--business-name' => str_repeat('a', 256)])];

        // Non-interactively there is nobody to prompt, so a gap is an error
        // rather than a silent default.
        $withoutTimezone = self::input([]);
        unset($withoutTimezone['--timezone']);
        yield 'a missing required field' => [$withoutTimezone];
    }

    public function test_it_refuses_a_business_uuid_that_is_not_a_uuid(): void
    {
        $exit = Artisan::call('business:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--business' => 'not-a-uuid',
            ...self::input([]),
        ]);

        $this->assertNotSame(0, $exit);
    }

    /**
     * Create-only: the guarantee that makes compensating cleanup safe. If this
     * ever provisioned into an existing schema, a failure could drop a live
     * business.
     */
    public function test_it_refuses_when_the_schema_already_exists(): void
    {
        $businessId = (string) Str::orderedUuid();
        $schema = SchemaName::forBusiness($businessId);

        // A bare schema, deliberately unmigrated — cheap, and enough to collide.
        DB::connection('pgsql_admin')->statement("create schema \"{$schema}\"");
        self::$schemasToDrop[] = $schema;
        DB::connection('pgsql_admin')->statement("create table \"{$schema}\".keep_me (id int)");

        $exit = Artisan::call('business:provision', [
            '--no-interaction' => true,
            '--force' => true,
            '--business' => $businessId,
            ...self::input([]),
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('create-only', Artisan::output());

        // Untouched: the refusal must not have dropped or migrated anything.
        $this->assertTrue(SchemaConnection::exists($schema));
        $this->assertNotNull(DB::connection('pgsql_admin')->selectOne(
            'select 1 as f from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, 'keep_me'],
        ));
    }

    public function test_it_refuses_when_the_credentials_path_is_already_taken(): void
    {
        $businessId = (string) Str::orderedUuid();
        $schema = SchemaName::forBusiness($businessId);

        $path = sys_get_temp_dir().'/evitan-taken-'.Str::lower(Str::random(8)).'.json';
        file_put_contents($path, "existing\n");

        try {
            $exit = Artisan::call('business:provision', [
                '--no-interaction' => true,
                '--force' => true,
                '--business' => $businessId,
                '--out' => $path,
                ...self::input([]),
            ]);

            $this->assertNotSame(0, $exit);
            // Never overwritten: it might be a credential already delivered.
            $this->assertSame("existing\n", file_get_contents($path));
            // And the refusal came before any DDL.
            $this->assertFalse(SchemaConnection::exists($schema));
        } finally {
            @unlink($path);
        }
    }

    public function test_it_refuses_when_the_branch_role_name_is_taken(): void
    {
        // The role name derives from a freshly minted branch UUID, so a
        // collision cannot be forced from outside — assert the guard directly.
        $role = 'branch_'.bin2hex(random_bytes(16));

        DB::connection('pgsql_admin')->statement("create role \"{$role}\" nologin");
        self::$rolesToDrop[] = $role;

        $this->assertTrue(BranchRole::exists($role));

        $problems = (new BusinessProvisioner(new ProvisioningAudit, new CredentialIssuance))
            ->preflight(SchemaName::forBusiness((string) Str::orderedUuid()), $role);

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString($role, implode(' ', $problems));
    }

    /**
     * A complete, valid option set, with overrides applied.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private static function input(array $overrides): array
    {
        return [
            '--owner-name' => 'Validation Owner',
            '--username' => 'validation_owner',
            '--owner-password' => 'validation-Passw0rd!',
            '--business-name' => 'Validation Business',
            '--timezone' => 'Asia/Manila',
            '--currency-symbol' => '₱',
            '--branch-name' => 'Validation Branch',
            '--branch-code' => 'VAL',
            ...$overrides,
        ];
    }
}
