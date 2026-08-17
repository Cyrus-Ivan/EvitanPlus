<?php

namespace Tests\Feature;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The `scope` parameter, as a CONTRACT across every screen that takes one.
 *
 * Six controllers each carried a private near-copy of `normalizeScope()`, and
 * the per-screen tests each pinned their own copy — so the two places the copies
 * disagreed were invisible, because no test ever compared them:
 *
 *  1. **A typo widened the view.** Four copies ended `… ? $scope : SCOPE_ALL`,
 *     so `?scope=nonsense` came back as "all branches" on reports, sales, login
 *     history and stock history, while inventory and pending stocks narrowed to
 *     `local`. A scope parameter that fails OPEN is the wrong way round.
 *  2. **A non-string was a 500.** Every copy began `(string) $value` on a raw
 *     query parameter, so `?scope[]=1` raised "Array to string conversion",
 *     which Laravel's error handler turns into an `ErrorException`. One crafted
 *     URL took out all six screens.
 *
 * ## Why the assertions compare responses instead of reading `scope`
 *
 * The echoed `scope` prop is the scope that was actually READ, after any
 * fallback — and with no Postgres in this suite every wider scope falls back to
 * `local` and says so. So "narrowed to local" and "tried all branches, failed,
 * fell back to local" both echo `local`, and asserting on it alone would pass
 * against the very bug this file exists for.
 *
 * What separates them is whether a consolidated read was ATTEMPTED, which each
 * screen reports in its own prop. {@see self::fingerprint()} takes both, so the
 * assertions can say the thing that actually matters: **an unusable scope must
 * behave exactly like no scope at all**, and a legitimate one must not.
 */
class BranchScopeContractTest extends TestCase
{
    use RefreshDatabase;

    /** A branch that is not this device's. Never reachable — there is no credential. */
    private const OTHER_BRANCH = '00000000-0000-0000-0000-00000000e15e';

    /**
     * Values that name neither `all` nor a branch, including the two shapes that
     * used to be a 500 and a path-traversal-flavoured one for good measure.
     *
     * @return list<mixed>
     */
    private const UNUSABLE = ['nonsense', ['1'], '../../etc/passwd', 'all-branches', '00000000-0000-0000-0000'];

    /**
     * Every screen taking a `scope`: route name, the lowest role that may widen
     * it, whether `all` means anything there, and where the screen echoes the
     * scope it read.
     *
     * `stock-updates.index` is deliberately `cashier` — answering a pending
     * movement is open to whoever is standing at the affected branch, so the page
     * listing them gates on nothing (`docs/glossary.md` §4).
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function scopedScreens(): array
    {
        return [
            //                    route                  role      `all`?  scope prop
            'reports' => ['reports', 'owner', true, 'scope'],
            'sales' => ['sales', 'admin', true, 'scope'],
            'login history' => ['login-history', 'admin', true, 'scope'],
            'stock history' => ['stock-history.index', 'admin', true, 'scope'],
            'inventory' => ['inventory', 'admin', false, 'scope'],
            'pending stocks' => ['stock-updates.index', 'cashier', true, 'filters.scope'],
        ];
    }

    private function actor(string $role): User
    {
        return match ($role) {
            'owner' => User::factory()->owner()->create(),
            'admin' => User::factory()->admin()->create(),
            default => User::factory()->cashier()->create(),
        };
    }

    /**
     * What the screen did, reduced to the two things that distinguish a narrowed
     * scope from an attempted-and-failed wider one: the scope it echoes, and
     * whether it tried to reach the business database.
     *
     * @return array<string, mixed>
     */
    private function fingerprint(TestResponse $response, string $scopeProp): array
    {
        $props = $response->viewData('page')['props'];

        $scope = $scopeProp === 'filters.scope' ? ($props['filters']['scope'] ?? null) : ($props['scope'] ?? null);

        return [
            'scope' => $scope,
            // Null on the screens that never attempted a consolidated read; false
            // when one was attempted and failed. That difference IS the bug.
            'attempted' => $props['consolidatedOnline'] ?? null,
        ];
    }

    /**
     * A value that cannot name a branch is answered with this branch — and is
     * indistinguishable from having sent no scope at all.
     */
    #[DataProvider('scopedScreens')]
    public function test_an_unusable_scope_behaves_exactly_like_no_scope(
        string $route,
        string $role,
        bool $allowsAll,
        string $scopeProp,
    ): void {
        $actor = $this->actor($role);

        $baseline = $this->fingerprint(
            $this->actingAs($actor)->get(route($route))->assertOk(),
            $scopeProp,
        );

        $this->assertSame('local', $baseline['scope'], 'The default scope must be this branch.');

        foreach (self::UNUSABLE as $value) {
            $this->assertSame(
                $baseline,
                $this->fingerprint(
                    $this->actingAs($actor)->get(route($route, ['scope' => $value]))->assertOk(),
                    $scopeProp,
                ),
                'An unusable scope on '.$route.' did not resolve like an absent one: '.json_encode($value),
            );
        }
    }

    /** An explicit `local` and an empty one are the default by another name. */
    #[DataProvider('scopedScreens')]
    public function test_local_and_empty_are_the_default(
        string $route,
        string $role,
        bool $allowsAll,
        string $scopeProp,
    ): void {
        $actor = $this->actor($role);

        foreach (['local', ''] as $value) {
            $this->assertSame(
                'local',
                $this->fingerprint(
                    $this->actingAs($actor)->get(route($route, ['scope' => $value]))->assertOk(),
                    $scopeProp,
                )['scope'],
            );
        }
    }

    /**
     * This branch's own id is `local` under another name.
     *
     * The picker only ever offers it as `local`, so a hand-typed or bookmarked
     * link naming the id would otherwise leave the control showing a value not in
     * its own list — and, on the screens that read live, would pay for a network
     * round trip to fetch what SQLite already holds.
     */
    #[DataProvider('scopedScreens')]
    public function test_this_branchs_own_id_is_the_local_scope(
        string $route,
        string $role,
        bool $allowsAll,
        string $scopeProp,
    ): void {
        if ($role === 'owner') {
            $this->markTestSkipped('Reports refuses a branch-scoped request from an Admin; covered separately.');
        }

        $this->assertSame(
            'local',
            $this->fingerprint(
                $this->actingAs($this->actor($role))
                    ->get(route($route, ['scope' => Instance::current()->branch_id]))
                    ->assertOk(),
                $scopeProp,
            )['scope'],
        );
    }

    /**
     * A legitimate wider scope is NOT treated like an unusable one — the proof
     * that the assertions above can tell the two apart.
     *
     * With no Postgres the read fails and the screen falls back to `local` and
     * says so, which is the documented behaviour; what matters here is that it
     * tried.
     */
    public function test_a_legitimate_wider_scope_is_actually_attempted(): void
    {
        foreach ([['reports', 'owner'], ['sales', 'admin'], ['login-history', 'admin'], ['stock-history.index', 'admin']] as [$route, $role]) {
            $this->assertSame(
                ['scope' => 'local', 'attempted' => false],
                $this->fingerprint(
                    $this->actingAs($this->actor($role))->get(route($route, ['scope' => 'all']))->assertOk(),
                    'scope',
                ),
                $route.' did not attempt the consolidated read it was asked for.',
            );
        }
    }

    /**
     * A role that may not widen is snapped back rather than refused — the local
     * view is theirs by right and it is on the same route. Reports is the one
     * exception and is asserted below.
     */
    #[DataProvider('scopedScreens')]
    public function test_a_role_that_may_not_widen_is_snapped_back(
        string $route,
        string $role,
        bool $allowsAll,
        string $scopeProp,
    ): void {
        if ($role !== 'admin') {
            $this->markTestSkipped('Screen is either Owner-only (refuses) or open to every role.');
        }

        $cashier = $this->actor('cashier');

        foreach (['all', self::OTHER_BRANCH] as $value) {
            $this->assertSame(
                // Never attempted: a Cashier is not offered the picker either.
                ['scope' => 'local', 'attempted' => null],
                $this->fingerprint(
                    $this->actingAs($cashier)->get(route($route, ['scope' => $value]))->assertOk(),
                    $scopeProp,
                ),
            );
        }
    }

    /**
     * `all` is refused on `/inventory` and honoured everywhere else.
     *
     * Inventory is the only screen where a wider scope adds rows to a NUMBER
     * rather than to a list, and a business-wide total per variant says nothing
     * on its own. M12 removed the option from the picker; this is what stops it
     * returning by URL.
     */
    public function test_inventory_never_honours_all_even_by_url(): void
    {
        $this->assertSame(
            ['scope' => 'local', 'attempted' => null],
            $this->fingerprint(
                $this->actingAs($this->actor('admin'))->get(route('inventory', ['scope' => 'all']))->assertOk(),
                'scope',
            ),
        );

        // A named branch, however, is exactly what this screen is for.
        $this->assertSame(
            false,
            $this->fingerprint(
                $this->actingAs($this->actor('admin'))
                    ->get(route('inventory', ['scope' => self::OTHER_BRANCH]))
                    ->assertOk(),
                'scope',
            )['attempted'],
        );
    }

    /**
     * Reports refuses an Admin instead of narrowing, for a branch id as much as
     * for `all` — the 403 comes before the own-branch snap, so an Admin naming a
     * branch never receives figures that are not the ones they asked for.
     */
    public function test_reports_refuses_a_wider_scope_rather_than_narrowing_it(): void
    {
        $admin = $this->actor('admin');

        foreach (['all', self::OTHER_BRANCH, Instance::current()->branch_id] as $scope) {
            $this->actingAs($admin)
                ->get(route('reports', ['scope' => $scope]))
                ->assertForbidden();
        }

        // The branch-local report on the same route is still theirs.
        $this->actingAs($admin)->get(route('reports'))->assertOk();
    }

    /**
     * An unusable scope must not slip past the Owner-only gate on reports by
     * being turned into `all` after it — nor trip the gate on its way to being
     * discarded. A typo is not a request for another branch's money.
     */
    public function test_an_unusable_scope_on_reports_is_neither_widened_nor_a_403(): void
    {
        $this->actingAs($this->actor('admin'))
            ->get(route('reports', ['scope' => 'nonsense']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scope', 'local'));
    }

    /**
     * Pending Stocks is open to a Cashier at every scope, deliberately.
     *
     * It is the one screen where a Cashier may read past their own branch. The
     * work is operational rather than financial — no cost, no profit, no totals,
     * only movements that have not happened yet — and what bounds ANSWERING one
     * is the branch, enforced in `AdjustmentResolver` rather than here. Pinned so
     * the decision is tested rather than only written down.
     */
    public function test_pending_stocks_is_open_to_a_cashier_at_every_scope(): void
    {
        $cashier = $this->actor('cashier');

        foreach (['all', self::OTHER_BRANCH] as $scope) {
            $this->assertSame(
                $scope,
                $this->fingerprint(
                    $this->actingAs($cashier)->get(route('stock-updates.index', ['scope' => $scope]))->assertOk(),
                    'filters.scope',
                )['scope'],
            );
        }
    }
}
