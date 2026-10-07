<?php

namespace Tests\Feature\Admin;

use App\Enums\AssignmentKind;
use App\Enums\UnitType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationalUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/organizational-units')->assertRedirect('/login');
    }

    public function test_unauthorized_user_cannot_view_the_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/organizational-units')->assertForbidden();
    }

    public function test_authorized_user_can_view_the_index(): void
    {
        $user = $this->userWithPermission('organizational_units.view');
        OrganizationalUnit::factory()->create(['name' => 'Listed Unit']);

        $response = $this->actingAs($user)->get('/admin/organizational-units');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/OrganizationalUnits/Index')
            ->has('organizationalUnits', 1));
    }

    public function test_each_office_shows_its_head_by_position_or_designation(): void
    {
        $user = $this->userWithPermission('organizational_units.view');
        $division = OrganizationalUnit::factory()->create(['name' => 'A Division', 'sort_order' => 1, 'unit_type' => UnitType::RegionalSupportUnit]);
        $field = OrganizationalUnit::factory()->create(['name' => 'B Field Office', 'sort_order' => 2, 'unit_type' => UnitType::FieldOffice]);
        $vacant = OrganizationalUnit::factory()->create(['name' => 'C Field Office', 'sort_order' => 3, 'unit_type' => UnitType::FieldOffice]);

        User::factory()->create(['name' => 'Chief Cruz', 'position' => 'Chief Human Resource Specialist', 'organizational_unit_id' => $division->id]);
        User::factory()->create(['name' => 'Staff Santos', 'position' => 'Senior Human Resource Specialist', 'organizational_unit_id' => $division->id]);
        User::factory()->create(['name' => 'Dir. Reyes', 'position' => 'Director II', 'organizational_unit_id' => $field->id]);
        // An inactive Director II heads nothing; an OIC designation does.
        User::factory()->inactive()->create(['position' => 'Director II', 'organizational_unit_id' => $vacant->id]);
        $oic = Employee::factory()->for(User::factory()->state(['name' => 'Dir. Reyes OIC', 'position' => 'Director II', 'organizational_unit_id' => $field->id]))->create();

        $this->actingAs($user)->get('/admin/organizational-units')->assertInertia(fn ($page) => $page
            ->where('organizationalUnits.0.head', ['name' => 'Chief Cruz', 'position' => 'Chief Human Resource Specialist', 'capacity' => null])
            ->where('organizationalUnits.1.head', null) // two Director IIs: HR has to sort out who
            ->where('organizationalUnits.2.head', null));

        EmployeeAssignment::query()->forceCreate([
            'employee_id' => $oic->id, 'kind' => AssignmentKind::Designation, 'as_head' => true,
            'to_unit_id' => $vacant->id, 'effective_date' => today()->subDay()->toDateString(),
        ]);

        $this->actingAs($user)->get('/admin/organizational-units')->assertInertia(fn ($page) => $page
            ->where('organizationalUnits.2.head', ['name' => 'Dir. Reyes OIC', 'position' => 'Director II', 'capacity' => 'Officer-in-Charge']));
    }

    public function test_index_supports_searching_by_name_or_code(): void
    {
        $user = $this->userWithPermission('organizational_units.view');
        OrganizationalUnit::factory()->create(['name' => 'Findable Unit', 'code' => 'FIND-01']);
        OrganizationalUnit::factory()->create(['name' => 'Something Else', 'code' => 'OTHER-01']);

        $response = $this->actingAs($user)->get('/admin/organizational-units?search=Findable');

        $response->assertInertia(fn ($page) => $page
            ->has('organizationalUnits', 1)
            ->where('organizationalUnits.0.name', 'Findable Unit'));
    }

    public function test_unauthorized_user_cannot_create_a_unit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/admin/organizational-units', [
            'code' => 'NEW-01',
            'name' => 'New Unit',
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
        ])->assertForbidden();

        $this->assertDatabaseMissing('organizational_units', ['code' => 'NEW-01']);
    }

    public function test_authorized_user_can_create_a_unit(): void
    {
        $user = $this->userWithPermission('organizational_units.create');

        $response = $this->actingAs($user)->post('/admin/organizational-units', [
            'code' => 'NEW-01',
            'name' => 'New Unit',
            'unit_type' => 'field_office',
            'status' => 'active',
            'sort_order' => 5,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organizational_units', ['code' => 'NEW-01', 'name' => 'New Unit']);

        $created = OrganizationalUnit::where('code', 'NEW-01')->firstOrFail();
        $this->assertNotNull($created->slug);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $user = $this->userWithPermission('organizational_units.create');
        OrganizationalUnit::factory()->create(['code' => 'DUP-01']);

        $response = $this->actingAs($user)->post('/admin/organizational-units', [
            'code' => 'DUP-01',
            'name' => 'Duplicate Attempt',
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_required_fields_are_validated_on_create(): void
    {
        $user = $this->userWithPermission('organizational_units.create');

        $response = $this->actingAs($user)->post('/admin/organizational-units', []);

        $response->assertSessionHasErrors(['code', 'name', 'unit_type', 'status', 'sort_order']);
    }

    public function test_invalid_website_url_is_rejected(): void
    {
        $user = $this->userWithPermission('organizational_units.create');

        $response = $this->actingAs($user)->post('/admin/organizational-units', [
            'code' => 'URL-01',
            'name' => 'URL Unit',
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
            'website_url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('website_url');
    }

    public function test_mass_assignment_cannot_set_unlisted_attributes(): void
    {
        $user = $this->userWithPermission('organizational_units.create');

        $this->actingAs($user)->post('/admin/organizational-units', [
            'code' => 'MASS-01',
            'name' => 'Mass Assignment Unit',
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
            'id' => 999999,
        ]);

        $unit = OrganizationalUnit::where('code', 'MASS-01')->firstOrFail();
        $this->assertNotSame(999999, $unit->id);
    }

    public function test_unauthorized_user_cannot_update_a_unit(): void
    {
        $user = User::factory()->create();
        $unit = OrganizationalUnit::factory()->create();

        $this->actingAs($user)->put("/admin/organizational-units/{$unit->id}", [
            'code' => $unit->code,
            'name' => 'Renamed',
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
        ])->assertForbidden();

        $this->assertDatabaseMissing('organizational_units', ['name' => 'Renamed']);
    }

    public function test_authorized_user_can_update_a_unit(): void
    {
        $user = $this->userWithPermission('organizational_units.update');
        $unit = OrganizationalUnit::factory()->create();

        $response = $this->actingAs($user)->put("/admin/organizational-units/{$unit->id}", [
            'code' => $unit->code,
            'name' => 'Updated Name',
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organizational_units', ['id' => $unit->id, 'name' => 'Updated Name']);
    }

    public function test_self_parent_assignment_is_rejected(): void
    {
        $user = $this->userWithPermission('organizational_units.update');
        $unit = OrganizationalUnit::factory()->create();

        $response = $this->actingAs($user)->put("/admin/organizational-units/{$unit->id}", [
            'code' => $unit->code,
            'name' => $unit->name,
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
            'parent_id' => $unit->id,
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertNull($unit->fresh()->parent_id);
    }

    public function test_circular_hierarchy_via_descendant_as_parent_is_rejected(): void
    {
        $user = $this->userWithPermission('organizational_units.update');
        $root = OrganizationalUnit::factory()->create(['name' => 'Root']);
        $child = OrganizationalUnit::factory()->childOf($root)->create(['name' => 'Child']);
        $grandchild = OrganizationalUnit::factory()->childOf($child)->create(['name' => 'Grandchild']);

        $response = $this->actingAs($user)->put("/admin/organizational-units/{$root->id}", [
            'code' => $root->code,
            'name' => $root->name,
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
            'parent_id' => $grandchild->id,
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_valid_reparenting_is_accepted(): void
    {
        $user = $this->userWithPermission('organizational_units.update');
        $unitA = OrganizationalUnit::factory()->create(['name' => 'Unit A']);
        $unitB = OrganizationalUnit::factory()->create(['name' => 'Unit B']);

        $response = $this->actingAs($user)->put("/admin/organizational-units/{$unitB->id}", [
            'code' => $unitB->code,
            'name' => $unitB->name,
            'unit_type' => 'other',
            'status' => 'active',
            'sort_order' => 0,
            'parent_id' => $unitA->id,
        ]);

        $response->assertRedirect();
        $this->assertSame($unitA->id, $unitB->fresh()->parent_id);
    }

    public function test_unauthorized_user_cannot_change_status(): void
    {
        $user = $this->userWithPermission('organizational_units.update');
        $unit = OrganizationalUnit::factory()->create();

        $this->actingAs($user)->patch("/admin/organizational-units/{$unit->id}/status", [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->assertSame('active', $unit->fresh()->status->value);
    }

    public function test_authorized_user_can_deactivate_and_reactivate_a_unit(): void
    {
        $user = $this->userWithPermission('organizational_units.archive');
        $unit = OrganizationalUnit::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/organizational-units/{$unit->id}/status", ['status' => 'inactive'])
            ->assertRedirect();

        $this->assertSame('inactive', $unit->fresh()->status->value);

        $this->actingAs($user)
            ->patch("/admin/organizational-units/{$unit->id}/status", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', $unit->fresh()->status->value);
    }

    public function test_invalid_unit_returns_404_for_edit(): void
    {
        $user = $this->userWithPermission('organizational_units.update');

        $this->actingAs($user)->put('/admin/organizational-units/999999', [])->assertNotFound();
    }

    private function userWithPermission(string $permission): User
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
