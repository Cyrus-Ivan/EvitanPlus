<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Provisioning\Support\BranchSecret;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use Tests\TestCase;
use Throwable;

/**
 * The credential issuance record's own guarantees (M3).
 *
 * Cheap by design: no business schema, no roles — just `console:migrate` and the
 * table. The constraints asserted here are the reason this is a table rather
 * than a few audit rows, so they are worth proving directly rather than only as
 * a side effect of the lifecycle tests.
 *
 * Rows are written straight to the table rather than through commands, because
 * what is under test is what the DATABASE refuses. A test that could only
 * produce violations by going through the code that avoids them would prove the
 * code, not the constraint.
 */
class CredentialIssuanceTest extends TestCase
{
    private const TABLE = 'credential_issuance';

    /** @var list<string> */
    private static array $branchIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('console:migrate');
    }

    public static function tearDownAfterClass(): void
    {
        // This table is append-only by trigger, so its own rows cannot be
        // deleted — the schema outlives the test either way. Truncation is a
        // DDL-ish operation the trigger does not block, and it is the only way
        // to keep a developer's audit store from accumulating probe rows.
        if (self::$branchIds !== []) {
            try {
                SchemaConnection::pinConsole()->statement(
                    'delete from '.self::TABLE.' where branch_id = any(?::uuid[])',
                    ['{'.implode(',', self::$branchIds).'}'],
                );
            } catch (Throwable) {
                // Expected: the trigger forbids DELETE. Left in place; the rows
                // are inert and name a branch that never existed.
            }
        }

        self::$branchIds = [];

        parent::tearDownAfterClass();
    }

    public function test_it_records_generations_and_supersedes_the_previous_one(): void
    {
        $issuance = new CredentialIssuance('test');
        $target = $this->target();

        $this->assertSame(1, $issuance->record($target, BranchSecret::generate(), CredentialIssuance::PROVISION));
        $this->assertSame(2, $issuance->record($target, BranchSecret::generate(), CredentialIssuance::ROTATE));
        $this->assertSame(3, $issuance->record($target, BranchSecret::generate(), CredentialIssuance::RECREATE));

        $current = $issuance->current($target['branch_id']);

        $this->assertNotNull($current);
        $this->assertSame(3, (int) $current->generation);
        $this->assertSame(3, $issuance->generations($target['branch_id']));

        // Exactly one live row; the rest carry a supersede timestamp.
        $this->assertSame(1, $this->rowsWhere($target['branch_id'], 'superseded_at is null'));
        $this->assertSame(2, $this->rowsWhere($target['branch_id'], 'superseded_at is not null'));
    }

    public function test_supersede_leaves_the_branch_with_no_live_credential(): void
    {
        $issuance = new CredentialIssuance('test');
        $target = $this->target();

        $issuance->record($target, BranchSecret::generate(), CredentialIssuance::PROVISION);

        $this->assertSame(1, $issuance->supersede($target['branch_id']));

        // Not "generation 0" and not a deleted row: the branch has a history of
        // one credential and no current one, which is what a revoked role is.
        $this->assertNull($issuance->current($target['branch_id']));
        $this->assertSame(1, $issuance->generations($target['branch_id']));

        // Superseding again is a no-op, not a second write.
        $this->assertNull($issuance->supersede($target['branch_id']));
    }

    /**
     * The counter continues from the highest generation ever issued, not from
     * the live one — and after a revoke there IS no live one.
     *
     * Counting from the live row restarts at 1 after a revoke, which collides
     * with the row already there and, worse, would make "generation 1" name two
     * different credentials. That is exactly the revoke-then-recreate sequence
     * §8.3 describes, so it is the normal path, not an edge case.
     */
    public function test_the_generation_counter_survives_a_revoke(): void
    {
        $issuance = new CredentialIssuance('test');
        $target = $this->target();

        $issuance->record($target, BranchSecret::generate(), CredentialIssuance::PROVISION);
        $issuance->record($target, BranchSecret::generate(), CredentialIssuance::ROTATE);

        // branch:revoke — the branch is left with no live credential at all.
        $issuance->supersede($target['branch_id']);
        $this->assertNull($issuance->current($target['branch_id']));

        // branch:rotate --recreate
        $this->assertSame(3, $issuance->record($target, BranchSecret::generate(), CredentialIssuance::RECREATE));
        $this->assertSame(3, $issuance->generations($target['branch_id']));
        $this->assertSame(1, $this->rowsWhere($target['branch_id'], 'superseded_at is null'));
    }

    /**
     * The invariant that makes "is this till current?" answerable at all. Two
     * live rows would make the question ambiguous, so the database refuses.
     */
    public function test_a_branch_cannot_have_two_live_credentials(): void
    {
        $target = $this->target();

        (new CredentialIssuance('test'))->record($target, BranchSecret::generate(), CredentialIssuance::PROVISION);

        $this->assertRefused(
            fn (Connection $db) => $db->table(self::TABLE)->insert($this->row($target, 2)),
            'unique',
        );
    }

    public function test_only_superseded_at_may_change_and_only_once(): void
    {
        $target = $this->target();

        (new CredentialIssuance('test'))->record($target, BranchSecret::generate(), CredentialIssuance::PROVISION);

        $id = $this->liveId($target['branch_id']);

        // Rewriting an issued fact is refused: this is the append-only
        // guarantee, narrowed just enough to let superseding work.
        $this->assertRefused(
            fn (Connection $db) => $db->table(self::TABLE)->where('id', $id)->update(['fingerprint' => 'sha256:forged']),
            'immutable',
        );

        $this->assertRefused(
            fn (Connection $db) => $db->table(self::TABLE)->where('id', $id)->update(['generation' => 99]),
            'immutable',
        );

        $this->assertRefused(
            fn (Connection $db) => $db->table(self::TABLE)->where('id', $id)->delete(),
            'immutable',
        );

        // Superseding is allowed exactly once — a row cannot come back to life.
        (new CredentialIssuance('test'))->supersede($target['branch_id']);

        $this->assertRefused(
            fn (Connection $db) => $db->table(self::TABLE)->where('id', $id)->update(['superseded_at' => null]),
            'already superseded',
        );
    }

    public function test_an_unknown_reason_is_refused_by_both_layers(): void
    {
        $target = $this->target();

        try {
            (new CredentialIssuance('test'))->record($target, BranchSecret::generate(), 'borrowed');
            $this->fail('An unknown issuance reason was accepted.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('borrowed', $e->getMessage());
        }

        // And the check constraint catches anything that bypasses the writer.
        $this->assertRefused(
            fn (Connection $db) => $db->table(self::TABLE)->insert(
                [...$this->row($target, 1), 'reason' => 'borrowed']
            ),
            'credential_issuance_reason',
        );
    }

    public function test_the_fingerprint_is_deterministic_and_is_not_the_password(): void
    {
        $secret = BranchSecret::generate();
        $target = $this->target();

        (new CredentialIssuance('test'))->record($target, $secret, CredentialIssuance::PROVISION);

        $stored = (string) SchemaConnection::pinConsole()->selectOne(
            'select fingerprint from '.self::TABLE.' where branch_id = ?',
            [$target['branch_id']],
        )->fingerprint;

        // Deterministic: M5 computes this on the device and M10 compares the
        // two. A salt would make that comparison impossible.
        $this->assertSame(BranchSecret::fingerprint($secret), $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertNotSame(BranchSecret::fingerprint(BranchSecret::generate()), $stored);
    }

    /** @param  array<string, string>  $target */
    private function row(array $target, int $generation): array
    {
        return [
            'id' => (string) Str::orderedUuid(),
            'business_id' => $target['business_id'],
            'schema_name' => $target['schema_name'],
            'branch_id' => $target['branch_id'],
            'role_name' => $target['role_name'],
            'fingerprint' => BranchSecret::fingerprint(BranchSecret::generate()),
            'generation' => $generation,
            'reason' => CredentialIssuance::ROTATE,
            'actor' => 'test',
            'issued_at' => now(),
            'superseded_at' => null,
        ];
    }

    /**
     * A target naming a branch that does not exist anywhere. This table has no
     * foreign keys by design — a row must stay readable after its business
     * schema is gone — so nothing needs provisioning to test it.
     *
     * @return array<string, string>
     */
    private function target(): array
    {
        $businessId = (string) Str::uuid();
        $branchId = (string) Str::uuid();

        self::$branchIds[] = $branchId;

        return [
            'business_id' => $businessId,
            'schema_name' => SchemaName::forBusiness($businessId),
            'branch_id' => $branchId,
            'role_name' => 'branch_'.str_replace('-', '', $branchId),
        ];
    }

    private function liveId(string $branchId): string
    {
        return (string) SchemaConnection::pinConsole()->selectOne(
            'select id from '.self::TABLE.' where branch_id = ? and superseded_at is null',
            [$branchId],
        )->id;
    }

    private function rowsWhere(string $branchId, string $where): int
    {
        return (int) SchemaConnection::pinConsole()->selectOne(
            'select count(*) as v from '.self::TABLE." where branch_id = ? and {$where}",
            [$branchId],
        )->v;
    }

    /**
     * Attempt inside a transaction so the failed statement does not poison the
     * session — the pattern SchemaInvariantsTest established.
     */
    private function assertRefused(callable $attempt, string $expected): void
    {
        $connection = SchemaConnection::pinConsole();

        try {
            $connection->transaction(fn () => $attempt($connection));
        } catch (Throwable $e) {
            $this->assertStringContainsStringIgnoringCase(
                $expected,
                $e->getMessage(),
                'Refused, but not for the expected reason: '.$e->getMessage(),
            );

            return;
        }

        $this->fail("An operation the [{$expected}] guard forbids was allowed.");
    }
}
