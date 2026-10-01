<?php

namespace Tests\Feature\Authorization;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionAssignmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_actor_cannot_grant_a_permission_they_do_not_hold_themselves(): void
    {
        $actor = $this->userWithPermission(PermissionName::PermissionsManage->value);
        $targetRole = Role::factory()->create();
        $unheldPermission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::RolesDelete->value],
            ['display_name' => 'Roles Delete'],
        );

        $response = $this->actingAs($actor)->put("/roles/{$targetRole->id}/permissions", [
            'permission_ids' => [$unheldPermission->id],
        ]);

        $response->assertSessionHasErrors('permission_ids');
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $targetRole->id,
            'permission_id' => $unheldPermission->id,
        ]);
    }

    public function test_actor_can_grant_a_permission_they_already_hold(): void
    {
        $manageName = PermissionName::PermissionsManage->value;
        $actorRole = Role::factory()->create();
        $managePermission = Permission::query()->firstOrCreate(['name' => $manageName], ['display_name' => $manageName]);
        $actorRole->permissions()->attach($managePermission);
        $actor = User::factory()->create();
        $actor->roles()->attach($actorRole);

        $targetRole = Role::factory()->create();

        $response = $this->actingAs($actor)->put("/roles/{$targetRole->id}/permissions", [
            'permission_ids' => [$managePermission->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $targetRole->id,
            'permission_id' => $managePermission->id,
        ]);
    }

    public function test_actor_cannot_modify_the_super_admin_roles_permissions(): void
    {
        $actor = $this->userWithPermission(PermissionName::PermissionsManage->value);
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $heldPermission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::PermissionsManage->value],
            ['display_name' => 'Permissions Manage'],
        );

        $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}/permissions", [
            'permission_ids' => [$heldPermission->id],
        ]);

        $response->assertSessionHasErrors('permission_ids');
    }

    public function test_an_actual_super_admin_can_modify_the_super_admin_roles_permissions(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);
        $permission = Permission::factory()->create();

        $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}/permissions", [
            'permission_ids' => [$permission->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $superAdminRole->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_an_actual_super_admin_can_grant_a_permission_they_do_not_hold_themselves(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);

        $targetRole = Role::factory()->create();
        $permission = Permission::factory()->create();

        $response = $this->actingAs($actor)->put("/roles/{$targetRole->id}/permissions", [
            'permission_ids' => [$permission->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $targetRole->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_removing_a_permission_the_actor_does_not_hold_is_not_blocked(): void
    {
        // The escalation guard only restricts *additions*; an actor may
        // still remove a permission from a role even if they don't
        // personally hold it — that narrows access, it doesn't escalate it.
        $manageName = PermissionName::PermissionsManage->value;
        $actorRole = Role::factory()->create();
        $managePermission = Permission::query()->firstOrCreate(['name' => $manageName], ['display_name' => $manageName]);
        $actorRole->permissions()->attach($managePermission);
        $actor = User::factory()->create();
        $actor->roles()->attach($actorRole);

        $unheldPermission = Permission::query()->firstOrCreate(
            ['name' => PermissionName::RolesDelete->value],
            ['display_name' => 'Roles Delete'],
        );
        $targetRole = Role::factory()->create();
        $targetRole->permissions()->attach($unheldPermission);

        $response = $this->actingAs($actor)->put("/roles/{$targetRole->id}/permissions", [
            'permission_ids' => [],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $targetRole->id,
            'permission_id' => $unheldPermission->id,
        ]);
    }

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
        );
        $user->roles()->attach($role);

        return $user;
    }
}
