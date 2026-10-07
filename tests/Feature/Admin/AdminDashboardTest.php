<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditEvent;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\Committee;
use App\Models\LoginHistory;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Support\Audit\AuditLogger;
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

    public function test_dashboard_sections_are_scoped_to_what_the_viewer_can_see(): void
    {
        $user = $this->userWithPermissions(['users.view']);

        $this->actingAs($user)->get('/admin')->assertInertia(fn ($page) => $page
            ->component('Admin/Index')
            ->whereNot('dashboard.metrics.users', null)
            ->where('dashboard.metrics.systems', null)
            ->where('dashboard.metrics.access', null)
            ->where('dashboard.loginTrend', null)
            ->where('dashboard.recentActivity', null)
            ->whereNot('dashboard.roleCounts', null));
    }

    public function test_recent_activity_shows_the_actor_name_without_the_email(): void
    {
        $viewer = $this->userWithPermissions(['audit_logs.view']);
        $actor = User::factory()->create(['name' => 'Juan Dela Cruz', 'email' => 'jdcruz@example.test']);
        app(AuditLogger::class)->recordFor($actor, AuditEvent::UserUpdated, 'Updated a user');

        $this->actingAs($viewer)->get('/admin')->assertInertia(fn ($page) => $page
            ->where('dashboard.recentActivity.0.actor', 'Juan Dela Cruz')
            ->where('dashboard.recentActivity.0.actor_label', 'Juan Dela Cruz <jdcruz@example.test>'));
    }

    public function test_dashboard_reports_real_counts(): void
    {
        $viewer = $this->userWithPermissions(['users.view', 'applications.view', 'audit_logs.view']);
        User::factory()->inactive()->create();
        $system = Application::factory()->create();
        $role = ApplicationRole::factory()->for($system)->create();
        ApplicationAccess::factory()->forRole($role)->create();
        LoginHistory::query()->forceCreate(['username' => 'x', 'logged_in_at' => now()]);

        $this->actingAs($viewer)->get('/admin')->assertInertia(fn ($page) => $page
            // viewer + inactive user + the grant's user
            ->where('dashboard.metrics.users.total', 3)
            ->where('dashboard.metrics.users.inactive', 1)
            ->where('dashboard.metrics.systems.total', 1)
            ->where('dashboard.metrics.access.grants', 1)
            ->where('dashboard.metrics.access.usersWithAccess', 1)
            ->where('dashboard.metrics.access.usersWithoutAccess', 1)
            ->where('dashboard.metrics.logins.today', 1)
            ->where('dashboard.loginTrend.values', fn ($values) => count($values) === 14 && collect($values)->last() === 1)
            ->where('dashboard.accessBySystem.top.0.assigned', 1));
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
