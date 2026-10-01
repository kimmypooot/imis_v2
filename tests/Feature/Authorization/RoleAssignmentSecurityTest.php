<?php

namespace Tests\Feature\Authorization;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAssignmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_user_cannot_assign_roles(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $role = Role::factory()->create();

        $this->actingAs($actor)
            ->put("/users/{$target->id}/roles", ['role_ids' => [$role->id]])
            ->assertForbidden();

        $this->assertDatabaseMissing('role_user', ['user_id' => $target->id, 'role_id' => $role->id]);
    }

    public function test_unauthorized_user_cannot_grant_permissions(): void
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();

        $this->actingAs($actor)
            ->put("/roles/{$role->id}/permissions", ['permission_ids' => [$permission->id]])
            ->assertForbidden();

        $this->assertDatabaseMissing('permission_role', ['role_id' => $role->id, 'permission_id' => $permission->id]);
    }

    public function test_user_with_users_update_permission_cannot_assign_super_admin_to_self(): void
    {
        $actor = $this->userWithPermission(PermissionName::UsersUpdate->value);
        $superAdmin = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);

        $response = $this->actingAs($actor)->put("/users/{$actor->id}/roles", [
            'role_ids' => [$superAdmin->id],
        ]);

        $response->assertSessionHasErrors('role_ids');
        $this->assertFalse($actor->fresh()->hasRole(RoleName::SuperAdmin));
    }

    public function test_user_with_users_update_permission_cannot_assign_super_admin_to_another_user(): void
    {
        $actor = $this->userWithPermission(PermissionName::UsersUpdate->value);
        $target = User::factory()->create();
        $superAdmin = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);

        $response = $this->actingAs($actor)->put("/users/{$target->id}/roles", [
            'role_ids' => [$superAdmin->id],
        ]);

        $response->assertSessionHasErrors('role_ids');
        $this->assertFalse($target->fresh()->hasRole(RoleName::SuperAdmin));
    }

    public function test_super_administrator_can_assign_super_admin_role(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);

        $target = User::factory()->create();

        $this->actingAs($actor)
            ->put("/users/{$target->id}/roles", ['role_ids' => [$superAdminRole->id]])
            ->assertRedirect();

        $this->assertTrue($target->fresh()->hasRole(RoleName::SuperAdmin));
    }

    public function test_ordinary_user_cannot_reach_role_assignment_screen_for_another_user(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor)->get("/users/{$target->id}")->assertForbidden();
    }

    public function test_super_admin_role_cannot_be_removed_from_the_last_active_super_admin(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);

        $response = $this->actingAs($actor)->put("/users/{$actor->id}/roles", ['role_ids' => []]);

        $response->assertSessionHasErrors('role_ids');
        $this->assertTrue($actor->fresh()->hasRole(RoleName::SuperAdmin));
    }

    public function test_super_admin_role_can_be_removed_when_another_active_super_admin_exists(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);
        $otherSuperAdmin = User::factory()->create();
        $otherSuperAdmin->roles()->attach($superAdminRole);

        $response = $this->actingAs($actor)->put("/users/{$otherSuperAdmin->id}/roles", ['role_ids' => []]);

        $response->assertRedirect();
        $this->assertFalse($otherSuperAdmin->fresh()->hasRole(RoleName::SuperAdmin));
    }

    public function test_super_admin_role_removal_is_still_blocked_even_when_an_inactive_super_admin_exists(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);
        $inactiveSuperAdmin = User::factory()->inactive()->create();
        $inactiveSuperAdmin->roles()->attach($superAdminRole);

        $response = $this->actingAs($actor)->put("/users/{$actor->id}/roles", ['role_ids' => []]);

        $response->assertSessionHasErrors('role_ids');
        $this->assertTrue($actor->fresh()->hasRole(RoleName::SuperAdmin));
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
