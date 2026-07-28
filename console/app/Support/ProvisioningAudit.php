<?php

namespace Provisioning\Support;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Writes the console's self-audit entries (§4, §8.6): every provision, migrate,
 * rotate, disable and delete, with who/what/when/target.
 *
 * Rows land in SchemaName::CONSOLE, built by console:migrate. Nothing here
 * creates the table: an audit sink that silently builds itself on first write
 * is a sink that silently vanishes when someone points the console at a
 * different database, so isReady() is a pre-flight the caller must honour.
 */
final class ProvisioningAudit
{
    private const TABLE = 'provisioning_audit';

    /**
     * @param  string|null  $actor  Overrides the derived operator identity;
     *                              the commands pass their --actor option.
     */
    public function __construct(private readonly ?string $actor = null) {}

    /**
     * Is the audit store migrated and writable?
     *
     * Provisioning checks this BEFORE it creates anything, so a missing sink is
     * a clean refusal rather than a half-built business nobody has a record of.
     *
     * Asked read-only. The previous spelling went through
     * SchemaConnection::pinConsole(), which calls ensureExists() — so asking
     * "is the trail there?" CREATED the schema it was asking about, and every
     * caller of this preflight quietly performed DDL, including the ones whose
     * documented contract is that they do not.
     */
    public function isReady(): bool
    {
        try {
            return SchemaConnection::hasTable(SchemaName::CONSOLE, self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    public const SUCCEEDED = 'succeeded';

    public const ROLLED_BACK = 'rolled_back';

    /** The worst outcome: something was created and could not be removed. */
    public const CLEANUP_FAILED = 'cleanup_failed';

    /**
     * Append one entry.
     *
     * @param  array<string, mixed>  $target  business_id / schema_name / branch_id / branch_code / role_name
     * @param  array<string, mixed>  $detail  Action-specific context. NEVER credentials.
     */
    public function record(string $action, string $outcome, array $target = [], array $detail = []): void
    {
        $unknown = array_diff(array_keys($target), [
            'business_id', 'schema_name', 'branch_id', 'branch_code', 'role_name',
        ]);

        if ($unknown !== []) {
            throw new RuntimeException('Unknown audit target keys: '.implode(', ', $unknown));
        }

        SchemaConnection::pinConsole()->table(self::TABLE)->insert([
            'id' => (string) Str::orderedUuid(),
            'action' => $action,
            'outcome' => $outcome,
            'business_id' => $target['business_id'] ?? null,
            'schema_name' => $target['schema_name'] ?? null,
            'branch_id' => $target['branch_id'] ?? null,
            'branch_code' => $target['branch_code'] ?? null,
            'role_name' => $target['role_name'] ?? null,
            'actor' => OperatorIdentity::resolve($this->actor),
            'detail' => $detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
