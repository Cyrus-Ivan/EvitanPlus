<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Instance;
use App\Support\Sync\BranchRoster;
use App\Support\Sync\OnlineRead;
use Illuminate\Database\Connection;

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
            report: false,
        );
    }
}
