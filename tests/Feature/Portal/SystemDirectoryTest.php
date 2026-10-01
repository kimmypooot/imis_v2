<?php

namespace Tests\Feature\Portal;

use App\Enums\UnitType;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dashboard's Category → Division → System directory.
 */
class SystemDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_systems_are_grouped_by_unit_type_then_committees_then_other(): void
    {
        $rsu = OrganizationalUnit::factory()->create(['name' => 'ESD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $fieldOffice = OrganizationalUnit::factory()->create(['name' => 'Leyte FO', 'unit_type' => UnitType::FieldOffice]);
        $committee = Committee::factory()->create(['name' => 'GAD Committee']);

        $otrs = Application::factory()->create(['name' => 'OTRS', 'organizational_unit_id' => $rsu->id]);
        $otrs->listedUnits()->attach($fieldOffice);
        $gad = Application::factory()->create(['name' => 'GAD Corner']);
        $gad->listedCommittees()->attach($committee);
        $loose = Application::factory()->create(['name' => 'Loose']);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('directory.categories', fn ($categories) => collect($categories)->pluck('label')->all()
                    === ['Regional Support Units', 'Field Offices', 'Committees', 'Other'])
                ->where('directory.categories.0.divisions.0.name', 'ESD')
                ->where('directory.categories.0.divisions.0.system_ids', [$otrs->id])
                // Listed in a second place without being duplicated in `systems`.
                ->where('directory.categories.1.divisions.0.system_ids', [$otrs->id])
                ->where('directory.categories.2.divisions.0.system_ids', [$gad->id])
                ->where('directory.categories.3.divisions.0.key', 'other')
                ->where('directory.categories.3.divisions.0.system_ids', [$loose->id])
                ->has('directory.systems', 3));
    }

    public function test_only_visible_systems_and_active_divisions_appear(): void
    {
        $unit = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $inactiveUnit = OrganizationalUnit::factory()->inactive()->create(['unit_type' => UnitType::FieldOffice]);
        Application::factory()->inactive()->create(['organizational_unit_id' => $unit->id]);
        Application::factory()->administrative()->create(['organizational_unit_id' => $unit->id]);
        $underInactive = Application::factory()->create(['organizational_unit_id' => $inactiveUnit->id]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                // Only the system whose unit is inactive is visible — it falls to "Other".
                ->has('directory.categories', 1)
                ->where('directory.categories.0.key', 'other')
                ->where('directory.categories.0.divisions.0.system_ids', [$underInactive->id]));
    }

    public function test_directory_systems_carry_the_viewers_launch_state(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $restricted = Application::factory()->create([
            'organizational_unit_id' => $unit->id, 'requires_access' => true, 'url' => 'https://secret.example.test',
        ]);
        $role = ApplicationRole::factory()->for($restricted)->create(['name' => 'Encoder']);
        $holder = ApplicationAccess::factory()->forRole($role)->create()->user;

        $this->actingAs($holder)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where("directory.systems.{$restricted->id}.url", 'https://secret.example.test')
            ->where("directory.systems.{$restricted->id}.role", 'Encoder'));

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');
        $response->assertInertia(fn (Assert $page) => $page
            ->where("directory.systems.{$restricted->id}.url", null)
            ->where("directory.systems.{$restricted->id}.role", null));
        $this->assertStringNotContainsString('secret.example.test', $response->getContent());
    }

    public function test_the_open_division_comes_from_the_query_string(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard?division=unit-3')
            ->assertInertia(fn (Assert $page) => $page->where('division', 'unit-3'));
    }

    public function test_admin_can_list_a_system_under_extra_units_and_committees(): void
    {
        $actor = $this->userWith('applications.update');
        $application = Application::factory()->create();
        $unit = OrganizationalUnit::factory()->create(['name' => 'PALD']);
        $committee = Committee::factory()->create(['name' => 'BAC']);

        $this->actingAs($actor)->put("/admin/applications/{$application->id}", [
            'name' => $application->name,
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'listed_unit_ids' => [$unit->id],
            'listed_committee_ids' => [$committee->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$unit->id], $application->listedUnits()->pluck('organizational_units.id')->all());
        $this->assertSame([$committee->id], $application->listedCommittees()->pluck('committees.id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'application.listings_changed', 'target_id' => $application->id]);

        // Omitting the fields leaves listings alone.
        $this->actingAs($actor)->put("/admin/applications/{$application->id}", [
            'name' => $application->name, 'status' => 'active', 'visibility' => 'authenticated', 'sort_order' => 0,
        ]);
        $this->assertSame(1, $application->listedUnits()->count());
    }

    public function test_listing_ids_are_validated(): void
    {
        $application = Application::factory()->create();

        $this->actingAs($this->userWith('applications.update'))->put("/admin/applications/{$application->id}", [
            'name' => $application->name, 'status' => 'active', 'visibility' => 'authenticated', 'sort_order' => 0,
            'listed_unit_ids' => [999999],
            'listed_committee_ids' => ['nope'],
        ])->assertSessionHasErrors(['listed_unit_ids.0', 'listed_committee_ids.0']);
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
