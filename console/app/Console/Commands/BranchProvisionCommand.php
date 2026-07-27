<?php

namespace Provisioning\Console\Commands;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Provisioning\Support\BranchCode;
use Provisioning\Support\BranchDirectory;
use Provisioning\Support\BranchIssuer;
use Provisioning\Support\RoleName;
use Throwable;

use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Add a branch to an existing business (§8.2, Phase M3).
 *
 * The counterpart to business:provision, and deliberately the *only* command
 * that writes into a schema it did not create. Three things happen: a `branches`
 * row, a least-privilege role, a credentials JSON. Explicitly NOT a re-migration
 * — the catalog, users and settings are already there and the new branch pulls
 * them at hydrate (M5).
 *
 * The failure story is what distinguishes this from business:provision. That
 * command can compensate by dropping the schema, because it made it. This one
 * cannot: the schema holds other branches' sales history. See BranchIssuer.
 */
class BranchProvisionCommand extends BranchCommand
{
    protected $signature = 'branch:provision
        {--business= : Business UUID; the schema name is derived from it}
        {--schema= : Target an explicit schema name instead}
        {--branch-name= : Branch name}
        {--branch-code= : Branch code; prefixes every receipt serial at this branch}
        {--branch-address= : Branch address}
        {--out= : Write the credentials JSON here instead of the default path}
        {--actor= : Recorded as the operator in the provisioning audit}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Add a branch to an existing business: branches row, least-privilege role and credentials JSON';

    public function handle(): int
    {
        try {
            $schema = $this->targetSchema();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $issuer = new BranchIssuer($this->audit(), $this->issuance());

        // Before prompting for anything: a refusal here has zero side effects
        // and costs the operator no typing.
        $problems = $issuer->preflight($schema);

        if ($problems !== []) {
            return $this->refuse($problems);
        }

        try {
            $data = $this->collect();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $validator = Validator::make($data, $this->rules());

        if ($validator->fails()) {
            $this->components->error('The input is not valid:');
            $this->components->bulletList($validator->errors()->all());

            return self::INVALID;
        }

        $data = $validator->validated();
        $code = BranchCode::canonicalize((string) $data['branch_code']);

        // New in M3: business:provision creates the first branch into an empty
        // schema, so it never had to ask this. branches_code_unique is still
        // the authority — this only turns a constraint violation into a
        // sentence, and only when nothing has been created yet.
        if (BranchDirectory::codeTaken($schema, $code)) {
            $this->components->error(
                "Branch code [{$code}] is already in use in this business. Codes prefix every receipt "
                .'serial (§12), so they must be unique within a business. Try branch:list.'
            );

            return self::INVALID;
        }

        // Minted here so the confirmation can show the role name the operator
        // will actually see in Postgres.
        $data['branch_id'] = (string) Str::orderedUuid();
        $data['out'] = $this->stringOption('out');

        if (! $this->confirmSummary($data, $schema, $code)) {
            $this->components->warn('Aborted. Nothing was created.');

            return self::SUCCESS;
        }

        try {
            $summary = $issuer->provision($schema, $data, $this->stepReporter());
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->report($summary);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException Under --no-interaction with fields missing.
     */
    private function collect(): array
    {
        if (! $this->input->isInteractive()) {
            $this->requireOptions();
        }

        return [
            'branch_name' => $this->value('branch-name', fn () => text(
                label: 'Branch name',
                required: true,
            )),
            'branch_code' => $this->value('branch-code', fn () => text(
                label: 'Branch code',
                hint: 'Prefixes every receipt number at this branch, e.g. CEB gives CEB-0000000001.',
                required: true,
                // Validate at the prompt too, so a typo re-asks rather than
                // discarding the flow.
                validate: fn (string $value) => $this->branchCodeError($value),
            )),
            'branch_address' => $this->value('branch-address', fn () => text(
                label: 'Branch address',
                hint: 'Optional.',
            )),
        ];
    }

    private function requireOptions(): void
    {
        $missing = array_values(array_filter(
            ['branch-name', 'branch-code'],
            fn (string $option): bool => $this->stringOption($option) === null,
        ));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Running non-interactively requires every field as an option. Missing: --'
                .implode(', --', $missing)
            );
        }
    }

    /**
     * The branch subset of business:provision's rules — a branch added here must
     * be indistinguishable from one that command would have created.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'branch_name' => ['required', 'string', 'max:255'],
            'branch_code' => ['required', 'string', 'max:'.BranchCode::MAX_LENGTH, $this->branchCodeRule()],
            'branch_address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function branchCodeRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            $error = $this->branchCodeError(is_string($value) ? $value : '');

            if ($error !== null) {
                $fail($error);
            }
        };
    }

    private function branchCodeError(string $value): ?string
    {
        try {
            BranchCode::validate($value);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function confirmSummary(array $data, string $schema, string $code): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        try {
            $business = BranchDirectory::businessName($schema);
            $existing = count(BranchDirectory::all($schema));
        } catch (Throwable) {
            $business = '(unreadable)';
            $existing = 0;
        }

        table(
            headers: ['', 'Value'],
            rows: [
                ['Business', $business],
                ['Schema', $schema],
                ['Existing branches', (string) $existing],
                ['New branch', (string) $data['branch_name']],
                ['Branch code', $code],
                ['Postgres role', RoleName::forBranch((string) $data['branch_id'])],
            ],
        );

        return $this->confirmed('Add this branch?');
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function report(array $summary): int
    {
        $this->newLine();
        $this->components->info('Branch provisioned');

        $this->components->twoColumnDetail('Business', (string) $summary['business_name']);
        $this->components->twoColumnDetail('Schema', (string) $summary['schema']);
        $this->components->twoColumnDetail('Branch', $summary['branch_name'].' ['.$summary['branch_code'].']');
        $this->components->twoColumnDetail('Branch id', (string) $summary['branch_id']);
        $this->components->twoColumnDetail('Postgres role', (string) $summary['role']);
        $this->components->twoColumnDetail('Login name', (string) $summary['login_name']);
        $this->components->twoColumnDetail('Generation', (string) $summary['generation']);
        $this->components->twoColumnDetail('Schema version', (string) $summary['schema_version']);
        $this->components->twoColumnDetail('Min app version', (string) $summary['min_app_version']);
        $this->components->twoColumnDetail('Credentials', (string) $summary['credentials_path']);

        // The password is deliberately not printed: terminal scrollback and
        // shell logs outlive the delivery, and the JSON is the channel.
        $this->newLine();
        $this->components->bulletList($this->handlingRules());

        return self::SUCCESS;
    }

    /**
     * An option's value, or the prompt's. Blank optional fields become '' so the
     * insert writes null exactly where business:provision would.
     *
     * @param  callable(): string  $prompt
     */
    private function value(string $option, callable $prompt): string
    {
        $supplied = $this->stringOption($option);

        if ($supplied !== null) {
            return $supplied;
        }

        if (! $this->input->isInteractive()) {
            // requireOptions() has already vetted the mandatory ones.
            return '';
        }

        return trim((string) $prompt());
    }
}
