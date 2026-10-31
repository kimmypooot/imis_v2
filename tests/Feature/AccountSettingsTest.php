<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_index_redirects_to_the_password_section(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/account')
            ->assertRedirect('/account/password');
    }

    public function test_guests_cannot_open_account_settings(): void
    {
        $this->get('/account')->assertRedirect('/login');
    }

    public function test_every_section_is_reachable_by_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['/account/password', '/account/notifications', '/my/record-access', '/my/feedback'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
