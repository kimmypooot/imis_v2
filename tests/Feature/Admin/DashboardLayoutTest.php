<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The legacy "Manage Dashboard" page: placing systems under divisions.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_applications_update(): void
    {
        $this->actingAs($this->userWith('applications.view'))->get('/admin/dashboard-layout')->assertForbidden();
    }

    public function test_lists_divisions_with_managed_and_listed_systems(): void
    {
        $unit = OrganizationalUnit::factory()->create(['name' => 'ESD']);
        $owned = Application::factory()->create(['organizational_unit_id' => $unit->id]);
        $listed = Application::factory()->create();
        $unit->listedApplications()->attach($listed);
        $loose = Application::factory()->create();

        $this->actingAs($this->userWith('applications.update'))->get('/admin/dashboard-layout')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/DashboardLayout/Index')
                ->where('categories.0.divisions.0.owned_ids', [$owned->id])
                ->where('categories.0.divisions.0.listed_ids', [$listed->id])
                ->where('systems', fn ($systems) => collect($systems)->firstWhere('id', $loose->id)['unplaced'] === true
                    && collect($systems)->firstWhere('id', $listed->id)['unplaced'] === false));
    }

    public function test_saving_syncs_listings_and_ignores_managed_systems(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $owned = Application::factory()->create(['organizational_unit_id' => $unit->id]);
        $keep = Application::factory()->create();
        $drop = Application::factory()->create();
        $unit->listedApplications()->attach($drop);

        $this->actingAs($this->userWith('applications.update'))
            ->put("/admin/dashboard-layout/unit/{$unit->id}", ['application_ids' => [$owned->id, $keep->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame([$keep->id], $unit->listedApplications()->pluck('applications.id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'application.listings_changed', 'target_id' => $unit->id]);
    }

    public function test_committees_can_be_given_systems(): void
    {
        $committee = Committee::factory()->create();
        $system = Application::factory()->create();

        $this->actingAs($this->userWith('applications.update'))
            ->put("/admin/dashboard-layout/committee/{$committee->id}", ['application_ids' => [$system->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$system->id], $committee->listedApplications()->pluck('applications.id')->all());
    }

    public function test_unknown_division_types_and_systems_are_rejected(): void
    {
        $actor = $this->userWith('applications.update');
        $unit = OrganizationalUnit::factory()->create();

        $this->actingAs($actor)->put("/admin/dashboard-layout/service/{$unit->id}", ['application_ids' => []])->assertNotFound();
        $this->actingAs($actor)->put("/admin/dashboard-layout/unit/{$unit->id}", ['application_ids' => [999999]])
            ->assertSessionHasErrors('application_ids.0');
    }

    private function userWith(string $permission): User
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
