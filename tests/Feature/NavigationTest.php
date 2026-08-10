<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every navigation section. Role-based authorization (Phase 3) is exercised
     * in AuthorizationTest; here we only assert each section is reachable by a
     * user with full access (the Owner).
     *
     * @return array<string, array{string}>
     */
    public static function sectionRoutes(): array
    {
        return [
            'pos' => ['pos'],
            'sales' => ['sales'],
            // Two sections since M13: the catalog and the shelf.
            'items' => ['items.index'],
            'categories' => ['categories.index'],
            'inventory' => ['inventory'],
            'stock-updates' => ['stock-updates.index'],
            'stock-history' => ['stock-history.index'],
            'reports' => ['reports'],
            'users' => ['users'],
            'login-history' => ['login-history'],
            'audit-logs' => ['audit-logs'],
            'business-settings' => ['business-settings'],
            // Settings pages live under the user menu rather than the sidebar;
            // appearance is closure-rendered, so this is its only coverage.
            'settings-profile' => ['profile.edit'],
            'settings-password' => ['password.edit'],
            'settings-appearance' => ['appearance'],
        ];
    }

    #[DataProvider('sectionRoutes')]
    public function test_guests_are_redirected_to_login(string $routeName)
    {
        // A user must exist — on a fresh install the setup wizard answers.
        User::factory()->cashier()->create();

        $this->get(route($routeName))->assertRedirect(route('login'));
    }

    #[DataProvider('sectionRoutes')]
    public function test_the_owner_can_visit_each_section(string $routeName)
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get(route($routeName))->assertOk();
    }
}
