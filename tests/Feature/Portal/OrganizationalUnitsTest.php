<?php

namespace Tests\Feature\Portal;

use App\Enums\EmploymentStatus;
use App\Enums\UnitType;
use App\Models\Application;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationalUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_organizational_units(): void
    {
        $this->get('/organizational-units')->assertRedirect('/login');
    }

    public function test_active_unit_renders_in_the_directory(): void
    {
        $user = User::factory()->create();
        $unit = OrganizationalUnit::factory()->create(['name' => 'Visible Unit', 'unit_type' => UnitType::FieldOffice]);

        $response = $this->actingAs($user)->get('/organizational-units');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/OrganizationalUnits/Index')
            ->has('groups', 1)
            ->where('groups.0.units.0.name', $unit->name));
    }

    public function test_offices_are_grouped_with_satellite_offices_under_field_offices(): void
    {
        $user = User::factory()->create();
        OrganizationalUnit::factory()->create(['name' => 'Support Unit', 'unit_type' => UnitType::RegionalSupportUnit, 'sort_order' => 1]);
        OrganizationalUnit::factory()->create(['name' => 'Field One', 'unit_type' => UnitType::FieldOffice, 'sort_order' => 2]);
        OrganizationalUnit::factory()->create(['name' => 'Satellite One', 'unit_type' => UnitType::SatelliteOffice, 'sort_order' => 3]);

        $this->actingAs($user)->get('/organizational-units')->assertInertia(fn ($page) => $page
            ->has('groups', 2)
            ->where('groups.0.label', 'Regional Support Units')
            ->where('groups.1.label', 'Field Offices')
            ->has('groups.1.units', 2)
            ->where('groups.1.units.1.name', 'Satellite One'));
    }

    public function test_each_office_lists_its_active_employees_without_contact_details(): void
    {
        $viewer = User::factory()->create();
        $unit = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        $serving = User::factory()->create(['organizational_unit_id' => $unit->id, 'position' => 'Administrative Aide']);
        User::factory()->inactive()->create(['organizational_unit_id' => $unit->id]);
        User::factory()->create(['organizational_unit_id' => null]);

        $this->actingAs($viewer)->get('/organizational-units')->assertInertia(fn ($page) => $page
            ->where('groups.0.units.0.employee_count', 1)
            ->where('groups.0.units.0.employees.0.name', $serving->name)
            ->where('groups.0.units.0.employees.0.position', 'Administrative Aide')
            ->missing('groups.0.units.0.employees.0.email')
            ->missing('groups.0.units.0.employees.0.birthday'));
    }

    public function test_inactive_unit_is_not_exposed(): void
    {
        $user = User::factory()->create();
        OrganizationalUnit::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/organizational-units');

        $response->assertInertia(fn ($page) => $page->has('groups', 0));
    }

    public function test_employees_are_ordered_by_salary_grade_with_contract_of_service_last(): void
    {
        $viewer = User::factory()->create();
        $unit = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);

        foreach (['Aide' => 1, 'Specialist' => 18, 'Consultant' => 24] as $title => $grade) {
            Position::query()->create(['title' => $title, 'salary_grade' => $grade]);
        }

        $aide = User::factory()->create(['organizational_unit_id' => $unit->id, 'position' => 'Aide']);
        $contract = User::factory()->create(['organizational_unit_id' => $unit->id, 'position' => 'Consultant']);
        Employee::factory()->create(['user_id' => $contract->id, 'employment_status' => EmploymentStatus::ContractOfService]);
        $specialist = User::factory()->create(['organizational_unit_id' => $unit->id, 'position' => 'Specialist']);

        $this->actingAs($viewer)->get('/organizational-units')->assertInertia(fn ($page) => $page
            ->where('groups.0.units.0.employees.0.name', $specialist->name)
            ->where('groups.0.units.0.employees.1.name', $aide->name)
            ->where('groups.0.units.0.employees.2.name', $contract->name));
    }

    public function test_the_regional_director_office_lists_director_iv_then_director_iii(): void
    {
        $viewer = User::factory()->create();
        $unit = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);

        Position::query()->create(['title' => 'Director III', 'salary_grade' => 26]);
        Position::query()->create(['title' => 'Director IV', 'salary_grade' => 28]);
        Position::query()->create(['title' => 'Aide', 'salary_grade' => 1]);

        $aide = User::factory()->create(['organizational_unit_id' => $unit->id, 'position' => 'Aide']);
        $directorThree = User::factory()->create(['organizational_unit_id' => $unit->id, 'position' => 'Director III']);
        $directorFour = User::factory()->create(['organizational_unit_id' => $unit->id, 'position' => 'Director IV']);

        $this->actingAs($viewer)->get('/organizational-units')->assertInertia(fn ($page) => $page
            ->where('groups.0.units.0.employees.0.name', $directorFour->name)
            ->where('groups.0.units.0.employees.0.role', 'Head')
            ->where('groups.0.units.0.employees.1.name', $directorThree->name)
            ->where('groups.0.units.0.employees.1.role', null)
            ->where('groups.0.units.0.employees.2.name', $aide->name));
    }

    public function test_invalid_unit_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/organizational-units/999999')->assertNotFound();
    }

    public function test_inactive_unit_detail_returns_404(): void
    {
        $user = User::factory()->create();
        $unit = OrganizationalUnit::factory()->inactive()->create();

        $this->actingAs($user)->get("/organizational-units/{$unit->id}")->assertNotFound();
    }

    public function test_active_unit_detail_renders_with_its_applications_and_services(): void
    {
        $user = User::factory()->create();
        $unit = OrganizationalUnit::factory()->create(['name' => 'Detail Unit']);
        Application::factory()->create(['organizational_unit_id' => $unit->id]);
        Service::factory()->create(['organizational_unit_id' => $unit->id]);

        $response = $this->actingAs($user)->get("/organizational-units/{$unit->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('organizationalUnit.name', 'Detail Unit')
            ->has('applications', 1)
            ->has('services', 1));
    }

    public function test_directory_index_only_lists_root_level_units(): void
    {
        $user = User::factory()->create();
        $parent = OrganizationalUnit::factory()->create(['name' => 'Parent Unit']);
        OrganizationalUnit::factory()->childOf($parent)->create(['name' => 'Child Unit']);

        $response = $this->actingAs($user)->get('/organizational-units');

        $response->assertInertia(fn ($page) => $page
            ->has('groups.0.units', 1)
            ->where('groups.0.units.0.name', 'Parent Unit'));
    }

    public function test_unit_detail_lists_its_active_children_and_parent(): void
    {
        $user = User::factory()->create();
        $parent = OrganizationalUnit::factory()->create(['name' => 'Parent Unit']);
        $child = OrganizationalUnit::factory()->childOf($parent)->create(['name' => 'Child Unit']);
        OrganizationalUnit::factory()->childOf($parent)->inactive()->create(['name' => 'Inactive Child']);

        $response = $this->actingAs($user)->get("/organizational-units/{$parent->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('children', 1)
            ->where('children.0.name', 'Child Unit'));

        $childResponse = $this->actingAs($user)->get("/organizational-units/{$child->id}");

        $childResponse->assertInertia(fn ($page) => $page->where('organizationalUnit.parent.name', 'Parent Unit'));
    }
}
