<?php

namespace Tests\Feature\Sync;

use App\Models\Instance;
use App\Models\User;
use App\Support\Settings\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which business and which branch this device is (M11.5).
 *
 * A shared prop rather than a controller's, and the login screen is the reason:
 * the branch code prefixes every receipt this till prints, so "which branch is
 * this?" has to be answerable by someone who cannot get past the login form —
 * which is exactly the person on the phone asking for help.
 */
class DeviceIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_names_the_business_and_the_branch(): void
    {
        app(BusinessSettings::class)->update(['business_name' => 'Acme Sari-Sari']);

        $this->assertGuest();

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('identity.business', 'Acme Sari-Sari')
                ->where('identity.branch', 'Test Branch')
                ->where('identity.code', 'TST')
            );
    }

    public function test_an_authenticated_screen_carries_the_same_identity(): void
    {
        app(BusinessSettings::class)->update(['business_name' => 'Acme Sari-Sari']);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('pos'))
            ->assertInertia(fn ($page) => $page
                ->where('identity.business', 'Acme Sari-Sari')
                ->where('identity.code', 'TST')
            );
    }

    /**
     * A device the wizard has not finished with has no branch to name. It is
     * funnelled to /setup, which introduces itself — so the prop is null rather
     * than a half-filled identity nothing would know how to render.
     */
    public function test_a_device_that_was_never_set_up_has_no_identity(): void
    {
        Instance::query()->delete();

        $this->get(route('setup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('identity', null));
    }
}
