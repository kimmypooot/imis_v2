<?php

namespace Tests\Feature\Authorization;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAndPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_a_role(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        $user->roles()->attach($role);

        $this->assertTrue($user->hasRole($role->name));
    }

    public function test_user_can_have_multiple_roles(): void
    {
        $user = User::factory()->create();
        $roleA = Role::factory()->create();
        $roleB = Role::factory()->create();

        $user->roles()->attach([$roleA->id, $roleB->id]);

        $this->assertTrue($user->hasRole($roleA->name));
        $this->assertTrue($user->hasRole($roleB->name));
        $this->assertCount(2, $user->roles);
    }

    public function test_has_any_role_matches_at_least_one(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => 'role-a']);
        $user->roles()->attach($role);

        $this->assertTrue($user->hasAnyRole(['role-a', 'role-b']));
        $this->assertFalse($user->hasAnyRole(['role-b', 'role-c']));
    }

    public function test_has_all_roles_requires_every_role(): void
    {
        $user = User::factory()->create();
        $roleA = Role::factory()->create(['name' => 'role-a']);
        $roleB = Role::factory()->create(['name' => 'role-b']);
        $user->roles()->attach([$roleA->id, $roleB->id]);

        $this->assertTrue($user->hasAllRoles(['role-a', 'role-b']));
        $this->assertFalse($user->hasAllRoles(['role-a', 'role-c']));
    }

    public function test_role_can_have_permissions(): void
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();

        $role->permissions()->attach($permission);

        $this->assertTrue($role->permissions->contains('id', $permission->id));
    }

    public function test_role_can_have_multiple_permissions(): void
    {
        $role = Role::factory()->create();
        $permissions = Permission::factory()->count(3)->create();

        $role->permissions()->attach($permissions->pluck('id'));

        $this->assertCount(3, $role->permissions);
    }

    public function test_user_inherits_permissions_through_roles(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['name' => 'widgets.view']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        $this->assertTrue($user->hasPermission('widgets.view'));
    }

    public function test_has_any_permission_matches_at_least_one(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::factory()->create(['name' => 'widgets.view']));
        $user->roles()->attach($role);

        $this->assertTrue($user->hasAnyPermission(['widgets.view', 'widgets.delete']));
        $this->assertFalse($user->hasAnyPermission(['widgets.create', 'widgets.delete']));
    }

    public function test_has_all_permissions_requires_every_permission(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::factory()->createMany([
            ['name' => 'widgets.view'],
            ['name' => 'widgets.create'],
        ]));
        $user->roles()->attach($role);

        $this->assertTrue($user->hasAllPermissions(['widgets.view', 'widgets.create']));
        $this->assertFalse($user->hasAllPermissions(['widgets.view', 'widgets.delete']));
    }

    public function test_user_without_permission_is_denied(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasPermission('widgets.view'));
        $this->assertFalse($user->can('widgets.view'));
    }

    public function test_super_administrator_receives_system_level_access(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $user->roles()->attach($role);

        $this->assertTrue($user->hasPermission('anything.at-all'));
        $this->assertTrue($user->can('anything.at-all'));
    }

    public function test_ordinary_users_do_not_automatically_receive_super_administrator_access(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasRole(RoleName::SuperAdmin));
        $this->assertFalse($user->can('anything.at-all'));
    }

    public function test_super_administrator_identification_does_not_depend_on_email_or_id(): void
    {
        $user = User::factory()->create(['email' => 'nobody-special@example.com']);
        $role = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $user->roles()->attach($role);

        $this->assertTrue($user->hasRole(RoleName::SuperAdmin));
    }

    public function test_duplicate_role_assignment_is_prevented(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        $user->roles()->attach($role);

        $this->expectException(QueryException::class);
        $user->roles()->attach($role);
    }

    public function test_duplicate_permission_assignment_is_prevented(): void
    {
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();

        $role->permissions()->attach($permission);

        $this->expectException(QueryException::class);
        $role->permissions()->attach($permission);
    }
}
