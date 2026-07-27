<?php

namespace Provisioning\Console\Commands;

use InvalidArgumentException;
use Provisioning\Support\BranchConnection;
use Provisioning\Support\BranchRole;
use Provisioning\Support\ProvisioningAudit;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\table;

/**
 * Delete a branch's Postgres role (§8.3 "delete a branch role").
 *
 * ## Why this is not called branch:delete
 *
 * Because it does not delete a branch. The `branches` row, its sales,
 * its ledger and its audit history are all untouched and stay readable by every
 * other branch of the business — deleting business records is forbidden outright
 * (CLAUDE.md), and this command could not do it even if asked. What goes away is
 * one Postgres login role. `branch:delete` invites exactly the wrong reading at
 * exactly the wrong hour, and a confirmation prompt is a weak defence against a
 * name that is already misleading.
 *
 * ## Why anyone would run it
 *
 * A branch that is closing, a device that is gone, or a credential that must not
 * merely be disabled but must cease to exist. For a temporary stop, branch:disable
 * is the better tool — it is reversible in one statement and preserves the grants.
 *
 * The way back is branch:rotate --recreate, which rebuilds the role with the
 * current grant matrix and issues a new JSON. There is no way to restore the old
 * password: it was never stored (§9).
 */
class BranchRevokeCommand extends BranchCommand
{
    protected $signature = 'branch:revoke
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--branch= : Branch UUID}
        {--branch-code= : Branch code, e.g. MNL}
        {--actor= : Recorded as the operator in the provisioning audit}
        {--force : Skip the confirmation prompt}';

    protected $description = "Delete a branch's Postgres role. The branch and all its history stay";

    public function handle(): int
    {
        try {
            $schema = $this->targetSchema();
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
        $issuance = $this->issuance();

        if (! $audit->isReady() || ! $issuance->isReady()) {
            return $this->refuse([
                'The console schema is not migrated — run: php console/artisan console:migrate',
            ]);
        }

        if (! BranchRole::exists($role)) {
            $this->components->info("Role [{$role}] does not exist; nothing to revoke.");

            return self::SUCCESS;
        }

        if (! $this->confirmSummary($branch, $schema, $role)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        $target = $this->auditTarget($schema, $branch, $role);

        try {
            // Postgres refuses to drop a role that is still connected, and a
            // session left open by an earlier command in the same process would
            // be exactly that.
            BranchConnection::close();

            BranchRole::drop($role, $schema);
        } catch (Throwable $e) {
            $this->recordQuietly($audit, 'branch.delete', ProvisioningAudit::ROLLED_BACK, $target, [
                'error' => $e->getMessage(),
            ]);

            $this->components->error('Could not drop the role: '.$e->getMessage());
            $this->newLine();
            $this->components->bulletList(BranchRole::dropRoleSql($role, $schema));

            return self::FAILURE;
        }

        // The branch now has no credential it is supposed to be running, and
        // that is what the issuance record should say — a live row pointing at
        // a role that no longer exists would be worse than no row at all.
        $superseded = $issuance->supersede((string) $branch->id);

        $this->recordQuietly($audit, 'branch.delete', ProvisioningAudit::SUCCEEDED, $target, [
            'superseded_generation' => $superseded,
        ]);

        $this->newLine();
        $this->components->info('Branch role revoked');
        $this->components->twoColumnDetail('Branch', $branch->name.' ['.$branch->code.']');
        $this->components->twoColumnDetail('Postgres role', '<fg=red>dropped</>');
        $this->components->twoColumnDetail(
            'Superseded generation',
            $superseded === null ? 'none was live' : (string) $superseded,
        );

        $this->newLine();
        $this->components->bulletList([
            'The branch row, its sales and its history are untouched and still readable.',
            'Any JSON issued for this branch is now dead — destroy it.',
            'Bring the role back with: branch:rotate --recreate (it issues a new JSON).',
            // Measured against a live project: the pooler holds the dropped
            // role's OID for ~2 minutes, so a re-creation started immediately
            // spends that long unable to verify itself.
            'Re-creating within ~2 minutes is fine but cannot be verified — the pooler caches the old role.',
        ]);

        return self::SUCCESS;
    }

    private function confirmSummary(object $branch, string $schema, string $role): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        table(
            headers: ['', 'Value'],
            rows: [
                ['Schema', $schema],
                ['Branch', $branch->name.' ['.$branch->code.']'],
                ['Role to drop', $role],
                ['Branch row', 'kept'],
                ['Sales and history', 'kept'],
            ],
        );

        $this->components->warn(
            'This deletes a database login, not a branch. The device using it stops syncing '
            .'permanently until a role is re-created and a new JSON delivered. '
            .'For a reversible stop, use branch:disable instead.'
        );

        return $this->confirmed("Drop the Postgres role for branch [{$branch->code}]?");
    }
}
