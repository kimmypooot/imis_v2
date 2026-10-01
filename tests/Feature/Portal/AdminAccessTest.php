<?php

namespace Tests\Feature\Portal;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_ordinary_authenticated_user_receives_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_user_with_an_admin_qualifying_permission_can_access_the_admin_landing_page(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => 'roles.view'], ['display_name' => 'Roles view']),
        );
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Index'));
    }

    public function test_organizational_units_view_permission_alone_qualifies_for_admin_access(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(
                ['name' => 'organizational_units.view'],
                ['display_name' => 'Organizational units view'],
            ),
        );
        $user->roles()->attach($role);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_services_view_permission_alone_qualifies_for_admin_access(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(
                ['name' => 'services.view'],
                ['display_name' => 'Services view'],
            ),
        );
        $user->roles()->attach($role);

        $this->actingAs($user)->get('/admin')->assertOk();
    }
}
