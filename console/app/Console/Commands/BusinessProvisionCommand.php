<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Provisioning\Support\BranchCode;
use Provisioning\Support\BusinessProvisioner;
use Provisioning\Support\BusinessSettingKeys;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\RoleName;
use Provisioning\Support\SchemaName;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Phase M2 milestone command: provision a business (§8.1).
 *
 * Collects Owner, Business and first Branch, and only once every value is
 * present and valid creates the schema, migrates it, seeds it, creates the
 * branch's least-privilege role, and emits the credentials JSON.
 *
 * Every prompt has a matching option, so this is equally an interactive tool and
 * a scriptable one — and under --no-interaction a missing required value is an
 * error rather than a silent default, because a business quietly provisioned
 * with a defaulted timezone or currency is worse than one not provisioned.
 *
 * Create-only by design: see BusinessProvisioner for why that is what makes
 * failure cleanup safe. Adding a branch to an existing business is Phase M3.
 */
class BusinessProvisionCommand extends Command
{
    protected $signature = 'business:provision
        {--business= : Reuse this business UUID instead of minting one}
        {--owner-name= : The Owner\'s full name}
        {--username= : The Owner\'s login username}
        {--owner-password= : The Owner\'s password (exposed in ps and shell history — prefer the prompt)}
        {--business-name= : Registered business name}
        {--business-address= : Business address}
        {--tin= : Taxpayer identification number}
        {--receipt-footer= : Footer line printed on every receipt}
        {--timezone= : Business timezone, e.g. Asia/Manila}
        {--currency-symbol= : Currency symbol, e.g. P}
        {--branch-name= : First branch name}
        {--branch-code= : First branch code; prefixes every receipt serial}
        {--branch-address= : First branch address}
        {--out= : Write the credentials JSON here instead of the default path}
        {--actor= : Recorded as the operator in the provisioning audit}
        {--disposable : THROWAWAY: name the schema biz_test_* so rig:clean --all may destroy it}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Provision a business: schema, migration, seed, branch role and credentials JSON';

    /** Optional text fields, which become '' rather than null (as the app stores them). */
    private const OPTIONAL = ['business-address', 'tin', 'receipt-footer', 'branch-address'];

    public function handle(): int
    {
        $actor = $this->stringOption('actor');
        $audit = new ProvisioningAudit($actor);
        $provisioner = new BusinessProvisioner($audit, new CredentialIssuance($actor), $this->getOutput());

        // Both identities are minted here, before anything is collected, so the
        // preflight can check the real schema AND the real role name rather
        // than probing for one and hoping about the other.
        try {
            $businessId = $this->resolveBusinessId();
            $branchId = (string) Str::orderedUuid();

            // The disposability decision is made HERE, before any DDL, because
            // it is spelt in the schema name itself — there is nothing to write
            // afterwards and nothing to forget to write. See
            // SchemaName::DISPOSABLE_PREFIX for why the name carries it.
            $schema = SchemaName::forBusiness($businessId, (bool) $this->option('disposable'));
            $role = RoleName::forBranch($branchId);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $problems = $provisioner->preflight($schema, $role);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
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
        $data['business_id'] = $businessId;
        $data['branch_id'] = $branchId;
        $data['out'] = $this->stringOption('out');
        // Travels with the two identities because the provisioner re-derives the
        // schema name from them; without it the preflight and the CREATE would
        // disagree about which schema this is.
        $data['disposable'] = (bool) $this->option('disposable');

        if (! $this->confirmSummary($data, $schema)) {
            $this->components->warn('Aborted. Nothing was created.');

            return self::SUCCESS;
        }

        try {
            $summary = $provisioner->provision(
                $data,
                fn (string $step) => $this->line("  <fg=gray>›</> {$step}"),
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->report($summary);
    }

    /**
     * The business UUID: reused when supplied, minted otherwise.
     *
     * --business exists so a schema name can be reproduced deliberately (a
     * retry after a cleaned-up failure, or a fixture in a test). It is validated
     * by SchemaName::forBusiness, which rejects anything that is not a UUID.
     */
    private function resolveBusinessId(): string
    {
        return $this->stringOption('business') ?? (string) Str::orderedUuid();
    }

    /**
     * Gather every field: options win, prompts fill the gaps, and a gap that
     * cannot be prompted for is a hard error.
     *
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $interactive = $this->input->isInteractive();

        if (! $interactive) {
            $this->requireOptions();
        }

        return [
            'owner_name' => $this->value('owner-name', fn () => text(
                label: "Owner's full name",
                required: true,
            )),
            'username' => $this->value('username', fn () => text(
                label: "Owner's username",
                required: true,
            )),
            ...$this->ownerPassword(),

            'business_name' => $this->value('business-name', fn () => text(
                label: 'Business name',
                required: true,
            )),
            'business_address' => $this->value('business-address', fn () => text(
                label: 'Business address',
                hint: 'Printed on receipts. Optional.',
            )),
            'tin' => $this->value('tin', fn () => text(
                label: 'TIN',
                hint: 'Taxpayer identification number. Optional.',
            )),
            'receipt_footer' => $this->value('receipt-footer', fn () => text(
                label: 'Receipt footer',
                hint: 'Optional.',
            )),
            'timezone' => $this->value('timezone', fn () => suggest(
                label: 'Business timezone',
                options: timezone_identifiers_list(),
                default: BusinessSettingKeys::DEFAULTS['timezone'],
                required: true,
            )),
            'currency_symbol' => $this->value('currency-symbol', fn () => text(
                label: 'Currency symbol',
                default: BusinessSettingKeys::DEFAULTS['currency_symbol'],
                required: true,
            )),

            'branch_name' => $this->value('branch-name', fn () => text(
                label: 'First branch name',
                required: true,
            )),
            'branch_code' => $this->value('branch-code', fn () => text(
                label: 'First branch code',
                hint: 'Prefixes every receipt number, e.g. MNL gives MNL-0000000001.',
                required: true,
                // Validate at the prompt as well as in the batch below, so a
                // typo re-asks instead of discarding a 13-field flow.
                validate: fn (string $value) => $this->branchCodeError($value),
            )),
            'branch_address' => $this->value('branch-address', fn () => text(
                label: 'First branch address',
                hint: 'Optional.',
            )),
        ];
    }

    /**
     * The Owner's password, typed twice (§8.1: "password → hashed").
     *
     * The developer sets it and hands it over at delivery; the app's own
     * password-change screens are how the owner replaces it later.
     *
     * @return array<string, string>
     */
    private function ownerPassword(): array
    {
        $supplied = $this->stringOption('owner-password');

        if ($supplied !== null) {
            return ['password' => $supplied, 'password_confirmation' => $supplied];
        }

        $entered = password(label: "Owner's password", required: true);

        return [
            'password' => $entered,
            'password_confirmation' => password(label: 'Confirm the password', required: true),
        ];
    }

    /**
     * Under --no-interaction there is nobody to prompt, so every field that has
     * no default must already be an option. Reported as one list rather than
     * one-at-a-time, so a scripted caller fixes the whole invocation at once.
     */
    private function requireOptions(): void
    {
        $required = [
            'owner-name', 'username', 'owner-password', 'business-name',
            'timezone', 'currency-symbol', 'branch-name', 'branch-code',
        ];

        $missing = array_values(array_filter(
            $required,
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
     * These rules are the ONLY validation an Owner account and a business
     * identity now get. Until M5 they mirrored the app's own setup wizard, which
     * collected the same fields on the device; that wizard connects and hydrates
     * instead, so nothing downstream re-checks what is accepted here.
     *
     * Rule::unique('users', 'username') is deliberately absent: the console's
     * default connection is the admin one pinned to `public`, so the rule would
     * query a `users` table that has nothing to do with this business. The
     * schema is brand new anyway (create-only), the seed transaction refuses a
     * non-empty schema, and users_username_unique is the real authority.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            // Spelled out, NOT Password::defaults().
            //
            // This app has no service provider at all (bootstrap/providers.php is
            // empty by design — it is CLI-only), so `defaults()` here resolves to
            // Laravel's unconfigured `min(8)` and nothing else. The POS app sets a
            // real policy in AppServiceProvider::configurePasswordPolicy(), and
            // until this line was written the two silently disagreed: every
            // cashier the app created had a stricter rule than the OWNER minted
            // here — the one account that can never be archived, the only one that
            // sees item cost, and the only one that can read every branch's sales
            // at once. Backwards, and invisible.
            //
            // Duplicated rather than shared because §18 forbids either app
            // referencing the other's code — the same reason
            // CredentialVault::FINGERPRINT_DOMAIN restates BranchSecret's. Keep
            // this in step with configurePasswordPolicy() by hand; the
            // BusinessProvisionValidationTest cases below are what notice.
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],

            'business_name' => ['required', 'string', 'max:255'],
            'business_address' => ['nullable', 'string', 'max:1000'],
            'tin' => ['nullable', 'string', 'max:50'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'timezone:all'],
            'currency_symbol' => ['required', 'string', 'max:5'],

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
    private function confirmSummary(array $data, string $schema): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        // Said before the table rather than as a row in it: this is the one
        // property of the business that cannot be changed afterwards without
        // re-provisioning, and the one that makes it destroyable in bulk.
        if (SchemaName::isDisposable($schema)) {
            $this->components->warn(
                'DISPOSABLE: this business lands in the biz_test_ namespace and '
                .'`composer rig:clean:all` will destroy it without asking. Do not use --disposable for anything real.'
            );
        }

        table(
            headers: ['', 'Value'],
            rows: [
                ['Business', $data['business_name']],
                ['Schema', $schema],
                ['Owner', $data['owner_name'].' ('.$data['username'].')'],
                ['Timezone', $data['timezone']],
                ['Currency', $data['currency_symbol']],
                ['First branch', $data['branch_name']],
                ['Branch code', BranchCode::canonicalize((string) $data['branch_code'])],
            ],
        );

        return confirm(label: 'Provision this business?', default: false);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function report(array $summary): int
    {
        $this->newLine();
        $this->components->info('Business provisioned');

        // --force and --no-interaction both skip confirmSummary(), and those are
        // exactly how the suites and the rig call this, so say it here too.
        if (SchemaName::isDisposable((string) $summary['schema'])) {
            $this->components->warn('DISPOSABLE — `composer rig:clean:all` will destroy this business.');
        }

        $this->components->twoColumnDetail('Business', (string) $summary['business_name']);
        $this->components->twoColumnDetail('Business id', (string) $summary['business_id']);
        $this->components->twoColumnDetail('Schema', (string) $summary['schema']);
        $this->components->twoColumnDetail('Branch', $summary['branch_name'].' ['.$summary['branch_code'].']');
        $this->components->twoColumnDetail('Branch id', (string) $summary['branch_id']);
        $this->components->twoColumnDetail('Postgres role', (string) $summary['role']);
        $this->components->twoColumnDetail('Login name', (string) $summary['login_name']);
        $this->components->twoColumnDetail('Schema version', (string) $summary['schema_version']);
        $this->components->twoColumnDetail('Min app version', (string) $summary['min_app_version']);
        $this->components->twoColumnDetail('Credentials', (string) $summary['credentials_path']);

        // The branch password is deliberately NOT printed: terminal scrollback
        // and shell logs outlive the delivery, and the JSON is the channel.
        $this->newLine();
        $this->components->bulletList([
            'The credentials JSON is the only copy of the branch password — it is mode 0600.',
            'Deliver it to the device, import it in the setup wizard, then remove it (§9).',
            'Rotation is a fresh AppImage plus a new JSON, never an in-place swap.',
        ]);

        return self::SUCCESS;
    }

    /**
     * An option's value, or the prompt's, normalising blanks to '' for optional
     * fields so the seed writes '' rather than null — matching how the app
     * stores an unset business setting.
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

    /** Null when absent or blank, so '' is never mistaken for a supplied value. */
    private function stringOption(string $option): ?string
    {
        $value = $this->option($option);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return in_array($option, self::OPTIONAL, true) ? '' : null;
        }

        return $value;
    }
}
