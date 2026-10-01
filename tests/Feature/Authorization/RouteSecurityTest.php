<?php

namespace Tests\Feature\Authorization;

use App\Enums\PermissionName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/roles')->assertRedirect('/login');
        $this->get('/permissions')->assertRedirect('/login');
        $this->get('/authorization-demo')->assertRedirect('/login');
    }

    public function test_authenticated_user_without_permission_receives_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/roles')->assertForbidden();
        $this->actingAs($user)->get('/permissions')->assertForbidden();
    }

    public function test_authorized_user_can_access_roles_index(): void
    {
        $user = $this->userWithPermission(PermissionName::RolesView->value);

        $this->actingAs($user)->get('/roles')->assertOk();
    }

    public function test_authorized_user_can_access_permissions_index(): void
    {
        $user = $this->userWithPermission(PermissionName::PermissionsView->value);

        $this->actingAs($user)->get('/permissions')->assertOk();
    }

    public function test_direct_post_to_protected_endpoint_is_denied_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/roles', [
            'name' => 'attempted-role',
            'display_name' => 'Attempted Role',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'attempted-role']);
    }

    public function test_direct_put_to_protected_endpoint_is_denied_without_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => 'target-role']);

        $this->actingAs($user)->put("/roles/{$role->id}", [
            'name' => 'renamed-role',
            'display_name' => 'Renamed',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'target-role']);
    }

    public function test_direct_permission_assignment_is_denied_without_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();

        $this->actingAs($user)->put("/roles/{$role->id}/permissions", [
            'permission_ids' => [$permission->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => $permission], [
                'display_name' => $permission,
            ]),
        );
        $user->roles()->attach($role);

        return $user;
    }
}
