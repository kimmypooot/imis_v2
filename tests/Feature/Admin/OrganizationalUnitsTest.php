<?php

namespace Tests\Feature\Admin;

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
