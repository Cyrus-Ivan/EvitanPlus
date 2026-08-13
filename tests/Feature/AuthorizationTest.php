<?php

namespace Tests\Feature;

use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Variant;
use App\Support\Auth\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes every authenticated user (Cashier and up) may reach.
     *
     * @return array<string, array{string}>
     */
    public static function cashierRoutes(): array
    {
        return [
            'pos' => ['pos'],
            'sales' => ['sales'],
            'inventory' => ['inventory'],
            'login-history' => ['login-history'],
        ];
    }

    /**
     * Routes gated at Admin (Admins and the Owner).
     *
     * @return array<string, array{string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'reports' => ['reports'],
            'users' => ['users'],
            'audit-logs' => ['audit-logs'],
        ];
    }

    /**
     * Owner-only routes.
     *
     * @return array<string, array{string}>
     */
    public static function ownerRoutes(): array
    {
        return [
            // Three sections, three URLs — the whole area is owner-only, so a
            // section that forgot its middleware is a hole in the same wall.
            'business-settings' => ['business-settings'],
            'printer-settings' => ['printer-settings'],
            'report-email-settings' => ['report-email-settings'],
        ];
    }

    #[DataProvider('cashierRoutes')]
    public function test_cashier_can_reach_cashier_level_routes(string $route)
    {
        $this->actingAs(User::factory()->cashier()->create());

        $this->get(route($route))->assertOk();
    }

    #[DataProvider('adminRoutes')]
    public function test_cashier_is_forbidden_from_admin_routes(string $route)
    {
        $this->actingAs(User::factory()->cashier()->create());

        $this->get(route($route))->assertForbidden();
    }

    #[DataProvider('ownerRoutes')]
    public function test_cashier_is_forbidden_from_owner_routes(string $route)
    {
        $this->actingAs(User::factory()->cashier()->create());

        $this->get(route($route))->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_admin_can_reach_admin_routes(string $route)
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route($route))->assertOk();
    }

    #[DataProvider('ownerRoutes')]
    public function test_admin_is_forbidden_from_owner_routes(string $route)
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route($route))->assertForbidden();
    }

    #[DataProvider('ownerRoutes')]
    public function test_owner_can_reach_owner_routes(string $route)
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get(route($route))->assertOk();
    }

    public function test_gates_respect_the_role_hierarchy()
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->cashier()->create();

        // Admin-level abilities: Admin and Owner only.
        foreach (['access-reports', 'access-audit-logs', 'manage-users'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability));
            $this->assertTrue(Gate::forUser($admin)->allows($ability));
            $this->assertFalse(Gate::forUser($cashier)->allows($ability));
        }

        // Owner-only abilities.
        foreach (['access-settings', 'view-item-cost'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability));
            $this->assertFalse(Gate::forUser($admin)->allows($ability));
            $this->assertFalse(Gate::forUser($cashier)->allows($ability));
        }
    }

    public function test_variant_cost_is_hidden_from_non_owners()
    {
        $variant = new Variant(['cost' => '12.50', 'selling_price' => '20.00']);

        $this->actingAs(User::factory()->owner()->create());
        $this->assertArrayHasKey('cost', $variant->toArray());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertArrayNotHasKey('cost', $variant->toArray());

        $this->actingAs(User::factory()->cashier()->create());
        $this->assertArrayNotHasKey('cost', $variant->toArray());
    }

    public function test_transaction_item_cost_is_hidden_from_non_owners()
    {
        $item = new TransactionItem(['cost' => '5.00', 'selling_price' => '9.00']);

        $this->actingAs(User::factory()->owner()->create());
        $this->assertArrayHasKey('cost', $item->toArray());

        $this->actingAs(User::factory()->cashier()->create());
        $this->assertArrayNotHasKey('cost', $item->toArray());
    }

    public function test_cost_is_hidden_when_unauthenticated()
    {
        $variant = new Variant(['cost' => '12.50']);

        $this->assertArrayNotHasKey('cost', $variant->toArray());
    }

    public function test_shared_permissions_reflect_the_role()
    {
        $this->actingAs(User::factory()->cashier()->create());

        $this->get(route('pos'))->assertInertia(
            fn ($page) => $page
                ->where('auth.permissions.access-reports', false)
                ->where('auth.permissions.manage-users', false)
                ->where('auth.permissions.view-item-cost', false)
        );

        $this->actingAs(User::factory()->owner()->create());

        $this->get(route('pos'))->assertInertia(
            fn ($page) => $page
                ->where('auth.permissions.access-reports', true)
                ->where('auth.permissions.access-settings', true)
                ->where('auth.permissions.view-item-cost', true)
        );
    }

    public function test_guest_permission_map_is_a_full_false_object_not_an_empty_array()
    {
        $map = Permissions::forUser(null);

        // Every named ability is present and false, so the shared prop always
        // serializes as a keyed object matching Record<Permission, boolean>.
        $this->assertSame(
            array_fill_keys(Permissions::ABILITIES, false),
            $map,
        );
    }
}
