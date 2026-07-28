<?php

namespace Provisioning\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Records which credential each branch is supposed to be running (M3).
 *
 * The sibling of ProvisioningAudit, answering a different question. The audit
 * trail records EVENTS — this rotation happened, that operator did it. This
 * table records STATE: generation 3 is live, generations 1 and 2 are
 * superseded, and here is a fingerprint you can compare a device against
 * without either side transmitting the secret.
 *
 * Both live in evitan_console and neither is reachable by any branch role.
 *
 * ## Superseding is part of issuing
 *
 * record() closes the previous generation and opens the next one in a single
 * transaction, because a moment in which a branch has two live credentials — or
 * none — is a moment in which the question this table exists to answer has no
 * correct answer. The partial unique index enforces the same thing at the
 * database level, so a caller that tried to do this in two steps would fail
 * rather than corrupt the record.
 */
final class CredentialIssuance
{
    private const TABLE = 'credential_issuance';

    public const PROVISION = 'provision';

    public const ROTATE = 'rotate';

    public const RECREATE = 'recreate';

    public function __construct(private readonly ?string $actor = null) {}

    /**
     * Is the issuance table migrated?
     *
     * Checked in every preflight alongside ProvisioningAudit::isReady(). A
     * console that predates this table would otherwise issue credentials that
     * silently go unrecorded — and unlike the audit trail, this record cannot
     * be reconstructed afterwards, because the secret is gone.
     *
     * Read-only, for the reason spelled out on ProvisioningAudit::isReady():
     * pinConsole() creates the schema, so asking used to be a write.
     */
    public function isReady(): bool
    {
        try {
            return SchemaConnection::hasTable(SchemaName::CONSOLE, self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Close the branch's live generation and open the next one.
     *
     * @param  array<string, mixed>  $target  business_id, schema_name, branch_id, role_name
     * @return int The generation number issued.
     */
    public function record(array $target, string $password, string $reason): int
    {
        $this->assertReason($reason);

        $connection = SchemaConnection::pinConsole();

        return $connection->transaction(function (Connection $db) use ($target, $password, $reason): int {
            $branchId = (string) $target['branch_id'];

            // From the HIGHEST generation ever issued, not from the live one.
            // After branch:revoke there is no live row at all, and counting from
            // that would restart at 1 — colliding with the row already there and,
            // worse, making "generation 1" mean two different credentials.
            $generation = $this->highest($db, $branchId) + 1;

            $live = $this->live($db, $branchId);

            if ($live !== null) {
                $this->close($db, (string) $live->id);
            }

            $db->table(self::TABLE)->insert([
                'id' => (string) Str::orderedUuid(),
                'business_id' => $target['business_id'],
                'schema_name' => $target['schema_name'],
                'branch_id' => $target['branch_id'],
                'role_name' => $target['role_name'],
                // The password itself never reaches this table, or any other.
                'fingerprint' => BranchSecret::fingerprint($password),
                'generation' => $generation,
                'reason' => $reason,
                'actor' => OperatorIdentity::resolve($this->actor),
                'issued_at' => now(),
                'superseded_at' => null,
            ]);

            return $generation;
        });
    }

    /**
     * Mark a branch's live credential as no longer current without issuing a
     * replacement — what branch:revoke leaves behind.
     *
     * Returns the generation closed, or null when there was nothing live. A
     * branch with no live row is the honest representation of a revoked role:
     * there IS no credential it should be running.
     */
    public function supersede(string $branchId): ?int
    {
        $connection = SchemaConnection::pinConsole();

        return $connection->transaction(function (Connection $db) use ($branchId): ?int {
            $live = $this->live($db, $branchId);

            if ($live === null) {
                return null;
            }

            $this->close($db, (string) $live->id);

            return (int) $live->generation;
        });
    }

    /** The branch's current credential, or null when it has none. */
    public function current(string $branchId): ?stdClass
    {
        return $this->live(SchemaConnection::pinConsole(), $branchId);
    }

    /**
     * How many credentials this branch has ever been issued — the generation
     * counter, readable even when nothing is live.
     */
    public function generations(string $branchId): int
    {
        return $this->highest(SchemaConnection::pinConsole(), $branchId);
    }

    /** The highest generation ever issued to this branch; 0 when never issued. */
    private function highest(Connection $db, string $branchId): int
    {
        $row = $db->selectOne(
            'select max(generation) as latest from '.self::TABLE.' where branch_id = ?',
            [$branchId],
        );

        return (int) ($row->latest ?? 0);
    }

    private function live(Connection $db, string $branchId): ?stdClass
    {
        return $db->selectOne(
            'select id, generation, fingerprint, role_name, issued_at from '.self::TABLE
            .' where branch_id = ? and superseded_at is null',
            [$branchId],
        );
    }

    /**
     * The trigger allows exactly this: superseded_at moving from null to a
     * value, once. Anything else on this table is rejected by the database.
     */
    private function close(Connection $db, string $id): void
    {
        $db->table(self::TABLE)->where('id', $id)->update([
            'superseded_at' => now(),
        ]);
    }

    private function assertReason(string $reason): void
    {
        if (! in_array($reason, [self::PROVISION, self::ROTATE, self::RECREATE], true)) {
            throw new RuntimeException("Unknown issuance reason [{$reason}].");
        }
    }
}
