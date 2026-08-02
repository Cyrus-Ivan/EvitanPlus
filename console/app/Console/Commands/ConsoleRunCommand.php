<?php

namespace Provisioning\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Provisioning\Support\BranchDirectory;
use Provisioning\Support\BranchState;
use Provisioning\Support\BusinessDirectory;
use Provisioning\Support\CredentialIssuance;
use Provisioning\Support\ProvisioningAudit;
use Provisioning\Support\SchemaName;
use Provisioning\Support\SupabaseEndpoint;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * The console's front door: pick a business, pick a branch, pick an action.
 *
 * ## Why a menu at all
 *
 * Every branch command targets a business with --business XOR --schema and a
 * branch with --branch XOR --branch-code. That machinery exists because
 * targeting is the error-prone part — SchemaName::resolve and
 * BranchDirectory::resolve are both, in effect, guards against mistyping an
 * identifier copied out of a previous command's output. A menu retires the whole
 * class: you cannot mistype a UUID you selected from a list.
 *
 * So the navigation is OBJECT-FIRST — business, then branch, then verb — rather
 * than verb-first-then-prompt-for-a-target. That is the order the domain is in
 * (business → branch → branch role) and the order the runbook's recipes read in.
 *
 * ## What it is not
 *
 * Not a reimplementation. Every action dispatches the real command through
 * $this->call(), and never with --force. Selecting "Rotate the credential" still
 * gets BranchRotateCommand's summary table, its warning that the current
 * credential stops working immediately, and its confirm(default: false). The
 * menu inherits every warning, report line and handling rule that way instead of
 * restating them — restating them is how they drift apart.
 *
 * Two read-only commands stop being actions here and become the screens
 * themselves: branch:list is the branch picker, schema:version is the business
 * screen's header. Both still exist for scripting.
 *
 * ## Nothing ever clears the screen
 *
 * Each render appends. The output of these commands IS the deliverable — the
 * credentials JSON path is printed once and nowhere else — so navigating away
 * from a report must not take the report with it.
 */
class ConsoleRunCommand extends Command
{
    protected $signature = 'console:run
        {--actor= : Recorded as the operator in the provisioning audit}';

    protected $description = 'The interactive menu: pick a business, pick a branch, pick an action';

    /** The commands that declare --actor, and so can be handed one. */
    private const AUDITED = [
        'business:provision',
        'branch:provision',
        'branch:rotate',
        'branch:disable',
        'branch:enable',
        'branch:revoke',
    ];

    /** Is the admin connection answering? False puts the menu in setup-only mode. */
    private bool $online = false;

    public function handle(): int
    {
        // Symfony's configureIO already turns interactivity off for --no-interaction
        // AND for a non-tty stdin, so this one check covers both.
        if (! $this->input->isInteractive()) {
            $this->components->error(
                'console:run is the interactive menu; there is nobody to prompt. '
                .'The individual commands are the scripting surface — see console/README.md §2.'
            );

            return self::INVALID;
        }

        $this->banner();

        while (true) {
            $choice = $this->mainMenu();

            if ($choice === 'exit') {
                return self::SUCCESS;
            }

            if ($choice === 'setup') {
                $this->setupScreen();

                // Both facts the banner reports can have changed in there:
                // console:migrate creates the trail, and a corrected .env is
                // usually why someone went looking for console:ping.
                $this->banner();

                continue;
            }

            if ($choice === 'provision') {
                $this->dispatch('business:provision');

                continue;
            }

            $this->businessScreen(substr($choice, strlen('schema:')));
        }
    }

    /**
     * The actions that make sense for a branch role in a given state.
     *
     * Gating on the actual state is what removes the --recreate trap at the
     * point of choosing: rotate is never offered for a role that is not there,
     * and re-create is never offered for one that is. The glossary's rule that
     * "--recreate is required in BOTH directions" is kept, not bypassed — these
     * are two distinctly labelled items, the flag is still passed explicitly,
     * and BranchRotateCommand::modeProblem() remains the backstop for anyone
     * driving the command directly.
     *
     * Static and pure so the gating is testable without a database.
     *
     * @return array<string, string>
     */
    public static function actionsFor(?bool $canLogin): array
    {
        $rotate = 'Rotate the credential — new password, new JSON';
        $revoke = 'Revoke the role — drops the role; the branch and its history stay';

        return match ($canLogin) {
            null => ['recreate' => 'Re-create the role and issue a new credential'],
            false => [
                'enable' => 'Enable syncing — restore LOGIN',
                // Legitimate, and it cannot be verified while LOGIN is revoked.
                // Saying so in the label beats letting the operator find out
                // from a report they only read after choosing.
                'rotate' => $rotate.' (cannot be verified while disabled)',
                'revoke' => $revoke,
            ],
            true => [
                'rotate' => $rotate,
                'disable' => 'Disable syncing — revoke LOGIN; the till keeps selling offline',
                'revoke' => $revoke,
            ],
        };
    }

    /**
     * Where this console is pointed, and whether it can get there.
     *
     * The password is never echoed, not even masked — the same rule
     * console:ping follows, for the same reason: scrollback outlives the run.
     */
    private function banner(): void
    {
        $this->newLine();
        $this->components->info('Evitan Plus — Provisioning Console');

        $admin = config('database.connections.pgsql_admin');

        $this->components->twoColumnDetail('Admin', is_array($admin)
            ? sprintf('%s @ %s:%s', $admin['username'] ?? '—', $admin['host'] ?? '—', $admin['port'] ?? '—')
            : '<fg=red>not configured</>');

        try {
            $branch = SupabaseEndpoint::forBranch();
            $this->components->twoColumnDetail('Branch', $branch->host.':'.$branch->port);
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Branch', '<fg=red>'.$e->getMessage().'</>');
        }

        $this->online = $this->reachable();

        if (! $this->online) {
            $this->components->twoColumnDetail('Trail', '<fg=red>unknown — not connected</>');

            return;
        }

        $this->components->twoColumnDetail('Trail', $this->trailReady()
            ? SchemaName::CONSOLE.' ready'
            : '<fg=yellow>'.SchemaName::CONSOLE.' missing — Setup ▸ Create the console schema</>');
    }

    private function reachable(): bool
    {
        try {
            DB::connection('pgsql_admin')->select('select 1');

            return true;
        } catch (Throwable $e) {
            // Said in full rather than reduced to "offline": a wrong host, a
            // wrong port and a wrong pooler username are three different
            // mistakes with three different fixes, and the driver's own message
            // is what distinguishes them. README §5 keys its table off these.
            $this->newLine();
            $this->components->error('The admin connection is not answering: '.$e->getMessage());
            $this->components->bulletList([
                'Check the CONSOLE_DB_* values in console/.env, single-quoted (README §1).',
                'The admin connection needs the SESSION endpoint on 5432, never 6543.',
                'Setup ▸ Check both Supabase endpoints runs the full diagnosis.',
            ]);

            return false;
        }
    }

    /**
     * Is the provisioning trail migrated?
     *
     * This used to ask information_schema by hand, because isReady() reached
     * its answer through SchemaConnection::pinConsole() and CREATED the schema
     * on the way — right for a preflight about to write, wrong for a banner.
     * isReady() is read-only now, so the shared preflight is the honest source
     * and the hand-rolled query is gone.
     */
    private function trailReady(): bool
    {
        return (new ProvisioningAudit)->isReady();
    }

    /** The businesses this project holds, plus the actions that need no business. */
    private function mainMenu(): string
    {
        $options = [];

        if ($this->online) {
            try {
                foreach (BusinessDirectory::all() as $business) {
                    $options['schema:'.$business['schema']] = $this->businessLabel($business);
                }

                $options['provision'] = 'Provision a new business';
            } catch (Throwable $e) {
                $this->components->error('Could not list businesses: '.$e->getMessage());

                $this->online = false;
                $options = [];
            }
        }

        $options['setup'] = 'Setup & diagnostics';
        $options['exit'] = 'Exit';

        return (string) select(
            label: 'Provisioning Console',
            options: $options,
            scroll: 15,
            hint: $this->online
                ? 'Select a business to see its branches.'
                : 'Not connected — only setup and diagnostics are available.',
        );
    }

    /**
     * @param  array{schema: string, business_id: string|null, name: string, branches: int, provisioned: bool}  $business
     */
    private function businessLabel(array $business): string
    {
        if (! $business['provisioned']) {
            return $this->pad($business['schema'], 40).'not provisioned';
        }

        return $this->pad($business['name'], 32)
            .$this->pad($this->shortSchema($business['schema']), 18)
            .$business['branches'].($business['branches'] === 1 ? ' branch' : ' branches');
    }

    /**
     * The branches of one business, and the two things you can do to the
     * business itself. A schema that is not a provisioned business — a
     * schema:migrate that died partway, or a leftover biz_test_* fixture — has
     * no branches to show and only one useful action, so it degrades rather
     * than erroring.
     */
    private function businessScreen(string $schema): void
    {
        while (true) {
            $this->newLine();

            try {
                if (! BranchDirectory::isProvisionedBusiness($schema)) {
                    if ($this->halfBuiltScreen($schema)) {
                        continue;
                    }

                    return;
                }

                $this->businessHeader($schema);

                $states = BranchState::forSchema($schema, new CredentialIssuance);
            } catch (Throwable $e) {
                $this->components->error("Could not read [{$schema}]: ".$e->getMessage());

                return;
            }

            $options = [];

            foreach ($states as $state) {
                $options['branch:'.$state->id()] = $this->branchLabel($state);
            }

            $options['add'] = 'Add a branch';
            $options['upgrade'] = 'Upgrade this schema to the latest migrations';
            $options['regrant'] = 'Re-apply branch grants to every role in this schema';
            $options['back'] = 'Back';

            // Stated where the action is taken, not only in the runbook. This
            // caveat used to read "upgrading does not re-apply branch grants" —
            // true from M1 to M8, and the reason a migration that added a table
            // could leave every till reporting "Sync stuck" until somebody
            // remembered a second command. M9 folded the re-grant into
            // schema:migrate, so what is left to warn about is the version
            // floor: raising it freezes every branch below it, and this screen
            // is the last place anyone is thinking about that.
            $upgradeCaveat = 'Branch grants are re-applied automatically if the schema advances. '
                .'A migration that raises the minimum app version freezes every branch below it — '
                .'they keep selling, but nothing syncs until each device is updated.';

            $choice = (string) select(
                label: 'Branches',
                options: $options,
                scroll: 15,
                hint: $states === [] ? 'This business has no branches yet.' : 'Select a branch to act on it.',
            );

            match (true) {
                $choice === 'back' => null,
                $choice === 'add' => $this->dispatch('branch:provision', ['--schema' => $schema]),
                $choice === 'upgrade' => $this->upgrade($schema, $upgradeCaveat),
                // No confirmation: it changes no password, issues no file and
                // removes nothing an operator could want back. Re-running it is
                // running it once.
                $choice === 'regrant' => $this->dispatch('schema:reapply-grants', ['--schema' => $schema]),
                default => $this->branchScreen($schema, substr($choice, strlen('branch:'))),
            };

            if ($choice === 'back') {
                return;
            }
        }
    }

    /**
     * Confirmed rather than dispatched straight: a migration can raise the
     * minimum app version, and that freezes the sync of every branch below it
     * until each device is updated. Nothing is lost and nothing stops selling,
     * but it is not an outcome to arrive at by one keystroke.
     */
    private function upgrade(string $schema, string $caveat): void
    {
        $this->newLine();
        $this->components->warn($caveat);

        if (confirm(label: "Upgrade [{$schema}] now?", default: false)) {
            $this->dispatch('schema:migrate', ['--schema' => $schema]);
        }
    }

    /** True to stay on this schema, false to go back. */
    private function halfBuiltScreen(string $schema): bool
    {
        $this->components->warn("[{$schema}] is not a provisioned business — it has no schema_meta, or no branches table.");
        $this->components->bulletList([
            'A schema:migrate that never ran, or died partway, leaves this; running it is the fix.',
            'The test suite\'s biz_test_* fixtures also look like this (README §7).',
        ]);

        $choice = (string) select(label: $schema, options: [
            'upgrade' => 'Migrate this schema',
            'back' => 'Back',
        ]);

        if ($choice === 'back') {
            return false;
        }

        $this->dispatch('schema:migrate', ['--schema' => $schema]);

        return true;
    }

    private function businessHeader(string $schema): void
    {
        $this->components->info(BranchDirectory::businessName($schema));
        $this->components->twoColumnDetail('Schema', $schema);

        $businessId = null;

        try {
            $businessId = SchemaName::toBusinessUuid($schema);
        } catch (Throwable) {
            // A biz_ schema that encodes no UUID; --schema still targets it.
        }

        $this->components->twoColumnDetail('Business id', $businessId ?? '—');

        $version = BusinessDirectory::version($schema);

        if ($version === null) {
            $this->components->twoColumnDetail('Schema version', '<fg=yellow>unreadable</>');

            return;
        }

        $this->components->twoColumnDetail(
            'Schema version',
            $version['schema_version'].'  ·  min app '.$version['min_app_version'],
        );
        $this->components->twoColumnDetail('Applied at', $version['applied_at']);
    }

    private function branchLabel(BranchState $state): string
    {
        return $this->pad($state->code(), 8)
            .$this->pad($state->name(), 26)
            .$this->pad($state->credential, 11)
            // "gen 3" and "gen 3 (revoked)" read as generations; "gen never
            // issued" reads as nothing at all. Only a bare number takes the word.
            .(ctype_digit($state->generation) ? 'gen '.$state->generation : $state->generation);
    }

    /**
     * One branch, and the actions its role's current state allows.
     *
     * Re-read on every pass rather than cached: a rotate changes the generation
     * and a revoke changes which actions exist, and the operator returns here
     * immediately afterwards expecting to see it.
     */
    private function branchScreen(string $schema, string $branchId): void
    {
        while (true) {
            $this->newLine();

            try {
                $issuance = new CredentialIssuance;
                $state = BranchState::for(
                    BranchDirectory::resolve($schema, $branchId, null),
                    $issuance,
                    $issuance->isReady(),
                );
            } catch (Throwable $e) {
                $this->components->error('Could not read the branch: '.$e->getMessage());

                return;
            }

            $this->components->info($state->name().' ['.$state->code().']');
            $this->components->twoColumnDetail('Branch id', $state->id());
            $this->components->twoColumnDetail('Postgres role', $state->role);
            $this->components->twoColumnDetail('Credential', $state->credential);
            $this->components->twoColumnDetail('Generation', $state->generation);

            $choice = (string) select(
                label: 'Actions',
                options: [...self::actionsFor($state->canLogin), 'back' => 'Back'],
                hint: 'Each action confirms before it does anything.',
            );

            if ($choice === 'back') {
                return;
            }

            // --schema and --branch, never --branch-code: the exact identifiers
            // the menu already holds, so the command has nothing to look up and
            // nothing to get wrong.
            $target = ['--schema' => $schema, '--branch' => $branchId];

            match ($choice) {
                'rotate' => $this->dispatch('branch:rotate', $target),
                'recreate' => $this->dispatch('branch:rotate', [...$target, '--recreate' => true]),
                'disable' => $this->dispatch('branch:disable', $target),
                'enable' => $this->dispatch('branch:enable', $target),
                'revoke' => $this->dispatch('branch:revoke', $target),
                default => null,
            };
        }
    }

    private function setupScreen(): void
    {
        while (true) {
            $this->newLine();

            $choice = (string) select(label: 'Setup & diagnostics', options: [
                'console:ping' => 'Check both Supabase endpoints and admin capabilities',
                'console:migrate' => 'Create or upgrade the console schema ('.SchemaName::CONSOLE.')',
                'back' => 'Back',
            ]);

            if ($choice === 'back') {
                return;
            }

            $this->dispatch($choice);
        }
    }

    /**
     * Run the real command, in this terminal, with nothing suppressed.
     *
     * No --force, ever. Command::context() carries --ansi/--no-ansi/--quiet/
     * --verbose and interactivity into the child's input, so the child's own
     * prompts and confirmations happen here exactly as they do on the CLI.
     *
     * A throw is caught and shown rather than unwinding the menu: the commands
     * already report their own failures: what reaches here is the unexpected
     * kind, and the operator should land back on a screen, not at a shell.
     *
     * @param  array<string, mixed>  $options
     */
    private function dispatch(string $command, array $options = []): void
    {
        $this->newLine();

        // Forwarded, not dropped. --actor is what stamps the provisioning trail
        // with who acted; the menu is the documented way to operate this tool,
        // so without this every audit row produced the intended way carried the
        // derived <os-user>@<hostname> and the option was unreachable.
        // Only where it is declared: Symfony throws on an option a command does
        // not define, and the two migrate commands and console:ping write no
        // audit rows and take none.
        $actor = $this->option('actor');

        if (is_string($actor) && trim($actor) !== '' && in_array($command, self::AUDITED, true)) {
            $options['--actor'] = trim($actor);
        }

        try {
            $this->call($command, $options);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Pad to a column width by CHARACTERS, not bytes.
     *
     * Laravel Prompts renders select() labels through truncate() and dim() only
     * — never through Symfony's formatter — so a <fg=green> tag would print
     * literally and its width would be counted. Labels here are therefore plain
     * text aligned by hand; the colour lives in the headers above, which are
     * written with $this->components and do go through the formatter.
     */
    private function pad(string $text, int $width): string
    {
        if (mb_strlen($text) > $width - 1) {
            $text = mb_substr($text, 0, $width - 2).'…';
        }

        return $text.str_repeat(' ', max(1, $width - mb_strlen($text)));
    }

    /** biz_ plus 32 hex is 36 characters of noise; the first few identify it. */
    private function shortSchema(string $schema): string
    {
        return mb_strlen($schema) > 15 ? mb_substr($schema, 0, 14).'…' : $schema;
    }
}
