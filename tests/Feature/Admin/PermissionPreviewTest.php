<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin-3, permission preview: a user's page sends each assignable role's
 * permissions (and their display names), so the page can show what
 * ticking a role opens before saving. The preview itself is worked out
 * in the browser from the sidebar's own definitions (Utils/accessPreview).
 */
class PermissionPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_user_page_sends_each_roles_permissions(): void
    {
        $leave = Permission::query()->create(['name' => 'leave.view', 'display_name' => 'Leave View']);
        $role = Role::factory()->create();
        $role->permissions()->attach($leave);
        $target = User::factory()->create();

        $this->actingAs($this->admin())->get("/users/{$target->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Show')
            ->where("rolePermissions.{$role->id}", ['leave.view'])
            ->where('permissionLabels', fn ($labels) => $labels['leave.view'] === 'Leave View')
            ->where('holdsSuperAdmin', false));
    }

    public function test_a_held_super_administrator_role_is_flagged(): void
    {
        $target = User::factory()->create();
        $target->roles()->attach(Role::query()->firstOrCreate(['name' => RoleName::SuperAdmin->value], ['display_name' => 'Super Administrator', 'status' => 'active']));

        $this->actingAs($this->admin())->get("/users/{$target->id}")->assertInertia(fn (Assert $page) => $page->where('holdsSuperAdmin', true));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'users.view'], ['display_name' => 'Users View']));
        $user->roles()->attach($role);

        return $user;
    }
}
