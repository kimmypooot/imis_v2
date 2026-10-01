<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_and_edit_an_ordinary_role(): void
    {
        $actor = $this->userWithPermissions(['roles.view', 'roles.create', 'roles.update']);

        $this->actingAs($actor)->post('/roles', [
            'name' => 'rsu-manager',
            'display_name' => 'RSU Manager',
            'status' => 'active',
        ])->assertRedirect('/roles');

        $role = Role::where('name', 'rsu-manager')->firstOrFail();

        $this->actingAs($actor)->put("/roles/{$role->id}", [
            'name' => 'rsu-manager-renamed',
            'display_name' => 'RSU Manager Renamed',
            'status' => 'inactive',
        ])->assertRedirect('/roles');

        $this->assertSame('rsu-manager-renamed', $role->fresh()->name);
        $this->assertSame('inactive', $role->fresh()->status->value);
    }

    public function test_super_admin_role_name_cannot_be_changed_even_by_a_super_admin(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);

        $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}", [
            'name' => 'renamed-super-admin',
            'display_name' => $superAdminRole->display_name,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(RoleName::SuperAdmin->value, $superAdminRole->fresh()->name);
    }

    public function test_super_admin_role_cannot_be_deactivated_even_by_a_super_admin(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);

        $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}", [
            'name' => RoleName::SuperAdmin->value,
            'display_name' => $superAdminRole->display_name,
            'status' => 'inactive',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame('active', $superAdminRole->fresh()->status->value);
    }

    public function test_super_admin_role_display_name_and_description_can_still_be_edited(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = User::factory()->create();
        $actor->roles()->attach($superAdminRole);

        $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}", [
            'name' => RoleName::SuperAdmin->value,
            'display_name' => 'Updated Display Name',
            'description' => 'Updated description.',
            'status' => 'active',
        ]);

        $response->assertRedirect('/roles');
        $this->assertSame('Updated Display Name', $superAdminRole->fresh()->display_name);
    }

    public function test_an_ordinary_actor_with_roles_update_is_also_blocked_from_renaming_super_admin(): void
    {
        $superAdminRole = Role::factory()->create(['name' => RoleName::SuperAdmin->value]);
        $actor = $this->userWithPermissions(['roles.view', 'roles.update']);

        $response = $this->actingAs($actor)->put("/roles/{$superAdminRole->id}", [
            'name' => 'hijacked',
            'display_name' => $superAdminRole->display_name,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(RoleName::SuperAdmin->value, $superAdminRole->fresh()->name);
    }

    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissionNames as $name) {
            $role->permissions()->attach(
                Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name]),
            );
        }

        $user->roles()->attach($role);

        return $user;
    }
}
