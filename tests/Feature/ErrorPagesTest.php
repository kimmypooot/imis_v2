<?php

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_route_returns_404(): void
    {
        $this->get('/this-route-does-not-exist')->assertNotFound();
    }

    public function test_forbidden_request_renders_the_403_inertia_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/roles');

        $response->assertForbidden();
        $response->assertInertia(fn ($page) => $page->component('Errors/403'));
    }

    public function test_authorized_request_does_not_render_an_error_page(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => PermissionName::RolesView->value], [
                'display_name' => 'Roles view',
            ]),
        );
        $user->roles()->attach($role);

        $this->actingAs($user)->get('/roles')->assertOk();
    }
}
