<?php

namespace Provisioning\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Issues a branch credential into a business that already exists (§8.2, §8.3).
 *
 * Three operations share this class because they are one act performed under
 * three different starting conditions — mint a secret, make the role hold it,
 * emit a JSON that proves it works:
 *
 *   PROVISION  a new branches row and a new role
 *   ROTATE     an existing role gets a new password
 *   RECREATE   a role that was revoked comes back, fully granted
 *
 * ## The one rule that separates this from BusinessProvisioner
 *
 * **The schema is never in the undo stack.** BusinessProvisioner can safely
 * compensate with `DROP SCHEMA ... CASCADE` because it is create-only: it made
 * the schema in the same run, so dropping it destroys nothing that predated it.
 * Everything here runs against a schema that already holds other branches' sales
 * history. Undo is therefore scoped to exactly the objects this run created —
 * one branches row keyed by a UUID minted seconds earlier, one role, one file —
 * and BranchRole::dropRoleSql (not cleanupSql) is what a failed cleanup prints,
 * because cleanupSql starts with a DROP SCHEMA that would be catastrophic here.
 *
 * ## Rotation is a one-way door, and the ordering is the mitigation
 *
 * A rotated password cannot be rolled back: the previous secret was written to
 * one credentials file and deliberately never stored (§9). So the credentials
 * file is reserved BEFORE the ALTER ROLE, which leaves only a write to an
 * already-open, already-created 0600 handle in the unprotected window. If even
 * that fails, the operator is told plainly that the password HAS changed and
 * that re-running the rotation is safe — that is a recoverable state, and
 * pretending the rotation did not happen would not be.
 *
 * ## Why a re-issue never rolls back on a failed VERIFICATION
 *
 * Verification is the last step, and by the time it runs on a re-issue the role
 * already holds a new secret that exists in exactly one place: the credentials
 * file this run reserved. Rolling back discards that file — so a branch whose
 * password was successfully changed would be left with no way to learn the new
 * one. Permanently. That is a strictly worse outcome than shipping a credential
 * the console could not prove, especially since the usual cause is Supabase's
 * pooler still serving the previous role (see PoolerLag) rather than anything
 * wrong with the credential.
 *
 * So a re-issue that cannot verify keeps everything and reports
 * `verified => false`, loudly and into the audit detail. A PROVISION still
 * rolls back in full: it created the branch, the role and the file in this run,
 * nothing existed before them, and undoing all three destroys nothing.
 */
final class BranchIssuer
{
    public const PROVISION = 'branch.provision';

    public const ROTATE = 'branch.rotate';

    public const RECREATE = 'branch.recreate';

    /**
     * How long a re-created role's verification may keep retrying.
     *
     * Sized from measurement, not guesswork: Supavisor held a dropped role's
     * OID for ~126s in testing (see PoolerLagException). 180s leaves headroom
     * without letting a genuinely broken credential hang the command for long.
     */
    public const RECREATE_VERIFY_SECONDS = 180;

    /** Undo label for the credentials file, matched by name during rollback. */
    private const CREDENTIALS_UNDO = 'credentials file';

    /** Seconds between verification attempts; see verifyWithBudget. */
    private const VERIFY_RETRY_SECONDS = 15;

    /** @var list<array{label: string, undo: Closure}> */
    private array $undo = [];

    private BranchCredentials $credentials;

    /**
     * True once a step has run that cannot be compensated — today, exactly one:
     * replacing a live role's password.
     */
    private bool $irreversible = false;

    /** Seconds verification may keep retrying; 0 means one attempt. */
    private int $verifyBudget = 0;

    /**
     * Whether a failed verification keeps what was made instead of rolling back.
     *
     * True for every re-issue, and the reason is not tolerance of flakiness —
     * it is that rolling back would DESTROY THE ONLY COPY OF A LIVE SECRET.
     * See the class docblock.
     */
    private bool $keepOnVerifyFailure = false;

    /** The verification error, when one was survived rather than fatal. */
    private ?string $verifyError = null;

    public function __construct(
        private readonly ProvisioningAudit $audit,
        private readonly CredentialIssuance $issuance,
    ) {
        $this->credentials = new BranchCredentials;
    }

    /**
     * Everything that must be true before anything changes.
     *
     * Separate from issue() so a command can refuse with zero side effects,
     * including before it has prompted for anything.
     *
     * @return list<string> Human-readable reasons to refuse; empty means go.
     */
    public function preflight(string $schema): array
    {
        $problems = [];

        if (! $this->audit->isReady()) {
            $problems[] = 'The provisioning audit store is missing — run: php console/artisan console:migrate';
        }

        if (! $this->issuance->isReady()) {
            $problems[] = 'The credential issuance record is missing — run: php console/artisan console:migrate';
        }

        if (! SchemaConnection::exists($schema)) {
            $problems[] = "Schema [{$schema}] does not exist. Create the business first with: "
                .'php console/artisan business:provision';

            // Every check below reads the schema; stop rather than report a
            // cascade of failures that all have the same single cause.
            return $problems;
        }

        if (! BranchDirectory::isProvisionedBusiness($schema)) {
            $problems[] = "Schema [{$schema}] exists but is not a provisioned business "
                .'(no schema_meta or no branches row). A branch added to a half-built schema '
                .'would hydrate from a catalog that is not there.';
        }

        return $problems;
    }

    /**
     * Create a branch and its credential (§8.2).
     *
     * @param  array<string, mixed>  $data  branch_name, branch_code, branch_address, branch_id, out
     * @param  Closure(string): void  $step
     * @return array<string, mixed> The issuance summary.
     */
    public function provision(string $schema, array $data, Closure $step): array
    {
        $branchId = (string) ($data['branch_id'] ?? Str::orderedUuid());
        $role = RoleName::forBranch($branchId);
        $code = BranchCode::validate((string) $data['branch_code']);

        $target = $this->target($schema, $branchId, $code, $role);

        return $this->run($target, $data, $step, self::PROVISION, function (Closure $step) use (
            $schema, $data, $branchId, $code, $role, $target
        ): array {
            $step("Adding branch [{$code}] to {$schema}");
            $this->insertBranch($schema, $data, $branchId, $code);
            $this->pushUndo("branches row {$code}", fn () => $this->deleteBranch($schema, $branchId));

            $password = BranchSecret::generate();

            $step("Creating least-privilege role [{$role}]");
            BranchRole::create($role, $password, $schema);
            $this->pushUndo("role {$role}", fn () => BranchRole::drop($role, $schema));

            return [
                'password' => $password,
                'reason' => CredentialIssuance::PROVISION,
                'branch' => [
                    'id' => $branchId,
                    'code' => $code,
                    'name' => (string) $data['branch_name'],
                    'address' => ($data['branch_address'] ?? '') !== '' ? (string) $data['branch_address'] : null,
                ],
                'target' => $target,
            ];
        });
    }

    /**
     * Re-issue an existing branch's credential (§8.3).
     *
     * @param  object{id: string, code: string, name: string, address: ?string}  $branch
     * @param  array<string, mixed>  $data  out
     * @param  Closure(string): void  $step
     * @return array<string, mixed>
     */
    public function reissue(string $schema, object $branch, array $data, bool $recreate, Closure $step): array
    {
        $branchId = (string) $branch->id;
        $code = (string) $branch->code;
        $role = RoleName::forBranch($branchId);

        $target = $this->target($schema, $branchId, $code, $role);
        $action = $recreate ? self::RECREATE : self::ROTATE;

        // Both paths, not just re-creation. Rotation was measured settling
        // instantly in isolation, but a role with warm pooled connections can
        // lag there too — and on that path a rollback would delete the only
        // copy of a password that has already changed.
        $this->verifyBudget = (int) ($data['verify_wait'] ?? self::RECREATE_VERIFY_SECONDS);
        $this->keepOnVerifyFailure = true;

        return $this->run($target, $data, $step, $action, function (Closure $step) use (
            $schema, $branch, $branchId, $code, $role, $recreate, $target
        ): array {
            $password = BranchSecret::generate();

            if ($recreate) {
                $step("Re-creating role [{$role}] with the current grant matrix");
                BranchRole::create($role, $password, $schema);
                $this->pushUndo("role {$role}", fn () => BranchRole::drop($role, $schema));
            } else {
                $step("Setting a new password on [{$role}]");
                BranchRole::setPassword($role, $password);

                // From here the previous secret is unrecoverable. Nothing after
                // this point may claim the rotation can be undone.
                $this->irreversible = true;

                // Idempotent, and it re-aligns a role whose schema gained tables
                // since the role was created: grants are enumerated per table
                // (BranchGrants), so a migration that adds one leaves existing
                // roles without privileges on it until this runs (§7 As built).
                $step('Re-applying the grant matrix');
                BranchRole::reapplyGrants($role, $schema);
            }

            return [
                'password' => $password,
                'reason' => $recreate ? CredentialIssuance::RECREATE : CredentialIssuance::ROTATE,
                'branch' => [
                    'id' => $branchId,
                    'code' => $code,
                    'name' => (string) $branch->name,
                    'address' => $branch->address !== null ? (string) $branch->address : null,
                ],
                'target' => $target,
            ];
        });
    }

    /**
     * The shared spine: reserve the file, do the variable part, then verify,
     * write and record — identically for all three operations.
     *
     * @param  array<string, string>  $target
     * @param  array<string, mixed>  $data
     * @param  Closure(string): void  $step
     * @param  Closure(Closure): array<string, mixed>  $act
     * @return array<string, mixed>
     */
    private function run(array $target, array $data, Closure $step, string $action, Closure $act): array
    {
        $schema = $target['schema_name'];
        $role = $target['role_name'];

        $endpoint = SupabaseEndpoint::fromConsoleConfig();
        $businessId = SchemaName::toBusinessUuid($schema);
        $businessName = BranchDirectory::businessName($schema);

        // Before anything changes: a credential that cannot be written to a
        // file is a credential nobody can use, and for a rotation it is one
        // nobody can recover either.
        $step('Reserving credentials file');
        $path = $this->credentials->reserve(
            $this->credentialsPath($data, $schema, $target['branch_code'])
        );
        $this->pushUndo('credentials file', fn () => $this->credentials->discard());

        try {
            $result = $act($step);

            $step('Reading the schema version gate');
            $versions = $this->readVersions($schema);

            $step('Verifying the branch role can connect and read');
            $verified = $this->verifyWithBudget(
                $endpoint->loginName($role),
                $result['password'],
                $schema,
                $target['branch_code'],
                $step,
            );

            $step('Writing the credentials JSON');
            $this->credentials->write(BranchCredentials::document(
                business: [
                    'id' => $businessId,
                    'name' => $businessName,
                    'schema' => $schema,
                ],
                branch: $result['branch'],
                endpoint: $endpoint,
                role: $role,
                password: $result['password'],
                versions: $versions,
            ));

            // Last, and inside the guarded block on purpose: if the record of
            // what was issued cannot be written, the issuance is rolled back
            // rather than left unrecorded. Unlike the audit trail, this one
            // cannot be reconstructed later — the secret is already gone.
            $step('Recording the credential issuance');
            $generation = $this->issuance->record($target, $result['password'], $result['reason']);
        } catch (Throwable $original) {
            throw $this->rollBack($original, $target, $action);
        }

        $this->recordQuietly($action, $target, [
            'schema_version' => $versions['schema_version'],
            'min_app_version' => $versions['min_app_version'],
            'credentials_path' => $path,
            'generation' => $generation,
            'verified' => $verified,
            'verify_error' => $this->verifyError,
        ], $step);

        return [
            'verified' => $verified,
            'verify_error' => $this->verifyError,
            'business_id' => $businessId,
            'business_name' => $businessName,
            'schema' => $schema,
            'branch_id' => $target['branch_id'],
            'branch_code' => $target['branch_code'],
            'branch_name' => $result['branch']['name'],
            'role' => $role,
            'login_name' => $endpoint->loginName($role),
            'schema_version' => $versions['schema_version'],
            'min_app_version' => $versions['min_app_version'],
            'credentials_path' => $path,
            'generation' => $generation,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertBranch(string $schema, array $data, string $branchId, string $code): void
    {
        SchemaConnection::pin($schema)->transaction(function (Connection $db) use ($data, $branchId, $code): void {
            // The preflight said this code was free; if it is not now, something
            // claimed it in between. branches_code_unique would reject the
            // insert anyway — this only makes the reason legible.
            if ($db->table('branches')->where('code', $code)->exists()) {
                throw new RuntimeException("Branch code [{$code}] is already in use in this business.");
            }

            $now = now();

            $db->table('branches')->insert([
                'id' => $branchId,
                'code' => $code,
                'name' => $data['branch_name'],
                'address' => ($data['branch_address'] ?? '') !== '' ? $data['branch_address'] : null,
                // Not disabled: §8.3's disable is a Postgres LOGIN concern, and
                // this column is branch-closure business state.
                'disabled_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Remove the row this run inserted — the only DELETE anywhere in the console
     * that touches a business schema.
     *
     * Safe on both counts that matter: `branches` carries no immutability
     * trigger (unlike the seven history tables), and the WHERE is a UUID minted
     * by this process seconds ago, so it cannot match a row that predates the
     * run. A branch that has ever sold anything is never reachable from here.
     */
    private function deleteBranch(string $schema, string $branchId): void
    {
        SchemaConnection::pin($schema)->table('branches')->where('id', $branchId)->delete();
    }

    /**
     * @return array{schema_version: int, min_app_version: string}
     */
    private function readVersions(string $schema): array
    {
        $meta = SchemaConnection::pin($schema)
            ->selectOne('select schema_version, min_app_version from schema_meta');

        if ($meta === null) {
            throw new RuntimeException("[{$schema}] has no schema_meta row; the schema is not fully migrated.");
        }

        return [
            'schema_version' => (int) $meta->schema_version,
            'min_app_version' => (string) $meta->min_app_version,
        ];
    }

    /**
     * Verify, retrying while the pooler catches up.
     *
     * Returns true when the credential was proven to work, and false when a
     * re-issue could not prove it. On a PROVISION the failure is rethrown and
     * rolls the run back — nothing existed before it, so undoing costs nothing
     * and M2's guarantee that an unusable JSON is never shipped holds exactly
     * where it can be enforced.
     *
     * On a re-issue it never rethrows, because the role already holds a secret
     * that exists only in the file this run reserved (see the class docblock).
     * Retrying first is worth it: the usual cause is the pooler still serving
     * the previous role, and waiting turns most of these into a true.
     *
     * False is not silence — it travels into the audit detail and the report.
     *
     * @param  Closure(string): void  $step
     */
    private function verifyWithBudget(
        string $loginName,
        string $password,
        string $schema,
        string $code,
        Closure $step,
    ): bool {
        $deadline = microtime(true) + $this->verifyBudget;
        $announced = false;

        while (true) {
            try {
                $this->verify($loginName, $password, $schema, $code);

                return true;
            } catch (Throwable $e) {
                if (! $this->keepOnVerifyFailure) {
                    throw $e;
                }

                if (microtime(true) >= $deadline) {
                    $this->verifyError = $e->getMessage();

                    return false;
                }

                if (! $announced) {
                    $step(PoolerLag::matches($e)
                        ? 'The pooler is still serving the previous role — waiting for it to expire'
                        : 'Verification failed; retrying while the change propagates');
                    $announced = true;
                }

                // Unhurried on purpose. Each attempt consumes a pooler session
                // that Supabase frees lazily, and the thing being waited for
                // takes ~2 minutes anyway, so a tight retry buys nothing and
                // spends a connection budget that is only 15 wide.
                sleep(self::VERIFY_RETRY_SECONDS);
            }
        }
    }

    /**
     * Prove the emitted credentials work before claiming success — the
     * milestone is a JSON a client can actually connect with.
     *
     * search_path is left unset so this also proves the server-side ALTER ROLE
     * pin. The branch is read back by ITS OWN code rather than by any row in the
     * table: in a multi-branch schema `select code from branches` would happily
     * return a sibling's, and the check would pass while proving nothing.
     */
    private function verify(string $loginName, string $password, string $schema, string $code): void
    {
        try {
            $branch = BranchConnection::open($loginName, $password);

            $actualSchema = (string) $branch->selectOne('select current_schema() as name')->name;

            if ($actualSchema !== $schema) {
                throw new RuntimeException(
                    "The branch role lands in schema [{$actualSchema}], not [{$schema}] — search_path is not pinned."
                );
            }

            $row = $branch->selectOne('select code from branches where code = ?', [$code]);

            if ($row === null) {
                throw new RuntimeException("The branch role cannot read branch [{$code}].");
            }
        } finally {
            // Always: an open branch session blocks DROP ROLE, so leaving it
            // open would break the cleanup path.
            BranchConnection::close();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function credentialsPath(array $data, string $schema, string $code): string
    {
        $out = $data['out'] ?? null;

        if (is_string($out) && trim($out) !== '') {
            return trim($out);
        }

        return base_path(BranchCredentials::DEFAULT_DIRECTORY)
            .DIRECTORY_SEPARATOR
            .BranchCredentials::filename($schema, $code);
    }

    /** @param  Closure(): void  $undo */
    private function pushUndo(string $label, Closure $undo): void
    {
        $this->undo[] = ['label' => $label, 'undo' => $undo];
    }

    /**
     * Unwind in reverse, then report the ORIGINAL failure.
     *
     * @param  array<string, string>  $target
     */
    private function rollBack(Throwable $original, array $target, string $action): RuntimeException
    {
        BranchConnection::close();

        $failures = [];

        foreach (array_reverse($this->undo) as ['label' => $label, 'undo' => $undo]) {
            // Once a live role's password has been replaced, the credentials
            // file is the ONLY copy of the new secret. Discarding it here would
            // strand the branch permanently — far worse than leaving a file
            // behind for a run that failed. The role itself is still undone
            // where it can be (a re-creation), which returns the branch to a
            // clean no-credential state rather than a stranded one.
            if ($this->irreversible && $label === self::CREDENTIALS_UNDO) {
                continue;
            }

            try {
                $undo();
            } catch (Throwable $e) {
                $failures[] = $label.': '.$e->getMessage();
            }
        }

        $this->undo = [];

        // A rotated password is not a cleanup failure in the usual sense —
        // nothing was left behind — but it IS a change that survived a failed
        // command, and cleanup_failed is the outcome that means exactly that.
        $outcome = ($failures === [] && ! $this->irreversible)
            ? ProvisioningAudit::ROLLED_BACK
            : ProvisioningAudit::CLEANUP_FAILED;

        try {
            $this->audit->record($action, $outcome, $target, [
                'error' => $original->getMessage(),
                'cleanup_failures' => $failures,
                'password_changed' => $this->irreversible,
            ]);
        } catch (Throwable) {
            // The operation already failed; an unreachable audit store must not
            // become the error the operator sees instead.
        }

        $message = 'Issuing the branch credential failed: '.$original->getMessage();

        if ($this->irreversible) {
            $message .= PHP_EOL.PHP_EOL
                .'IMPORTANT: the role\'s password HAS been changed and the previous one is gone — '
                .'that is by design, the console never stores it. The branch cannot sync until it '
                .'receives a working JSON. Re-run the rotation to issue one; doing so is safe and '
                .'the till keeps selling offline meanwhile.';
        }

        if ($failures !== []) {
            $message .= PHP_EOL.PHP_EOL.'Cleanup did not fully succeed, so some objects remain:'
                .PHP_EOL.'  - '.implode(PHP_EOL.'  - ', $failures)
                .PHP_EOL.PHP_EOL.'Remove them by hand with:'.PHP_EOL.'  '
                // dropRoleSql, NOT cleanupSql: this schema holds other branches'
                // history and must never be dropped.
                .implode(PHP_EOL.'  ', BranchRole::dropRoleSql($target['role_name'], $target['schema_name']));
        } elseif (! $this->irreversible) {
            $message .= PHP_EOL.'Nothing was left behind.';
        }

        return new RuntimeException($message, 0, $original);
    }

    /**
     * @param  array<string, string>  $target
     * @param  array<string, mixed>  $detail
     * @param  Closure(string): void  $step
     */
    private function recordQuietly(string $action, array $target, array $detail, Closure $step): void
    {
        try {
            $this->audit->record($action, ProvisioningAudit::SUCCEEDED, $target, $detail);
        } catch (Throwable $e) {
            $step('WARNING: the operation succeeded but its audit entry failed: '.$e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function target(string $schema, string $branchId, string $code, string $role): array
    {
        return [
            'business_id' => SchemaName::toBusinessUuid($schema),
            'schema_name' => $schema,
            'branch_id' => $branchId,
            'branch_code' => $code,
            'role_name' => $role,
        ];
    }
}
