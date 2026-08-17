<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Instance;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * The branch roster as a scope picker offers it — shared by every screen that
 * takes a `scope` parameter (`docs/glossary.md` §"Branch scope": reports since
 * M11, login history since M11.5, stock history and pending stocks since M11.8,
 * sales and inventory stock since M12).
 *
 * ## Why the roster is read on every render, not only a consolidated one
 *
 * Both screens used to read it inside their consolidated online read, which is
 * the cheapest place for it — the roster and the page of rows share one
 * connection. But it left the picker with nothing to list in the DEFAULT scope,
 * so naming one branch meant first asking for all of them. The roster is
 * therefore read on its own whenever a viewer who may widen the scope opens one
 * of these screens, at the price of one short online read per page load. It is
 * as available as the feature it feeds: offline the picker cannot offer another
 * branch, and offline it does not.
 *
 * ## Why this branch is never in the list
 *
 * The picker's first entry IS this branch — `local`, the SQLite mirror. Offering
 * its id as well would be the same branch twice, and the worse of the two reads:
 * `local` works offline and includes rows this device has not pushed yet, while
 * `<own id>` is whatever reached Postgres. They coincide only while the MVP
 * holds one device per branch (`docs/glossary.md` §"Till"), which is exactly the
 * rule that makes the roster entry redundant rather than a second answer. So it
 * is stripped here, and {@see self::isThisBranch()} snaps a hand-typed or
 * bookmarked `?scope=<own id>` back to `local` — a value the picker does not
 * offer is a value it cannot display.
 */
trait OffersBranchScope
{
    /**
     * This branch, served from the local SQLite mirror. The default everywhere,
     * the only scope that works offline, and the only one that includes rows the
     * device has not pushed yet.
     */
    private const SCOPE_LOCAL = 'local';

    /**
     * Every branch, read live from the business database.
     *
     * Held here rather than per-screen because six controllers each carried a
     * private copy of this pair and one of them ({@see InventoryController})
     * carried only half — it never RETURNS `all`, but it still has to recognise
     * the word in order to snap it back.
     */
    private const SCOPE_ALL = 'all';

    /**
     * The branches this screen's scope picker may offer: every branch but this
     * one, ordered by code.
     *
     * @param  ?list<array{id: string, code: string, name: string}>  $fromRead
     *                                                                          The roster a consolidated read already returned this request, or
     *                                                                          null when no online read was attempted. Non-null is reused as-is,
     *                                                                          INCLUDING an empty list from a failed read: a second attempt would
     *                                                                          fail the same way and pay the probe timeout twice on one page load.
     * @param  bool  $eligible  Whether this viewer, on this device, may widen the
     *                          scope at all. False reads nothing.
     * @return list<array{id: string, code: string, name: string}>
     */
    private function pickerBranches(OnlineRead $read, ?array $fromRead, bool $eligible): array
    {
        if (! $eligible) {
            return [];
        }

        $branches = $fromRead ?? $this->readRoster($read);
        $own = Instance::current()?->branch_id;

        return array_values(array_filter(
            $branches,
            fn (array $branch): bool => $branch['id'] !== $own,
        ));
    }

    /**
     * Resolve a requested `scope` into one this screen will actually read.
     *
     * The shared branch scope vocabulary (`docs/glossary.md` §"Branch scope"):
     * `local`, `all`, or a branch id. Six screens had a near-identical private
     * copy of this and disagreed in two ways that mattered, which is the whole
     * reason it lives here now:
     *
     * - **An unusable value narrows, it never widens.** Four of the copies ended
     *   `… ? $scope : SCOPE_ALL`, so `?scope=nonsense` silently became "all
     *   branches" — a typo widening the reader's view, and the echoed-back value
     *   is the only thing on screen that says so. The fallback is `local`
     *   everywhere now: the narrow answer is the one the viewer is entitled to
     *   whatever went wrong.
     * - **A non-string is not a scope.** Every copy began `(string) $value` on a
     *   raw query parameter, so `?scope[]=1` raised "Array to string conversion",
     *   which Laravel's error handler turns into an `ErrorException` — a 500 from
     *   a crafted URL on all six screens.
     *
     * @param  bool  $canViewAll  Whether this viewer may read past their own branch
     *                            at all. False snaps everything to `local` rather
     *                            than refusing: on these screens the local view is
     *                            the viewer's by right and sits on the same route.
     * @param  bool  $allowAll  Whether `all` is meaningful here. False on
     *                          `/inventory`, where a wider scope adds rows to a
     *                          NUMBER rather than to a list and a business-wide
     *                          total per variant says nothing on its own.
     * @param  ?Closure():void  $authorize  Run instead of the `$canViewAll` check
     *                                      when a screen must REFUSE a wider scope
     *                                      rather than narrow it — `/reports`, which
     *                                      is Owner-only and 403s an Admin. Called
     *                                      after the `local` short-circuit and before
     *                                      the own-branch snap, so an Admin naming a
     *                                      branch id still gets the 403 rather than a
     *                                      report that is not the one they asked for.
     */
    private function normalizeScope(
        mixed $value,
        bool $canViewAll = true,
        bool $allowAll = true,
        ?Closure $authorize = null,
    ): string {
        $scope = is_string($value) ? $value : '';

        if ($scope === '' || $scope === self::SCOPE_LOCAL) {
            return self::SCOPE_LOCAL;
        }

        // Decide what was ASKED FOR before deciding whether it is allowed. A
        // value naming neither `all` nor a branch is not a request for either, so
        // it resolves exactly like no scope at all — including on `/reports`,
        // where running the Owner-only gate first would turn a typo into a 403.
        if ($scope !== self::SCOPE_ALL && ! Str::isUuid($scope)) {
            return self::SCOPE_LOCAL;
        }

        if ($authorize !== null) {
            $authorize();
        } elseif (! $canViewAll || ! Instance::isHydrated()) {
            // The hydration half is belt-and-braces: `RequireInitialSetup`
            // redirects an un-hydrated device off every route but the wizard, so
            // nothing should reach here without an identity. Kept because a
            // device with no branch has no business to widen INTO, and that
            // should not depend on a middleware ordering somewhere else.
            return self::SCOPE_LOCAL;
        }

        // This branch's own id is `local` under another name, and the picker
        // offers it only under that one.
        if ($this->isThisBranch($scope)) {
            return self::SCOPE_LOCAL;
        }

        if ($scope === self::SCOPE_ALL) {
            return $allowAll ? self::SCOPE_ALL : self::SCOPE_LOCAL;
        }

        return $scope;
    }

    /**
     * Is this scope just another name for `local`?
     */
    private function isThisBranch(string $scope): bool
    {
        return $scope === Instance::current()?->branch_id;
    }

    /**
     * The roster on its own, or an empty list when it cannot be reached.
     *
     * Rescued rather than surfaced: a picker that could not be filled is not a
     * reason to fail a screen whose default scope is served entirely from local
     * SQLite. The picker says so itself instead.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    private function readRoster(OnlineRead $read): array
    {
        return rescue(
            fn (): array => $read->run(
                fn (Connection $remote): array => array_values(BranchRoster::read($remote)),
                'The branch list',
            ),
            [],
            report: OnlineRead::reportUnlessOffline(),
        );
    }
}
