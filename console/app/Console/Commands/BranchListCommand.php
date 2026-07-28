<?php

namespace Provisioning\Console\Commands;

use InvalidArgumentException;
use Provisioning\Support\BranchDirectory;
use Provisioning\Support\BranchState;
use Provisioning\Support\SchemaConnection;
use Throwable;

use function Laravel\Prompts\table;

/**
 * List a business's branches and the state of each one's credential (M3).
 *
 * Read-only, and therefore not audited — the trail records changes, and an
 * entry per listing would bury them.
 *
 * Not in the M3 task list, and included anyway: every other command in this
 * phase is targeted with --branch or --branch-code, and without this there is
 * nowhere to read those values from short of querying Postgres by hand. It also
 * joins the two halves that no single table holds — the business's `branches`
 * rows and the cluster's `pg_roles` — which is what makes "this branch has no
 * role" and "this branch's role cannot log in" visible at all.
 *
 * This is deliberately NOT M10's monitor. It reports what the console did; the
 * monitor reports what each branch is doing, from `branch_status` rows the
 * branches themselves upsert.
 */
class BranchListCommand extends BranchCommand
{
    protected $signature = 'branch:list
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}';

    protected $description = "List a business's branches with each one's role and credential state";

    public function handle(): int
    {
        try {
            $schema = $this->targetSchema();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        // Checked before querying, so a mistyped business UUID gets a sentence
        // rather than a raw "relation does not exist" from Postgres.
        if (! SchemaConnection::exists($schema)) {
            $this->components->error(
                "Schema [{$schema}] does not exist. Check the business UUID, or create it with: "
                .'php console/artisan business:provision'
            );

            return self::FAILURE;
        }

        try {
            $states = BranchState::forSchema($schema, $this->issuance());
            $business = BranchDirectory::businessName($schema);
        } catch (Throwable $e) {
            $this->components->error("Could not read [{$schema}]: ".$e->getMessage());

            return self::FAILURE;
        }

        if ($states === []) {
            $this->components->warn("[{$schema}] has no branches.");

            return self::SUCCESS;
        }

        $rows = array_map(fn (BranchState $state): array => [
            $state->code(),
            $state->name(),
            $state->id(),
            $this->coloured($state->credential),
            $state->generation,
        ], $states);

        $this->components->info("Branches of [{$business}] in {$schema}");

        table(
            headers: ['Code', 'Name', 'Branch id', 'Credential', 'Generation'],
            rows: $rows,
        );

        return self::SUCCESS;
    }

    /**
     * The state word, in the colour a table can carry. BranchState owns the
     * words themselves — three states, not two, because "no role" is not the
     * same fact as "disabled" — and this adds only the decoration, which the
     * menu deliberately does without.
     */
    private function coloured(string $credential): string
    {
        return match ($credential) {
            'no role' => "<fg=red>{$credential}</>",
            'disabled' => "<fg=yellow>{$credential}</>",
            default => "<fg=green>{$credential}</>",
        };
    }
}
