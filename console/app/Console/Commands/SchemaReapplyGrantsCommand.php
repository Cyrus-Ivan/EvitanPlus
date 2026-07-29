<?php

namespace Provisioning\Console\Commands;

use InvalidArgumentException;
use Provisioning\Support\BranchRole;
use Provisioning\Support\BranchState;
use Provisioning\Support\BusinessDirectory;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\SchemaName;
use RuntimeException;
use Throwable;

/**
 * Re-apply the branch privilege matrix to every branch role in a schema.
 *
 * ## Why this has to exist
 *
 * Grants are enumerated per table on purpose (see BranchGrants): no
 * `ON ALL TABLES`, no `ALTER DEFAULT PRIVILEGES`, because both would silently
 * extend a leaked credential's reach to any table a future migration adds. The
 * cost of that choice is that the matrix has to be re-applied by hand whenever
 * it changes or a migration adds a table — and `BranchRole::reapplyGrants()`
 * has existed since M2 with **nothing calling it**.
 *
 * Until now the only in-console way to give an existing branch a privilege it
 * did not have was `branch:rotate`, which changes the password and therefore
 * obliges a fresh AppImage and a new credentials JSON to every till — an
 * enormous price for a GRANT. M6.5 forced the issue by widening the matrix for
 * every already-provisioned business, and M9's migrate-on-update needs exactly
 * this call too.
 *
 * ## What it is not
 *
 * It is not a migration and it changes no data. `GRANT` is idempotent, so
 * running this twice is running it once; running it on a schema that is already
 * current is a no-op that says so. It cannot remove a privilege the matrix
 * dropped — `applyGrants` revokes first, so it can, and BranchRoleGrantsTest
 * proves live grants equal the matrix exactly afterwards.
 *
 * ## One audit row per role
 *
 * Not one per schema. Each role is granted in its own transaction, so a failure
 * on the third role leaves the first two current and the rest not — a partial
 * state that a single summary row could not describe. Per-role rows say exactly
 * which credentials carry the new matrix, which is the question an operator has
 * at the moment they need this command.
 */
class SchemaReapplyGrantsCommand extends BranchCommand
{
    protected $signature = 'schema:reapply-grants
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--all : Every business schema in the project}
        {--actor= : Recorded as the operator in the provisioning audit}';

    protected $description = 'Re-apply the branch privilege matrix to every branch role in a schema';

    public function handle(): int
    {
        $audit = $this->audit();

        if (! $audit->isReady()) {
            return $this->refuse([
                'The provisioning audit store is missing — run: php console/artisan console:migrate',
            ]);
        }

        try {
            $schemas = $this->targetSchemas();
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        if ($schemas === []) {
            $this->components->info('No business schemas found — nothing to re-grant.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($schemas as $schema) {
            $failed += $this->reapply($schema, $audit);
        }

        $this->newLine();

        if ($failed > 0) {
            $this->components->error("{$failed} role(s) could not be re-granted. Re-running is safe.");

            return self::FAILURE;
        }

        $this->components->info('Grants are current.');

        $this->newLine();
        $this->components->bulletList([
            'No password changed, so no branch needs a new credentials JSON.',
            'Branch roles pick this up on their next connection — nothing to restart.',
        ]);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function targetSchemas(): array
    {
        if ((bool) $this->option('all')) {
            if ($this->stringOption('business') !== null || $this->stringOption('schema') !== null) {
                throw new InvalidArgumentException('--all cannot be combined with --business or --schema.');
            }

            return array_values(array_map(
                fn (array $business): string => $business['schema'],
                array_filter(BusinessDirectory::all(), fn (array $business): bool => $business['provisioned']),
            ));
        }

        $schema = $this->targetSchema();

        if (($problem = $this->schemaProblem($schema)) !== null) {
            throw new RuntimeException($problem);
        }

        return [$schema];
    }

    /** @return int roles that failed */
    private function reapply(string $schema, ProvisioningAudit $audit): int
    {
        $schema = SchemaName::validate($schema);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Schema</>', $schema);

        try {
            $states = BranchState::forSchema($schema, $this->issuance());
        } catch (Throwable $e) {
            $this->components->error("Could not read [{$schema}]: ".$e->getMessage());

            return 1;
        }

        if ($states === []) {
            $this->components->twoColumnDetail('Branches', '<fg=gray>none</>');

            return 0;
        }

        $failed = 0;

        foreach ($states as $state) {
            $label = $state->branch->name.' ['.$state->code().']';
            $target = $this->auditTarget($schema, $state->branch, $state->role);

            // A branch whose role was revoked has nothing to grant to. Skipping
            // is right and saying so matters: the operator is here precisely
            // because they want every till current, and a silent omission is
            // the one outcome that would mislead them.
            if ($state->canLogin === null) {
                $this->components->twoColumnDetail($label, '<fg=yellow>skipped — no role</>');

                $this->recordQuietly($audit, 'branch.reapply_grants', ProvisioningAudit::ROLLED_BACK, $target, [
                    'skipped' => 'the role does not exist',
                ]);

                continue;
            }

            try {
                BranchRole::reapplyGrants($state->role, $schema);
            } catch (Throwable $e) {
                $failed++;

                $this->components->twoColumnDetail($label, '<fg=red>failed</>');
                $this->components->error($e->getMessage());

                $this->recordQuietly($audit, 'branch.reapply_grants', ProvisioningAudit::ROLLED_BACK, $target, [
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Disabled roles are granted too, deliberately: LOGIN and privileges
            // are independent, and a branch re-enabled later must come back with
            // the current matrix rather than a stale one.
            $this->components->twoColumnDetail(
                $label,
                $state->canLogin ? '<fg=green>granted</>' : '<fg=green>granted</> <fg=gray>(login disabled)</>',
            );

            $this->recordQuietly($audit, 'branch.reapply_grants', ProvisioningAudit::SUCCEEDED, $target, [
                'can_login' => $state->canLogin,
            ]);
        }

        return $failed;
    }
}
