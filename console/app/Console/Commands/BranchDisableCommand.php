<?php

namespace Provisioning\Console\Commands;

use InvalidArgumentException;
use Provisioning\Support\BranchRole;
use Provisioning\Support\ProvisioningAudit;
use RuntimeException;
use Throwable;

/**
 * Revoke a branch role's LOGIN (§8.3).
 *
 * The whole of "disable": one ALTER ROLE. Grants are left exactly as they are,
 * because a role that cannot connect cannot use them — and leaving them intact
 * means branch:enable is a single statement rather than a re-derivation of the
 * privilege matrix that could silently differ from what the branch had before.
 *
 * ## What this does NOT touch
 *
 * `branches.disabled_at`. That column is business state, pulled down to every
 * till; a credential disabled because it may have leaked is not a branch that
 * has closed. Conflating them would mean re-enabling a credential silently
 * reopens a branch, and would push business-visible state as a side effect of a
 * security action. Branch closure is a separate concern and a later command.
 *
 * ## What the branch experiences
 *
 * Sync fails "unauthorized" and the till keeps selling offline behind a clear
 * banner (§4 — a sync failure never stops the till). Work queues in the outbox
 * and flushes on the first successful cycle after branch:enable. Because pushes
 * are UUID-keyed upserts (§11.4), no amount of disabled time can produce
 * duplicates on the way back in.
 *
 * Open sessions ARE terminated, by BranchRole::setLogin. Postgres checks LOGIN
 * only at connect time, and behind Supabase's pooler that left a "disabled"
 * branch still connecting more than 90 seconds later — the pooler served new
 * clients from its warm pool without re-authenticating them. A disable that
 * does not disable is worse than no feature, so the sessions go too.
 */
class BranchDisableCommand extends BranchCommand
{
    protected $signature = 'branch:disable
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--branch= : Branch UUID}
        {--branch-code= : Branch code, e.g. MNL}
        {--actor= : Recorded as the operator in the provisioning audit}
        {--force : Skip the confirmation prompt}';

    protected $description = "Revoke a branch role's LOGIN — its sync fails unauthorized while it keeps selling offline";

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

        if (! $audit->isReady()) {
            return $this->refuse([
                'The provisioning audit store is missing — run: php console/artisan console:migrate',
            ]);
        }

        $canLogin = BranchRole::canLogin($role);

        if ($canLogin === null) {
            return $this->refuse([
                "Role [{$role}] does not exist. There is nothing to disable; "
                .'branch:rotate --recreate would bring it back.',
            ]);
        }

        // Idempotent, and reported as such: an operator re-running this after a
        // dropped connection wants confirmation, not an error.
        if ($canLogin === false) {
            $this->components->info("Branch [{$branch->code}] is already disabled.");

            return self::SUCCESS;
        }

        if (! $this->confirmed("Disable branch [{$branch->code}] — its sync stops until re-enabled?")) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        $target = $this->auditTarget($schema, $branch, $role);

        try {
            BranchRole::setLogin($role, false);
        } catch (Throwable $e) {
            // Nothing persisted, so rolled_back is the honest outcome — the
            // same vocabulary the provisioning flows use for "attempted,
            // left nothing behind".
            $this->recordQuietly($audit, 'branch.disable', ProvisioningAudit::ROLLED_BACK, $target, [
                'error' => $e->getMessage(),
            ]);

            $this->components->error('Could not disable the role: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->recordQuietly($audit, 'branch.disable', ProvisioningAudit::SUCCEEDED, $target);

        $this->newLine();
        $this->components->info('Branch disabled');
        $this->components->twoColumnDetail('Branch', $branch->name.' ['.$branch->code.']');
        $this->components->twoColumnDetail('Postgres role', $role);
        $this->components->twoColumnDetail('LOGIN', '<fg=red>revoked</>');

        $this->newLine();
        $this->components->bulletList([
            'The till keeps selling offline; only sync is affected.',
            'Its outbox grows and flushes on the first cycle after branch:enable.',
            'The branch stops reporting to the monitor, so it will look stale rather than unauthorized.',
            // Worth stating to anyone disabling a credential they believe has
            // leaked. It is only true because setLogin also terminates the
            // role's sessions — NOLOGIN alone left a disabled branch connecting
            // through the pooler indefinitely.
            'Cut-off is immediate: the role loses LOGIN and its open sessions are terminated.',
        ]);

        return self::SUCCESS;
    }
}
