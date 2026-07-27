<?php

namespace Provisioning\Support;

/**
 * Who performed a console action.
 *
 * There are no accounts in the console — it is a developer CLI — so the
 * operator is identified by OS user and host, which is what actually
 * distinguishes one developer's laptop from another's. --actor or CONSOLE_ACTOR
 * override it for CI or a shared jump box.
 *
 * Shared by ProvisioningAudit and CredentialIssuance so the two records of the
 * same command name the same operator. When they disagreed, the disagreement
 * would look like evidence of something rather than like the copy-paste it was.
 */
final class OperatorIdentity
{
    public static function resolve(?string $actor = null): string
    {
        foreach ([$actor, env('CONSOLE_ACTOR')] as $override) {
            if (is_string($override) && trim($override) !== '') {
                return trim($override);
            }
        }

        $user = get_current_user();

        if ($user === '') {
            $user = (string) (getenv('USER') ?: 'unknown');
        }

        return $user.'@'.(gethostname() ?: 'unknown-host');
    }
}
