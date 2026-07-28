<?php

namespace Provisioning\Console\Commands;

use InvalidArgumentException;
use Provisioning\Support\BranchRole;
use Provisioning\Support\ProvisioningAudit;
use RuntimeException;
use Throwable;

/**
 * Restore a branch role's LOGIN (§8.3), the inverse of branch:disable.
 *
 * One ALTER ROLE, and nothing else — the grants were never removed, so the
 * branch comes back with exactly the privileges it had rather than a freshly
 * derived set that might differ. Its outbox flushes on the first successful
 * cycle, and because every push is a UUID-keyed upsert (§11.4), however long it
 * was disabled cannot produce duplicates on the way back in.
 *
 * No confirmation prompt: this is the recovery direction. It restores a state
 * that already existed and cannot destroy anything.
 *
 * It does NOT help a branch whose password was rotated — that credential is
 * dead regardless of LOGIN, and only a new JSON fixes it.
 */
class BranchEnableCommand extends BranchCommand
{
    protected $signature = 'branch:enable
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--branch= : Branch UUID}
        {--branch-code= : Branch code, e.g. MNL}
        {--actor= : Recorded as the operator in the provisioning audit}';

    protected $description = "Restore a branch role's LOGIN so it can sync again";

    public function handle(): int
    {
        try {
            $schema = $this->targetSchema();

            if (($problem = $this->schemaProblem($schema)) !== null) {
                return $this->refuse([$problem]);
            }

            $branch = $this->targetBranch($schema);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $role = $this->roleFor($branch);
        $audit = $this->audit();

        if (! $audit->isReady()) {
            return $this->refuse([
                'The provisioning audit store is missing — run: php console/artisan console:migrate',
            ]);
        }

        $canLogin = BranchRole::canLogin($role);

        if ($canLogin === null) {
            return $this->refuse([
                "Role [{$role}] does not exist, so enabling it is not possible. "
                .'Re-create it with: branch:rotate --recreate (it issues a new JSON).',
            ]);
        }

        if ($canLogin === true) {
            $this->components->info("Branch [{$branch->code}] is already enabled.");

            return self::SUCCESS;
        }

        $target = $this->auditTarget($schema, $branch, $role);

        try {
            BranchRole::setLogin($role, true);
        } catch (Throwable $e) {
            $this->recordQuietly($audit, 'branch.enable', ProvisioningAudit::ROLLED_BACK, $target, [
                'error' => $e->getMessage(),
            ]);

            $this->components->error('Could not enable the role: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->recordQuietly($audit, 'branch.enable', ProvisioningAudit::SUCCEEDED, $target);

        $this->newLine();
        $this->components->info('Branch enabled');
        $this->components->twoColumnDetail('Branch', $branch->name.' ['.$branch->code.']');
        $this->components->twoColumnDetail('Postgres role', $role);
        $this->components->twoColumnDetail('LOGIN', '<fg=green>granted</>');

        $this->newLine();
        $this->components->bulletList([
            'The branch syncs on its next cycle and flushes whatever queued while it was disabled.',
            'If its password was also rotated, it still needs the new JSON — LOGIN alone is not enough.',
        ]);

        return self::SUCCESS;
    }
}
