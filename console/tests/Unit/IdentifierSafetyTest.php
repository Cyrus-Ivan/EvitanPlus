<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Provisioning\Support\BranchCode;
use Provisioning\Support\BranchSecret;
use Provisioning\Support\RoleName;
use Provisioning\Support\SchemaName;

/**
 * The injection boundaries and the branch code, tested without a database.
 *
 * Extends PHPUnit's TestCase directly rather than Tests\TestCase: these need no
 * application and no admin credentials, so they are the part of the console
 * suite that stays green on a fresh clone. That matters because these classes
 * are what stand between operator input and raw DDL.
 */
class IdentifierSafetyTest extends TestCase
{
    public function test_a_role_name_is_derived_from_the_branch_uuid(): void
    {
        $role = RoleName::forBranch('a259bfe4-82e8-45a2-8fee-b2b63fadec16');

        $this->assertSame('branch_a259bfe482e845a28feeb2b63fadec16', $role);
        // Comfortably inside Postgres's 63-byte identifier limit, which silently
        // truncates and would therefore alias two branches onto one role.
        $this->assertLessThanOrEqual(RoleName::MAX_LENGTH, strlen($role));
    }

    #[DataProvider('hostileIdentifiers')]
    public function test_a_role_name_that_is_not_safe_to_interpolate_is_rejected(string $role): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleName::validate($role);
    }

    /** @return iterable<string, array{string}> */
    public static function hostileIdentifiers(): iterable
    {
        yield 'a quote that would close the identifier' => ['branch_a"; drop table users; --'];
        yield 'uppercase, which Postgres folds' => ['Branch_ABC'];
        yield 'a dot, which would qualify a schema' => ['branch_a.b'];
        yield 'a space' => ['branch_a b'];
        yield 'the wrong prefix' => ['admin_abc'];
        yield 'no suffix at all' => ['branch_'];
        yield 'beyond the identifier limit' => ['branch_'.str_repeat('a', 60)];
    }

    public function test_a_role_name_must_come_from_a_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RoleName::forBranch('not-a-uuid');
    }

    public function test_the_console_schema_is_admitted_but_never_as_a_business(): void
    {
        // Safe to interpolate: it is a compile-time constant.
        $this->assertSame(SchemaName::CONSOLE, SchemaName::validate(SchemaName::CONSOLE));

        // But it is not a business schema, so the business migration set must
        // never be pointed at it.
        $this->expectException(InvalidArgumentException::class);
        SchemaName::resolve(null, SchemaName::CONSOLE);
    }

    #[DataProvider('branchCodes')]
    public function test_a_branch_code_is_canonicalised_or_rejected(string $input, ?string $expected): void
    {
        if ($expected === null) {
            $this->expectException(InvalidArgumentException::class);
            BranchCode::validate($input);

            return;
        }

        $this->assertSame($expected, BranchCode::validate($input));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function branchCodes(): iterable
    {
        // A printed receipt number must have exactly one spelling, so case and
        // surrounding whitespace are normalised rather than refused.
        yield 'lowercase becomes uppercase' => ['mnl', 'MNL'];
        yield 'surrounding whitespace is trimmed' => ['  CEB  ', 'CEB'];
        yield 'digits are allowed' => ['mnl2', 'MNL2'];
        yield 'an internal dash is allowed' => ['mnl-north', 'MNL-NORTH'];
        yield 'sixteen characters is the limit' => ['abcdefghijklmnop', 'ABCDEFGHIJKLMNOP'];

        yield 'seventeen characters is too long' => ['abcdefghijklmnopq', null];
        yield 'an internal space is refused' => ['MNL 1', null];
        yield 'a dot is refused' => ['MNL.1', null];
        yield 'a leading dash is refused' => ['-MNL', null];
        yield 'a quote is refused' => ["MNL'", null];
        yield 'empty is refused' => ['', null];
        yield 'whitespace only is refused' => ['   ', null];
    }

    public function test_a_generated_secret_survives_every_channel_it_travels(): void
    {
        $secret = BranchSecret::generate();

        $this->assertSame(BranchSecret::LENGTH, strlen($secret));
        // Alphanumeric only, so the same value is safe in a Postgres literal, a
        // libpq URI, a JSON string, a .env line and a shell argument.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $secret);
        $this->assertNotSame(BranchSecret::generate(), $secret);

        $this->assertSame("'{$secret}'", BranchSecret::quote($secret));
    }

    #[DataProvider('unquotableSecrets')]
    public function test_quoting_refuses_a_secret_it_did_not_generate(string $secret): void
    {
        // Deliberately a crash, not an escape: a password needing escapes also
        // breaks the URI and .env paths, so accepting it here would move the
        // failure somewhere far harder to diagnose.
        $this->expectException(InvalidArgumentException::class);

        BranchSecret::quote($secret);
    }

    /** @return iterable<string, array{string}> */
    public static function unquotableSecrets(): iterable
    {
        yield 'a quote' => ["abcdefghijklmnop'; drop role x; --"];
        yield 'a backslash' => ['abcdefghijklmnop\\x'];
        yield 'too short' => ['short'];
        yield 'empty' => [''];
    }

    public function test_a_secret_below_the_minimum_length_is_never_generated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchSecret::generate(8);
    }

    /**
     * M3's commands start from a schema name and still have to put the business
     * UUID in the credentials JSON, so the derivation has to run backwards. The
     * round trip is the guarantee: there is no registry to fall back on if it
     * ever stopped agreeing with itself.
     */
    public function test_a_business_uuid_survives_the_round_trip_to_a_schema_name(): void
    {
        $uuid = '0198f4c2-3a1b-7c4d-8e9f-0a1b2c3d4e5f';

        $schema = SchemaName::forBusiness($uuid);

        $this->assertSame('biz_0198f4c23a1b7c4d8e9f0a1b2c3d4e5f', $schema);
        $this->assertSame($uuid, SchemaName::toBusinessUuid($schema));

        // Case and dashes are normalised on the way in, so the way back is
        // canonical rather than a copy of whatever was typed.
        $this->assertSame($uuid, SchemaName::toBusinessUuid(SchemaName::forBusiness(strtoupper($uuid))));
    }

    public function test_a_schema_not_derived_from_a_uuid_has_no_business_uuid(): void
    {
        // Valid to interpolate, but not something forBusiness ever produced —
        // the test schemas are exactly this shape.
        $this->expectException(InvalidArgumentException::class);

        SchemaName::toBusinessUuid('biz_test_abcdef123456');
    }

    /**
     * The fingerprint is compared, never verified: M5 computes it on the device
     * and M10 compares the two. Determinism is therefore the whole contract, and
     * a well-meant switch to a salted hash would break it silently.
     */
    public function test_a_fingerprint_is_deterministic_and_reveals_nothing(): void
    {
        $secret = BranchSecret::generate();

        $this->assertSame(BranchSecret::fingerprint($secret), BranchSecret::fingerprint($secret));
        $this->assertNotSame(BranchSecret::fingerprint(BranchSecret::generate()), BranchSecret::fingerprint($secret));

        $fingerprint = BranchSecret::fingerprint($secret);

        $this->assertStringStartsWith('sha256:', $fingerprint);
        $this->assertStringNotContainsString($secret, $fingerprint);

        // Domain-separated, so it cannot collide with a bare sha256 of the same
        // string computed somewhere else for some other purpose.
        $this->assertNotSame('sha256:'.hash('sha256', $secret), $fingerprint);
    }

    public function test_an_empty_secret_is_never_fingerprinted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchSecret::fingerprint('');
    }
}
