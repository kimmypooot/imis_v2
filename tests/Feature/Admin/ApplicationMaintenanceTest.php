<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicationMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    // ── Starting and ending ──────────────────────────────────────────

    public function test_unauthorized_user_cannot_start_maintenance(): void
    {
        $actor = $this->userWithPermissions('applications.update', 'applications.archive');
        $application = Application::factory()->create();

        $this->actingAs($actor)
            ->patch("/admin/applications/{$application->id}/maintenance", ['under_maintenance' => true])
            ->assertForbidden();

        $this->assertFalse($application->fresh()->under_maintenance);
    }

    public function test_authorized_user_can_start_maintenance_with_a_notice(): void
    {
        $actor = $this->userWithPermissions('applications.maintenance');
        $application = Application::factory()->create();

        $this->actingAs($actor)
            ->patch("/admin/applications/{$application->id}/maintenance", [
                'under_maintenance' => true,
                'maintenance_message' => 'Back by 5 PM.',
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertTrue($application->under_maintenance);
        $this->assertSame('Back by 5 PM.', $application->maintenance_message);
        $this->assertNotNull($application->maintenance_started_at);

        $log = AuditLog::query()->where('event', 'application.maintenance_started')->sole();
        $this->assertSame($application->id, $log->target_id);
        $this->assertSame('Back by 5 PM.', $log->metadata['message']);
    }

    public function test_updating_the_notice_keeps_the_start_time(): void
    {
        $actor = $this->userWithPermissions('applications.maintenance');
        $application = Application::factory()->create();
        $application->startMaintenance('First notice');
        $startedAt = $application->maintenance_started_at;

        $this->travel(2)->hours();

        $this->actingAs($actor)->patch("/admin/applications/{$application->id}/maintenance", [
            'under_maintenance' => true,
            'maintenance_message' => 'Taking longer than expected.',
        ]);

        $application->refresh();
        $this->assertSame('Taking longer than expected.', $application->maintenance_message);
        $this->assertTrue($startedAt->equalTo($application->maintenance_started_at));
        $this->assertDatabaseHas('audit_logs', ['event' => 'application.maintenance_updated']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'application.maintenance_started']);
    }

    public function test_authorized_user_can_end_maintenance(): void
    {
        $actor = $this->userWithPermissions('applications.maintenance');
        $application = Application::factory()->create();
        $application->startMaintenance('Upgrading');

        $this->actingAs($actor)
            ->patch("/admin/applications/{$application->id}/maintenance", ['under_maintenance' => false])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertFalse($application->under_maintenance);
        $this->assertNull($application->maintenance_message);
        $this->assertNull($application->maintenance_started_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'application.maintenance_ended']);
    }

    public function test_ending_maintenance_that_is_not_running_records_nothing(): void
    {
        $actor = $this->userWithPermissions('applications.maintenance');
        $application = Application::factory()->create();

        $this->actingAs($actor)->patch("/admin/applications/{$application->id}/maintenance", ['under_maintenance' => false]);

        $this->assertDatabaseMissing('audit_logs', ['event' => 'application.maintenance_ended']);
    }

    public function test_maintenance_fields_cannot_be_set_through_the_general_edit_form(): void
    {
        $actor = $this->userWithPermissions('applications.update');
        $application = Application::factory()->create();

        $this->actingAs($actor)->put("/admin/applications/{$application->id}", [
            'name' => $application->name,
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'under_maintenance' => true,
            'maintenance_message' => 'Sneaky',
        ]);

        $this->assertFalse($application->fresh()->under_maintenance);
        $this->assertNull($application->fresh()->maintenance_message);
    }

    public function test_notice_is_validated(): void
    {
        $actor = $this->userWithPermissions('applications.maintenance');
        $application = Application::factory()->create();

        $this->actingAs($actor)
            ->patch("/admin/applications/{$application->id}/maintenance", [
                'under_maintenance' => true,
                'maintenance_message' => str_repeat('a', 501),
            ])
            ->assertSessionHasErrors('maintenance_message');

        $this->actingAs($actor)
            ->patch("/admin/applications/{$application->id}/maintenance", [])
            ->assertSessionHasErrors('under_maintenance');
    }

    // ── Portal behavior ──────────────────────────────────────────────

    public function test_launch_link_is_withheld_during_maintenance_even_from_role_holders(): void
    {
        $application = Application::factory()->create(['url' => 'https://otrs.example.test']);
        $role = ApplicationRole::factory()->for($application)->create(['launch_url' => 'https://otrs.example.test/admin']);
        $grant = ApplicationAccess::factory()->forRole($role)->create();
        $application->startMaintenance('Upgrading the database.');

        $this->actingAs($grant->user)
            ->get("/applications/{$application->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.url', null)
                ->where('application.under_maintenance', true)
                ->where('application.maintenance_message', 'Upgrading the database.')
                ->where('application.role', $role->name));

        $response = $this->actingAs($grant->user)->get('/applications');
        $this->assertStringNotContainsString('otrs.example.test', $response->getContent());
    }

    public function test_people_who_manage_maintenance_can_still_launch(): void
    {
        $application = Application::factory()->create(['url' => 'https://otrs.example.test']);
        $application->startMaintenance(null);

        $this->actingAs($this->userWithPermissions('applications.maintenance'))
            ->get("/applications/{$application->id}")
            ->assertInertia(fn (Assert $page) => $page->where('application.url', 'https://otrs.example.test'));

        $this->actingAs($this->superAdmin())
            ->get("/applications/{$application->id}")
            ->assertInertia(fn (Assert $page) => $page->where('application.url', 'https://otrs.example.test'));
    }

    public function test_bypass_does_not_override_restricted_access(): void
    {
        $application = Application::factory()->create(['url' => 'https://otrs.example.test', 'requires_access' => true]);
        $application->startMaintenance(null);

        $this->actingAs($this->userWithPermissions('applications.maintenance'))
            ->get("/applications/{$application->id}")
            ->assertInertia(fn (Assert $page) => $page->where('application.url', null));
    }

    public function test_dashboard_lists_visible_systems_under_maintenance(): void
    {
        $down = Application::factory()->create(['name' => 'OTRS']);
        $down->startMaintenance('Back soon.');
        $hidden = Application::factory()->administrative()->create();
        $hidden->startMaintenance(null);
        Application::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('maintenanceNotices', 1)
                ->where('maintenanceNotices.0.name', 'OTRS')
                ->where('maintenanceNotices.0.message', 'Back soon.'));
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(
                Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
            );
        }

        $user->roles()->attach($role);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->firstOrCreate(
            ['name' => RoleName::SuperAdmin->value],
            ['display_name' => 'Super Administrator'],
        ));

        return $user;
    }
}
