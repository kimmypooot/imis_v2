<?php

namespace Tests\Feature\Portal;

use App\Models\Application;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migrations register real hub systems; these tests count their own.
        Application::query()->delete();
    }

    public function test_guest_cannot_access_the_portal(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_the_portal(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Portal/Dashboard'));
    }

    public function test_guest_visiting_root_is_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_user_visiting_root_is_redirected_to_the_portal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }

    public function test_portal_renders_with_no_directory_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Dashboard')
            ->where('directory.categories', [])
            ->where('services', []));
    }

    public function test_greeting_shows_the_users_division(): void
    {
        $unit = OrganizationalUnit::factory()->create(['name' => 'Examination Services Division', 'short_name' => 'ESD']);

        $this->actingAs(User::factory()->create(['organizational_unit_id' => $unit->id]))
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('userDivision', 'Examination Services Division'));

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('userDivision', null));
    }
}
