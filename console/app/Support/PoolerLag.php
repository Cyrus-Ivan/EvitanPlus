<?php

namespace Provisioning\Support;

use Throwable;

/**
 * Recognises "the role is fine, Supabase's pooler has not caught up yet".
 *
 * ## The measurement behind this class
 *
 * Supavisor keeps warm server connections keyed by username. Dropping a role
 * and creating one with the SAME name leaves it holding sessions bound to the
 * old role's OID, and it hands those to new clients — which fail with
 * `invalid role OID: <n>` even though the credential is perfectly valid.
 * Measured against a live Supabase project (2026-07-27):
 *
 *   provision (a name the pooler has never seen)  usable immediately
 *   rotate (ALTER ROLE ... PASSWORD)              usually immediate, but see below
 *   disable (NOLOGIN alone)                       NOT enforced — still connecting after 90s
 *   disable (NOLOGIN + terminate backends)        immediate
 *   enable (LOGIN)                                restored immediately
 *   re-create (DROP then CREATE)                  ~126s of `invalid role OID`
 *
 * The disable rows are why BranchRole::setLogin terminates the role's sessions:
 * NOLOGIN on its own left a "disabled" branch connecting indefinitely, because
 * the pooler satisfied new clients from its warm pool without re-authenticating.
 *
 * Rotation is listed as "usually immediate" rather than "immediate" on purpose.
 * An isolated probe measured it settling instantly; the test suite then caught
 * it failing with `password authentication failed` on a role that had several
 * warm connections. So the retry below covers rotation too.
 *
 * ## What this class is and is not responsible for
 *
 * It decides how long BranchIssuer keeps RETRYING, and nothing more. It does
 * not decide whether a failed verification rolls back — that rule is stricter
 * and has a different reason: a re-issue never rolls back, because by then the
 * role holds a secret whose only copy is the credentials file, and discarding
 * it would strand the branch permanently. See BranchIssuer.
 *
 * So even a verification failure that is NOT pooler lag keeps the artifacts on
 * a re-issue. What this class buys is that most of these never become a
 * `verified => false` at all — waiting turns them into a true, because the
 * credential was always valid. §9's delivery model is a fresh AppImage plus the
 * JSON, which takes far longer than two minutes to reach a device, so the
 * window is invisible in the field.
 */
final class PoolerLag
{
    /**
     * The three wordings the pooler produces while its cache is stale.
     *
     * `invalid role OID` is unambiguous — it can only mean a cached session is
     * bound to a role that no longer exists under a name that now does.
     * `(EAUTHQUERY) user not found in the database` is Supavisor's own message
     * when its auth query has not yet seen the new role. The plain
     * authentication failure is included because the same cache produces it in
     * the same window.
     *
     * Used only to word the progress message correctly — "the pooler is still
     * serving the previous role" versus a generic retry — so a false positive
     * costs a slightly wrong line of output, not a wrong decision.
     */
    public static function matches(Throwable $e): bool
    {
        return (bool) preg_match(
            '/invalid role OID|EAUTHQUERY|user not found in the database|password authentication failed/i',
            $e->getMessage(),
        );
    }
}
