<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * No list-shaped query parameter may take a screen down.
 *
 * Every filter on every list screen is normalised by a small private helper that
 * begins by casting the raw parameter to a string — `(string) $value`, or
 * `trim((string) $request->query('search', ''))`. That is fine for the strings a
 * form sends and wrong for what a URL can carry: `?search[]=x` hands the helper
 * an **array**, `(string) []` raises `Warning: Array to string conversion`, and
 * `HandleExceptions` turns any warning inside the error-reporting mask into an
 * `ErrorException`. The result is a 500 — a dead screen from a hand-edited URL,
 * on roughly every list in the product.
 *
 * The fix is uniform and so is this test: a non-string is not a filter, so it
 * reads as **no filter at all** rather than as an error. Sibling of
 * {@see BranchScopeContractTest}, which pins the same rule for `scope`
 * specifically — that one is separate because scope decides which BRANCHES are
 * read, so its fallback direction carries weight these do not.
 *
 * `(int)` casts are deliberately absent from this list: casting an array to int
 * is silent in PHP, so the month/year filters were never exposed.
 */
class ArrayQueryParameterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route name, the parameter, and the lowest role that may open the screen.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function filterParameters(): array
    {
        return [
            'sales search' => ['sales', 'search', 'cashier'],
            'sales payment type' => ['sales', 'payment_type', 'cashier'],
            'sales user' => ['sales', 'user', 'cashier'],
            'sales date from' => ['sales', 'date_from', 'cashier'],
            'sales date to' => ['sales', 'date_to', 'cashier'],
            'reports period' => ['reports', 'period', 'owner'],
            'reports date' => ['reports', 'date', 'owner'],
            'reports chart series' => ['reports', 'by', 'owner'],
            'login history event' => ['login-history', 'event', 'admin'],
            'employee performance user' => ['employee-performance', 'user', 'admin'],
            'pending stocks kind' => ['stock-updates.index', 'kind', 'cashier'],
            'stock history movement' => ['stock-history.index', 'movement', 'cashier'],
            'stock history search' => ['stock-history.index', 'search', 'cashier'],
            'inventory search' => ['inventory', 'search', 'cashier'],
            'inventory status' => ['inventory', 'status', 'cashier'],
            'items search' => ['items.index', 'search', 'cashier'],
            'items status' => ['items.index', 'status', 'cashier'],
            'users search' => ['users', 'search', 'owner'],
            'users status' => ['users', 'status', 'owner'],
            'pos search' => ['pos', 'search', 'cashier'],
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

    #[DataProvider('filterParameters')]
    public function test_a_list_shaped_filter_is_ignored_rather_than_fatal(string $route, string $parameter, string $role): void
    {
        $actor = $this->actor($role);

        foreach ([['x'], ['a', 'b'], ['nested' => ['deep']]] as $value) {
            $this->actingAs($actor)
                ->get(route($route, [$parameter => $value]))
                ->assertOk();
        }
    }
}
