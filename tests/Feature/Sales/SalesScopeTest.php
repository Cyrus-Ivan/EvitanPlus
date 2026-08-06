<?php

namespace Tests\Feature\Sales;

use App\Models\Instance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `scope` parameter on the sales screen (M12) — who may ask for other
 * branches' sales, and what a device that cannot reach them shows.
 *
 * What this file does NOT do is prove the consolidated READ. No test device
 * holds a sealed branch credential, so `OnlineRead` cannot open a connection
 * here and never will; and the dialect differences the remote query turns on —
 * `ilike`, offset-carrying window bounds, `numeric` as an exact string — are
 * exactly what a SQLite stand-in cannot reproduce. That proof is
 * `tests/Integration/ConsolidatedSalesTest.php`, against real Postgres.
 *
 * What IS provable here is the half that fails silently if it breaks: the
 * permission boundary, and the fallback. An Owner whose connection is down must
 * be TOLD these are one branch's sales — not shown them under the heading they
 * asked for.
 */
class SalesScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_branch_local_list_is_the_default_and_claims_nothing_about_other_branches(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('canViewAll', true)
                // Null, not false: nothing cross-branch was asked for, so there
                // is nothing to report as unavailable.
                ->where('consolidatedOnline', null)
            );
    }

    /**
     * Unlike reports, which are Owner-only and 403 an Admin, this screen snaps a
     * Cashier back to `local` and serves it. The local list is theirs by right
     * and it is on the same route — refusing it would take away a screen they
     * already have.
     */
    public function test_a_cashier_asking_for_other_branches_is_snapped_back_rather_than_refused(): void
    {
        $cashier = User::factory()->cashier()->create();

        foreach (['all', '00000000-0000-0000-0000-00000000e15e'] as $scope) {
            $this->actingAs($cashier)
                ->get(route('sales', ['scope' => $scope]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('scope', 'local')
                    ->where('canViewAll', false)
                    // Not even offered the picker, so no read was attempted.
                    ->where('branches', [])
                    ->where('consolidatedOnline', null)
                );
        }
    }

    /**
     * The same branch, read better: `local` works offline and includes sales this
     * till has not pushed yet. The picker offers it only as "this branch" for the
     * same reason, so its id is a value the control cannot display.
     */
    public function test_this_branchs_own_id_is_the_local_scope(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales', ['scope' => Instance::current()->branch_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', null)
            );
    }

    public function test_an_admin_offline_gets_this_branch_and_is_told_so(): void
    {
        // No credential is sealed on a test device, so the online read cannot
        // happen. The screen must fall back rather than fail — and must not echo
        // back the scope it could not serve.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales', ['scope' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'local')
                ->where('consolidatedOnline', false)
            );
    }

    public function test_every_local_row_is_stamped_with_this_branch(): void
    {
        Transaction::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales'))
            ->assertInertia(fn ($page) => $page
                // Read off `instance`, not off the row: the local table has no
                // branch_id and every row in it is this device's.
                ->where('transactions.data.0.branch', Instance::current()->branch_code)
            );
    }

    public function test_a_remote_sale_asked_for_offline_returns_to_the_list_and_says_why(): void
    {
        $transaction = Transaction::factory()->create();

        // Not a 404 and not a 500: the id may be perfectly good, and the reason
        // it could not be opened is the network.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales.show', ['transaction' => $transaction->id, 'scope' => 'all']))
            ->assertRedirect(route('sales', ['scope' => 'all']))
            ->assertSessionHas('toast.type', 'error');
    }

    public function test_a_local_sale_still_opens_writable(): void
    {
        $transaction = Transaction::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales.show', $transaction->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('readOnly', false)
                ->where('branch', null)
            );
    }

    public function test_an_unknown_sale_in_the_local_scope_is_still_a_404(): void
    {
        // Relaxing the route binding to a string must not turn a bad receipt id
        // into something softer than it was.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('sales.show', '00000000-0000-0000-0000-0000000004a4'))
            ->assertNotFound();
    }
}
