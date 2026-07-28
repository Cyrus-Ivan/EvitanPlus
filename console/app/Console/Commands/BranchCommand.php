<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Provisioning\Support\BranchDirectory;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\RoleName;
use Provisioning\Support\SchemaConnection;
use Provisioning\Support\SchemaName;
use RuntimeException;
use stdClass;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Shared plumbing for the branch lifecycle commands (M3).
 *
 * Six commands target a business and (mostly) a branch in exactly the same way,
 * and the resolution has one rule that must not be re-derived per command: the
 * Postgres role comes from the branch UUID, never the branch code (§7 As
 * built). One place to get that right beats six chances to get it wrong.
 *
 * The console had no command base class before this; five near-identical
 * lifecycle commands are what justifies introducing one.
 */
abstract class BranchCommand extends Command
{
    /** Options every branch command accepts; kept identical so muscle memory transfers. */
    protected const TARGET_OPTIONS = '
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--branch= : Branch UUID}
        {--branch-code= : Branch code, e.g. MNL}
        {--actor= : Recorded as the operator in the provisioning audit}';

    /**
     * The business schema this invocation targets.
     *
     * @throws InvalidArgumentException When --business/--schema are missing or both given.
     */
    protected function targetSchema(): string
    {
        return SchemaName::resolve(
            $this->stringOption('business'),
            $this->stringOption('schema'),
        );
    }

    /**
     * The branch row this invocation targets.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    protected function targetBranch(string $schema): stdClass
    {
        return BranchDirectory::resolve(
            $schema,
            $this->stringOption('branch'),
            $this->stringOption('branch-code'),
        );
    }

    protected function roleFor(stdClass $branch): string
    {
        return RoleName::forBranch((string) $branch->id);
    }

    /**
     * Why this schema cannot be operated on, or null when it can.
     *
     * branch:disable, branch:enable and branch:revoke used to go straight from
     * targetSchema() to targetBranch(), which pins a search_path that may point
     * at nothing and then queries `branches`. A one-digit typo in a business
     * UUID therefore produced a raw
     * `SQLSTATE[42P01] ... relation "branches" does not exist` dump — during an
     * incident, from the commands an operator reaches for during one — where
     * README §5 promises a sentence naming the fix. branch:list and the two
     * BranchIssuer commands already checked; these three did not, and the
     * message is the same in all cases, so it lives here.
     */
    protected function schemaProblem(string $schema): ?string
    {
        if (! SchemaConnection::exists($schema)) {
            return "Schema [{$schema}] does not exist. Check the business UUID, or create it with: "
                .'php console/artisan business:provision';
        }

        if (! BranchDirectory::isProvisionedBusiness($schema)) {
            return "Schema [{$schema}] exists but is not a provisioned business — it has no schema_meta "
                .'or no branches table. Finish it with: php console/artisan schema:migrate';
        }

        return null;
    }

    /**
     * The audit target for one branch. business_id is included because
     * provisioning_audit indexes on it — "what has happened to this business"
     * is the query the trail exists to answer, and a row missing it is invisible
     * to that query.
     *
     * @return array<string, string>
     */
    protected function auditTarget(string $schema, stdClass $branch, string $role): array
    {
        return [
            'business_id' => SchemaName::toBusinessUuid($schema),
            'schema_name' => $schema,
            'branch_id' => (string) $branch->id,
            'branch_code' => (string) $branch->code,
            'role_name' => $role,
        ];
    }

    /**
     * Record an audit entry without letting its failure change the outcome of
     * work that has already happened. The role change is real; claiming
     * otherwise because the trail is unreachable would be the bigger lie.
     *
     * @param  array<string, string>  $target
     * @param  array<string, mixed>  $detail
     */
    protected function recordQuietly(
        ProvisioningAudit $audit,
        string $action,
        string $outcome,
        array $target,
        array $detail = [],
    ): void {
        try {
            $audit->record($action, $outcome, $target, $detail);
        } catch (Throwable $e) {
            $this->components->warn('The action succeeded but its audit entry failed: '.$e->getMessage());
        }
    }

    protected function audit(): ProvisioningAudit
    {
        return new ProvisioningAudit($this->stringOption('actor'));
    }

    protected function issuance(): CredentialIssuance
    {
        return new CredentialIssuance($this->stringOption('actor'));
    }

    /**
     * Report a preflight refusal. Every problem is printed, not just the first:
     * a scripted caller should be able to fix the whole invocation at once.
     *
     * @param  list<string>  $problems
     */
    protected function refuse(array $problems): int
    {
        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }

    /** The progress reporter the issuer drives. */
    protected function stepReporter(): callable
    {
        return fn (string $step) => $this->line("  <fg=gray>›</> {$step}");
    }

    /**
     * Confirm unless --force or non-interactive, mirroring business:provision.
     * Default false everywhere: none of these actions should be one keystroke.
     */
    protected function confirmed(string $question): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return confirm(label: $question, default: false);
    }

    /**
     * Null when absent or blank, so '' is never mistaken for a supplied value.
     *
     * Also null when the command does not declare the option at all. That is
     * not defensiveness for its own sake: helpers on this base class read
     * shared options (--actor) that a read-only command like branch:list has no
     * reason to declare, and Symfony throws rather than returning null for an
     * undefined option.
     */
    protected function stringOption(string $option): ?string
    {
        if (! $this->getDefinition()->hasOption($option)) {
            return null;
        }

        $value = $this->option($option);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The lines every credential-issuing command ends with. §9's handling rules
     * are the reason the JSON exists in the form it does, so they are repeated
     * at the moment a file has just been written rather than left to the README.
     *
     * @return list<string>
     */
    protected function handlingRules(): array
    {
        return [
            'The credentials JSON is the only copy of the branch password — it is mode 0600.',
            'Deliver it to the device, import it in the setup wizard, then remove it (§9).',
            'Delivery is a fresh AppImage plus this JSON, never an in-place swap.',
        ];
    }
}
