<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_pos()
    {
        // Evitan Plus has no public landing page; the desktop app opens on the
        // POS screen, which in turn redirects guests to the login screen.
        // (A user must exist — on a fresh install the setup wizard answers.)
        User::factory()->cashier()->create();

        $this->get(route('home'))->assertRedirect('/pos');
    }
}
