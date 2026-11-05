<?php

namespace Tests\Unit\Models;

use App\Enums\Visibility;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migrations register real hub systems; these tests count their own.
        Application::query()->delete();
    }

    public function test_public_entry_is_visible_to_a_guest(): void
    {
        $application = Application::factory()->create(['visibility' => Visibility::PublicVisibility]);

        $this->assertTrue($application->isVisibleTo(null));
    }

    public function test_authenticated_entry_is_hidden_from_a_guest(): void
    {
        $application = Application::factory()->create(['visibility' => Visibility::Authenticated]);

        $this->assertFalse($application->isVisibleTo(null));
    }

    public function test_authenticated_entry_is_visible_to_any_logged_in_user(): void
    {
        $application = Application::factory()->create(['visibility' => Visibility::Authenticated]);
        $user = User::factory()->create();

        $this->assertTrue($application->isVisibleTo($user));
    }

    public function test_administrative_entry_is_hidden_from_an_ordinary_user(): void
    {
        $application = Application::factory()->administrative()->create();
        $user = User::factory()->create();

        $this->assertFalse($application->isVisibleTo($user));
    }

    public function test_administrative_entry_is_visible_to_a_user_with_admin_access(): void
    {
        $application = Application::factory()->administrative()->create();

        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => 'roles.view'], ['display_name' => 'Roles view']),
        );
        $user->roles()->attach($role);

        $this->assertTrue($application->isVisibleTo($user));
    }

    public function test_administrative_entry_is_visible_to_hr_staff_too(): void
    {
        $application = Application::factory()->administrative()->create();

        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => 'leave.view'], ['display_name' => 'Leave view']),
        );
        $user->roles()->attach($role);

        $this->assertTrue($application->isVisibleTo($user));
        $this->assertCount(1, Application::query()->visibleTo($user)->get());
    }

    public function test_inactive_entry_is_never_visible_regardless_of_visibility_tier(): void
    {
        $application = Application::factory()->inactive()->create([
            'visibility' => Visibility::PublicVisibility,
        ]);

        $this->assertFalse($application->isVisibleTo(null));
    }

    public function test_scope_visible_to_matches_is_visible_to(): void
    {
        Application::factory()->create(['visibility' => Visibility::Authenticated]);
        Application::factory()->administrative()->create();
        Application::factory()->inactive()->create();

        $user = User::factory()->create();

        $visible = Application::query()->visibleTo($user)->get();

        $this->assertCount(1, $visible);
        $this->assertTrue($visible->every(fn (Application $application) => $application->isVisibleTo($user)));
    }
}
