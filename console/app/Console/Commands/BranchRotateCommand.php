<?php

namespace Provisioning\Console\Commands;

use InvalidArgumentException;
use Provisioning\Support\BranchIssuer;
use Provisioning\Support\BranchRole;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\table;

/**
 * Re-issue a branch's credential (§8.3 rotate, and re-create).
 *
 * Rotation and re-creation are one command because they are one act — mint a
 * secret, make the role hold it, emit a JSON — differing only in whether the
 * role currently exists. --recreate is a required statement of intent in BOTH
 * directions: rotating a missing role is refused without it, and re-creating a
 * live one is refused with it. So a rotation can never silently become a
 * re-creation, which would leave an operator believing a role's grants had been
 * preserved when they had just been rebuilt (or the reverse).
 *
 * ## The password change cannot be undone
 *
 * The previous secret was written to one credentials file and never stored
 * (§9), so there is nothing to put back. The mitigation is ordering — the new
 * credentials file is reserved before the ALTER ROLE — and honesty afterwards:
 * a failure past that point says so plainly instead of implying a rollback that
 * did not happen. See BranchIssuer.
 *
 * ## Delivery
 *
 * A new credential does NOT reach the branch by itself. §9's model is a fresh
 * AppImage plus this JSON, re-imported through the setup wizard. Between
 * rotation and delivery the branch behaves exactly as a disabled one does: sync
 * fails unauthorized, the till keeps selling, the outbox grows and flushes on
 * the next successful cycle.
 */
class BranchRotateCommand extends BranchCommand
{
    protected $signature = 'branch:rotate
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--branch= : Branch UUID}
        {--branch-code= : Branch code, e.g. MNL}
        {--recreate : Re-create a role that was removed with branch:revoke}
        {--verify-wait= : Seconds to wait for the pooler when re-creating (default 180)}
        {--out= : Write the credentials JSON here instead of the default path}
        {--actor= : Recorded as the operator in the provisioning audit}
        {--force : Skip the confirmation prompt}';

    protected $description = "Issue a new password for a branch's role (or re-create a revoked role) and emit a new credentials JSON";

    public function handle(): int
    {
        $recreate = (bool) $this->option('recreate');

        try {
            $schema = $this->targetSchema();
            // Resolved up here, with the other input validation: a bad
            // --verify-wait is a typing mistake, and the place to report one is
            // before anything is confirmed, not from inside the run.
            $verifyWait = $this->verifyWait();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $issuer = new BranchIssuer($this->audit(), $this->issuance());

        $problems = $issuer->preflight($schema);

        if ($problems !== []) {
            return $this->refuse($problems);
        }

        try {
            $branch = $this->targetBranch($schema);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $role = $this->roleFor($branch);

        if (($problem = $this->modeProblem($role, $recreate)) !== null) {
            return $this->refuse([$problem]);
        }

        if (! $this->confirmSummary($branch, $schema, $role, $recreate)) {
            $this->components->warn('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            if ($recreate) {
                $this->components->info(
                    'Re-creating a role reuses a name the connection pooler has cached, so verification '
                    .'can take up to ~2 minutes. The credential is valid throughout.'
                );
            }

            $summary = $issuer->reissue(
                $schema,
                $branch,
                [
                    'out' => $this->stringOption('out'),
                    'verify_wait' => $verifyWait,
                ],
                $recreate,
                $this->stepReporter(),
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->report($summary, $recreate);
    }

    /**
     * How long verification may wait for the pooler, in seconds.
     *
     * Exposed mainly so the test suite can pass 0 and take the tolerated path
     * immediately instead of spending two minutes proving a known-slow cache.
     */
    private function verifyWait(): int
    {
        $supplied = $this->stringOption('verify-wait');

        if ($supplied === null) {
            return BranchIssuer::RECREATE_VERIFY_SECONDS;
        }

        // Refuses rather than casting. `(int) 'abc'` is 0, which silently turns
        // a typo into a single verification attempt against a pooler cache
        // measured at ~126 seconds — so a routine re-creation reports
        // verified=false and sends the operator into §4's investigation for no
        // reason. SupabaseEndpoint::port() refuses an unparseable value for
        // exactly this reason; so does this.
        if (filter_var($supplied, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidArgumentException(
                "--verify-wait must be a whole number of seconds, got [{$supplied}]."
            );
        }

        return (int) $supplied;
    }

    /**
     * Does the role's actual state match the mode the operator asked for?
     *
     * Both mismatches name the correct invocation rather than just refusing:
     * the operator is already holding the right command and one wrong flag.
     */
    private function modeProblem(string $role, bool $recreate): ?string
    {
        $exists = BranchRole::exists($role);

        if ($exists && $recreate) {
            return "Role [{$role}] still exists, so there is nothing to re-create. "
                .'To give it a new password, run the same command without --recreate. '
                .'To replace the role itself, run branch:revoke first.';
        }

        if (! $exists && ! $recreate) {
            return "Role [{$role}] does not exist — it was never created, or branch:revoke removed it. "
                .'Re-create it with the same command plus --recreate.';
        }

        return null;
    }

    private function confirmSummary(object $branch, string $schema, string $role, bool $recreate): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        $current = $this->issuance()->current((string) $branch->id);

        table(
            headers: ['', 'Value'],
            rows: [
                ['Schema', $schema],
                ['Branch', $branch->name.' ['.$branch->code.']'],
                ['Postgres role', $role],
                ['Action', $recreate ? 're-create the role' : 'replace the password'],
                ['Current generation', $current === null ? 'none' : (string) $current->generation],
            ],
        );

        $this->components->warn(
            'The credential this branch is using now stops working immediately. It keeps selling '
            .'offline, but it cannot sync until it receives the new AppImage and JSON.'
        );

        // Said before the confirmation rather than discovered in the report: the
        // rotation is legitimate on a disabled role, but it cannot be proven
        // while LOGIN is revoked, and the operator should choose knowing that.
        if (BranchRole::canLogin($role) === false) {
            $this->components->warn(
                'This role has LOGIN revoked, so the new credential cannot be verified. It will be '
                .'issued and written, and reported as unverified. Run branch:enable, then re-run '
                .'this command, to prove it end to end.'
            );
        }

        return $this->confirmed($recreate ? 'Re-create this role?' : 'Rotate this credential?');
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function report(array $summary, bool $recreate): int
    {
        $this->newLine();
        $this->components->info($recreate ? 'Branch role re-created' : 'Branch credential rotated');

        $this->components->twoColumnDetail('Business', (string) $summary['business_name']);
        $this->components->twoColumnDetail('Schema', (string) $summary['schema']);
        $this->components->twoColumnDetail('Branch', $summary['branch_name'].' ['.$summary['branch_code'].']');
        $this->components->twoColumnDetail('Postgres role', (string) $summary['role']);
        $this->components->twoColumnDetail('Login name', (string) $summary['login_name']);
        $this->components->twoColumnDetail('Generation', (string) $summary['generation']);
        $this->components->twoColumnDetail('Schema version', (string) $summary['schema_version']);
        $this->components->twoColumnDetail('Min app version', (string) $summary['min_app_version']);
        $this->components->twoColumnDetail('Credentials', (string) $summary['credentials_path']);

        $this->components->twoColumnDetail(
            'Verified',
            $summary['verified'] ? '<fg=green>connected as the branch</>' : '<fg=yellow>not yet — see below</>',
        );

        $this->newLine();

        // Said plainly rather than buried: the operator is about to deliver
        // this file, and needs to know whether it was proven or merely written.
        if (! $summary['verified']) {
            $this->components->warn(
                'The change was made and the JSON is written, but connecting as the branch did not '
                .'succeed within the wait, so this credential is NOT proven end to end. '
                .'Nothing was rolled back — the role already holds this password and the file is its '
                .'only copy, so discarding it would strand the branch. Recorded as verified=false.'
            );

            $this->components->bulletList([
                'Last error: '.$summary['verify_error'],
                // Named conditionally, because pointing at the pooler when the
                // role simply has LOGIN revoked sends the operator to wait for
                // a cache that was never involved.
                ...($summary['login_revoked']
                    ? ['This is not pooler lag — the role cannot log in at all until branch:enable runs.']
                    : ['Usually the connection pooler still serving the previous role; it clears in ~2 minutes.']),
                'Confirm before delivering: re-run this command, or connect with the JSON by hand.',
            ]);

            $this->newLine();
        }

        $this->components->bulletList([
            ...$this->handlingRules(),
            'Any JSON previously issued for this branch is now dead — destroy it.',
        ]);

        return self::SUCCESS;
    }
}
