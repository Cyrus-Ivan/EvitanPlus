<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Provisioning\Console\Commands\ConsoleRunCommand;
use Tests\CredentialFreeTestCase;

/**
 * The menu's two decisions that can be wrong without a database.
 *
 * ## Why the navigation itself is not driven here
 *
 * Laravel Prompts can simulate key presses through Prompt::fake(), but that
 * helper mocks the terminal with Mockery, which this app does not have and which
 * is not worth adding for one test. The alternative — the QuestionHelper
 * fallback — is unreachable by construction: ConfiguresPrompts turns the
 * fallback on precisely when the input is non-interactive, which is the one case
 * this command refuses outright.
 *
 * That leaves select() plumbing untested, which is framework code, and puts the
 * two things that carry real consequence in reach: the refusal (a scripted
 * caller must get a pointer, never a hung prompt) and the state gating (offering
 * "rotate" on a role that is not there, or "re-create" on one that is, is how an
 * operator ends up reading BranchRotateCommand's refusal instead of doing work).
 */
class ConsoleRunCommandTest extends CredentialFreeTestCase
{
    public function test_it_refuses_when_there_is_nobody_to_prompt(): void
    {
        // Stated as a flag rather than inferred from the environment: Symfony's
        // configureIO also turns interactivity off when stdin is not a tty, but
        // whether a test runner HAS a tty depends on how it was launched, and a
        // test that passes or fails on that is testing the harness.
        $exit = Artisan::call('console:run', ['--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('nobody to prompt', $output);
        $this->assertStringContainsString('console/README.md', $output);
    }

    public function test_a_live_role_can_be_rotated_disabled_or_revoked_but_never_recreated(): void
    {
        $actions = ConsoleRunCommand::actionsFor(true);

        $this->assertSame(['rotate', 'disable', 'revoke'], array_keys($actions));
    }

    public function test_a_disabled_role_is_offered_enable_rather_than_disable(): void
    {
        $actions = ConsoleRunCommand::actionsFor(false);

        $this->assertSame(['enable', 'rotate', 'revoke'], array_keys($actions));
    }

    /**
     * A branch whose role was dropped by branch:revoke. Only one act applies,
     * and offering "rotate" here would send the operator into
     * BranchRotateCommand::modeProblem's refusal rather than into the work.
     */
    public function test_a_missing_role_is_offered_only_re_creation(): void
    {
        $actions = ConsoleRunCommand::actionsFor(null);

        $this->assertSame(['recreate'], array_keys($actions));
    }

    /**
     * The glossary's rule is that rotate and re-create are one act under two
     * starting conditions, told apart by an explicit statement of intent. The
     * menu keeps that by never showing both at once — so no screen can leave an
     * operator believing grants were preserved when they were rebuilt.
     */
    public function test_rotate_and_recreate_are_never_offered_together(): void
    {
        foreach ([true, false, null] as $canLogin) {
            $actions = ConsoleRunCommand::actionsFor($canLogin);

            $this->assertFalse(
                isset($actions['rotate']) && isset($actions['recreate']),
                'Both rotate and recreate were offered for canLogin='.var_export($canLogin, true),
            );
        }
    }

    /** "Revoke" is the word (docs/glossary.md §7); "delete" is the wrong reading. */
    public function test_the_destructive_action_is_never_called_delete(): void
    {
        $labels = implode(' ', ConsoleRunCommand::actionsFor(true));

        $this->assertStringContainsString('Revoke the role', $labels);
        $this->assertStringNotContainsString('Delete', $labels);
        $this->assertStringContainsString('the branch and its history stay', $labels);
    }
}
