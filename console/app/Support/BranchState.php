<?php

namespace Provisioning\Support;

use stdClass;

/**
 * What the console knows about one branch's credential, in one object.
 *
 * The facts live in three places that no single query joins: the business's own
 * `branches` row, the cluster's `pg_roles` (does the role exist, may it log in),
 * and `evitan_console.credential_issuance` (which generation is live). Assembling
 * them is the read behind both `branch:list` and the menu's branch picker.
 *
 * It exists as a class rather than as a private method on either caller because
 * "active", "disabled" and "no role" are VOCABULARY (docs/glossary.md §7), not
 * formatting. Two copies of those three words is exactly the drift the glossary
 * was written against — a branch reported as "disabled" in one screen and
 * "revoked" in another describes two different recovery procedures.
 *
 * The labels here carry no colour. Callers decorate: `branch:list` wraps them in
 * Symfony tags for its table, and the menu leaves them plain because
 * Laravel Prompts renders select() labels without a formatter.
 */
final class BranchState
{
    private function __construct(
        public readonly stdClass $branch,
        public readonly string $role,
        /** Null when the role does not exist at all. */
        public readonly ?bool $canLogin,
        /** One of: active, disabled, no role. */
        public readonly string $credential,
        /** The live generation, or why there isn't one. */
        public readonly string $generation,
    ) {}

    /**
     * Every branch of a business, oldest first, each with its credential state.
     *
     * @return list<self>
     */
    public static function forSchema(string $schema, CredentialIssuance $issuance): array
    {
        $branches = BranchDirectory::all($schema);

        if ($branches === []) {
            return [];
        }

        // Read once: the answer is the same for every branch, and asking per
        // branch would pin the console schema N times mid-listing.
        $recordsReady = $issuance->isReady();

        return array_map(
            fn (stdClass $branch): self => self::for($branch, $issuance, $recordsReady),
            $branches,
        );
    }

    public static function for(stdClass $branch, CredentialIssuance $issuance, bool $recordsReady): self
    {
        $role = RoleName::forBranch((string) $branch->id);
        $canLogin = BranchRole::canLogin($role);

        return new self(
            branch: $branch,
            role: $role,
            canLogin: $canLogin,
            credential: self::credentialLabel($canLogin),
            generation: $recordsReady ? self::generationLabel($issuance, (string) $branch->id) : '—',
        );
    }

    public function code(): string
    {
        return (string) $this->branch->code;
    }

    public function name(): string
    {
        return (string) $this->branch->name;
    }

    public function id(): string
    {
        return (string) $this->branch->id;
    }

    /** Three states, not two: "no role" is not the same fact as "disabled". */
    private static function credentialLabel(?bool $canLogin): string
    {
        return match ($canLogin) {
            null => 'no role',
            false => 'disabled',
            true => 'active',
        };
    }

    /**
     * The live generation, or the highest ever issued when nothing is live —
     * "3 (revoked)" says more than a blank, because it distinguishes a branch
     * whose credential was withdrawn from one that never had one.
     */
    private static function generationLabel(CredentialIssuance $issuance, string $branchId): string
    {
        $current = $issuance->current($branchId);

        if ($current !== null) {
            return (string) $current->generation;
        }

        $issued = $issuance->generations($branchId);

        return $issued === 0 ? 'never issued' : $issued.' (revoked)';
    }
}
