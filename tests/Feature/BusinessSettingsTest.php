<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_the_business_settings_page_with_current_values()
    {
        Setting::create(['key' => 'business_name', 'value' => 'Acme Retail']);

        $response = $this
            ->actingAs(User::factory()->owner()->create())
            ->get(route('business-settings'));

        $response->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('business-settings/business', false)
                ->where('settings.business_name', 'Acme Retail')
                ->where('settings.tin', '')
                // The other two sections are their own URLs and carry their own
                // props; this one must not drag a probe's worth of them along.
                ->missing('printerSettings')
                ->missing('reportEmailSettings')
        );
    }

    public function test_owner_sees_the_printer_section_with_current_values()
    {
        // Not the default (58mm), or a page ignoring the stored row would pass.
        Setting::create(['key' => 'receipt_paper_size', 'value' => '80mm']);

        $response = $this
            ->actingAs(User::factory()->owner()->create())
            ->get(route('printer-settings'));

        $response->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('business-settings/printing', false)
                ->where('printerSettings.receipt_paper_size', '80mm')
                // Device hardware: nothing here is gated on a connection, so
                // this section must not pay for the probe.
                ->missing('online')
        );
    }

    public function test_owner_can_update_business_settings()
    {
        $response = $this
            ->actingAs(User::factory()->owner()->create())
            ->patch(route('business-settings.update'), [
                'business_name' => 'Acme Retail',
                'business_address' => '123 Market St',
                'tin' => '123-456-789',
                'receipt_footer' => 'Thank you for shopping!',
                'timezone' => 'Asia/Singapore',
                'currency_symbol' => '$',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Acme Retail', Setting::where('key', 'business_name')->value('value'));
        $this->assertSame('123 Market St', Setting::where('key', 'business_address')->value('value'));
        $this->assertSame('123-456-789', Setting::where('key', 'tin')->value('value'));
        $this->assertSame('Thank you for shopping!', Setting::where('key', 'receipt_footer')->value('value'));
        $this->assertSame('Asia/Singapore', Setting::where('key', 'timezone')->value('value'));
        $this->assertSame('$', Setting::where('key', 'currency_symbol')->value('value'));
    }

    public function test_timezone_and_currency_symbol_are_validated()
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->patch(route('business-settings.update'), [
                'business_name' => 'Acme Retail',
                'timezone' => 'Mars/Olympus_Mons',
                'currency_symbol' => '₱',
            ])
            ->assertSessionHasErrors('timezone');

        $this->actingAs($owner)
            ->patch(route('business-settings.update'), [
                'business_name' => 'Acme Retail',
                'timezone' => 'Asia/Manila',
                'currency_symbol' => 'too-long',
            ])
            ->assertSessionHasErrors('currency_symbol');
    }

    public function test_business_name_is_required()
    {
        $response = $this
            ->actingAs(User::factory()->owner()->create())
            ->from(route('business-settings'))
            ->patch(route('business-settings.update'), [
                'business_name' => '',
            ]);

        $response->assertSessionHasErrors('business_name');
    }

    public function test_admins_and_cashiers_cannot_update_business_settings()
    {
        foreach (['admin', 'cashier'] as $role) {
            $this
                ->actingAs(User::factory()->{$role}()->create())
                ->patch(route('business-settings.update'), [
                    'business_name' => 'Hijacked',
                ])
                ->assertForbidden();
        }

        $this->assertNull(Setting::where('key', 'business_name')->value('value'));
    }

    public function test_guests_cannot_update_business_settings()
    {
        // A user must exist, else the setup-wizard gate answers first.
        User::factory()->cashier()->create();

        $this
            ->patch(route('business-settings.update'), ['business_name' => 'Anon'])
            ->assertRedirect(route('login'));
    }
}
