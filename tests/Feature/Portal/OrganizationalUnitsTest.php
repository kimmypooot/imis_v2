<?php

namespace Tests\Feature\Portal;

use App\Models\Application;
use App\Models\OrganizationalUnit;
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
        $unit = OrganizationalUnit::factory()->create(['name' => 'Visible Unit']);

        $response = $this->actingAs($user)->get('/organizational-units');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/OrganizationalUnits/Index')
            ->has('organizationalUnits', 1)
            ->where('organizationalUnits.0.name', $unit->name));
    }

    public function test_inactive_unit_is_not_exposed(): void
    {
        $user = User::factory()->create();
        OrganizationalUnit::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/organizational-units');

        $response->assertInertia(fn ($page) => $page->has('organizationalUnits', 0));
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
            ->has('organizationalUnits', 1)
            ->where('organizationalUnits.0.name', 'Parent Unit'));
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
