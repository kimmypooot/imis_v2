<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_user_without_an_admin_qualifying_permission_receives_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_attention_is_empty_when_nothing_is_inactive(): void
    {
        $user = $this->userWithPermissions(['services.view']);
        Service::factory()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page->has('attention', 0));
    }

    public function test_attention_reports_real_inactive_counts_for_permitted_modules(): void
    {
        $user = $this->userWithPermissions(['services.view', 'applications.view']);
        Service::factory()->inactive()->count(2)->create();
        Application::factory()->inactive()->create();
        OrganizationalUnit::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->has('attention', 2)
            ->where('attention.0.label', 'Services')
            ->where('attention.0.inactiveCount', 2)
            ->where('attention.1.label', 'Systems / Applications')
            ->where('attention.1.inactiveCount', 1));
    }

    public function test_attention_reports_inactive_user_count(): void
    {
        $user = $this->userWithPermissions(['users.view']);
        User::factory()->inactive()->count(3)->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->has('attention', 1)
            ->where('attention.0.label', 'Users')
            ->where('attention.0.inactiveCount', 3));
    }

    public function test_attention_reports_inactive_committee_count(): void
    {
        $user = $this->userWithPermissions(['committees.view']);
        Committee::factory()->inactive()->count(2)->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->has('attention', 1)
            ->where('attention.0.label', 'Committees')
            ->where('attention.0.inactiveCount', 2));
    }

    public function test_attention_excludes_modules_the_user_cannot_view(): void
    {
        $user = $this->userWithPermissions(['services.view']);
        Service::factory()->inactive()->create();
        Application::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertInertia(fn ($page) => $page
            ->has('attention', 1)
            ->where('attention.0.label', 'Services'));
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
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
