<?php

namespace Tests\Feature\Reports;

use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `scope` parameter on the reports screen (M11) — who may ask for figures
 * that reach other branches, and what a device that cannot reach them shows.
 *
 * What this file does NOT do is prove the consolidated ARITHMETIC. No test
 * device has a sealed branch credential, so `OnlineRead` cannot open a
 * connection here and never will; and the SQLite stand-in used elsewhere in the
 * suite cannot run `to_char` or `at time zone`, which is precisely where the
 * consolidated query's correctness lives. That proof is
 * `tests/Integration/ConsolidatedReportTest.php`, against real Postgres.
 *
 * What IS provable here is the half that fails silently if it breaks: the
 * permission boundary, and the fallback. An Owner whose connection is down must
 * be told the figures are one branch's — not shown them under the heading they
 * asked for.
 */
class ConsolidatedReportScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_branch_local_report_is_the_default_and_claims_nothing_about_other_branches(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('chartBy', 'item')
                // Empty here because an Admin may not widen the scope at all, so
                // the picker is not offered one — no read is even attempted.
                ->where('branches', [])
                ->where('branchRows', null)
                // Null, not false: nothing consolidated was asked for, so there
                // is nothing to report as unavailable.
                ->where('consolidatedOnline', null)
            );
    }

    public function test_an_admin_may_not_ask_for_other_branches(): void
    {
        $admin = User::factory()->admin()->create();

        // Refused by the controller, not by route placement — the same route
        // serves the branch-local report an Admin is entitled to.
        $this->actingAs($admin)->get(route('reports', ['scope' => 'all']))->assertForbidden();
        $this->actingAs($admin)
            ->get(route('reports', ['scope' => '00000000-0000-0000-0000-00000000e15e']))
            ->assertForbidden();
        $this->actingAs($admin)->get(route('reports.export', ['scope' => 'all']))->assertForbidden();

        // Including this branch's own id. It resolves to `local`, which the
        // Admin is entitled to — but only AFTER the permission check, so naming
        // a branch id is never a silent downgrade to figures they did not ask
        // for.
        $this->actingAs($admin)
            ->get(route('reports', ['scope' => Instance::current()->branch_id]))
            ->assertForbidden();
    }

    /**
     * The Owner asking for this branch by id gets the local report: it is the
     * same branch, read better (offline-capable, and it includes sales this till
     * has not pushed yet). The picker offers it only as "this branch" for the
     * same reason.
     */
    public function test_this_branchs_own_id_is_the_local_scope(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['scope' => Instance::current()->branch_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('branchRows', null)
                // Not false: no consolidated read was attempted, because there
                // was nothing consolidated about the request.
                ->where('consolidatedOnline', null)
            );
    }

    public function test_a_cashier_may_not_reach_reports_at_all(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('reports', ['scope' => 'all']))
            ->assertForbidden();
    }

    public function test_an_owner_offline_gets_this_branch_and_is_told_so(): void
    {
        // No credential is sealed on a test device, so the online read cannot
        // happen. The screen must fall back rather than fail — and must not
        // echo back the scope it could not serve.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports', ['scope' => 'all', 'by' => 'branch']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('chartBy', 'item')
                ->where('branchRows', null)
                ->where('consolidatedOnline', false)
            );
    }

    public function test_the_owner_permission_is_shared_to_the_frontend(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports'))
            ->assertInertia(fn ($page) => $page->where('auth.permissions.view-consolidated-reports', true));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports'))
            ->assertInertia(fn ($page) => $page->where('auth.permissions.view-consolidated-reports', false));
    }

    public function test_an_offline_consolidated_export_still_streams_this_branch_s_pdf(): void
    {
        // The fallback has to reach the PDF too: an export that 500s because the
        // connection dropped is worse than one that prints the branch it could
        // actually read, so long as the document says which branch that is.
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('reports.export', ['period' => 'daily', 'scope' => 'all']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        // Not the consolidated filename — the figures are not consolidated.
        $this->assertStringNotContainsString('all-branches', (string) $response->headers->get('content-disposition'));
    }
}
