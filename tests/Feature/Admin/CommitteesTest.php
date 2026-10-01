<?php

namespace Tests\Feature\Admin;

use App\Models\Committee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitteesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/committees')->assertRedirect('/login');
    }

    public function test_unauthorized_user_cannot_view_the_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/committees')->assertForbidden();
    }

    public function test_authorized_user_can_view_the_index(): void
    {
        $user = $this->userWithPermission('committees.view');
        Committee::factory()->create(['name' => 'Listed Committee']);

        $response = $this->actingAs($user)->get('/admin/committees');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Committees/Index')
            ->has('committees', 1));
    }

    public function test_index_supports_searching_by_name(): void
    {
        $user = $this->userWithPermission('committees.view');
        Committee::factory()->create(['name' => 'Findable Committee']);
        Committee::factory()->create(['name' => 'Something Else']);

        $response = $this->actingAs($user)->get('/admin/committees?search=Findable');

        $response->assertInertia(fn ($page) => $page
            ->has('committees', 1)
            ->where('committees.0.name', 'Findable Committee'));
    }

    public function test_index_supports_filtering_by_status(): void
    {
        $user = $this->userWithPermission('committees.view');
        Committee::factory()->create();
        Committee::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/admin/committees?status=inactive');

        $response->assertInertia(fn ($page) => $page->has('committees', 1));
    }

    public function test_unauthorized_user_cannot_create_a_committee(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/admin/committees', [
            'name' => 'New Committee',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseMissing('committees', ['name' => 'New Committee']);
    }

    public function test_authorized_user_can_create_a_committee(): void
    {
        $user = $this->userWithPermission('committees.create');

        $response = $this->actingAs($user)->post('/admin/committees', [
            'name' => 'New Committee',
            'short_name' => 'NC',
            'description' => 'A demonstration committee.',
            'status' => 'active',
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-12-31',
        ]);

        $committee = Committee::where('name', 'New Committee')->firstOrFail();
        $response->assertRedirect("/admin/committees/{$committee->id}");
        $this->assertDatabaseHas('committees', ['name' => 'New Committee', 'short_name' => 'NC']);
    }

    public function test_required_fields_are_validated_on_create(): void
    {
        $user = $this->userWithPermission('committees.create');

        $response = $this->actingAs($user)->post('/admin/committees', []);

        $response->assertSessionHasErrors(['name', 'status']);
    }

    public function test_effective_until_must_not_precede_effective_from(): void
    {
        $user = $this->userWithPermission('committees.create');

        $response = $this->actingAs($user)->post('/admin/committees', [
            'name' => 'Date Committee',
            'status' => 'active',
            'effective_from' => '2026-06-01',
            'effective_until' => '2026-01-01',
        ]);

        $response->assertSessionHasErrors('effective_until');
    }

    public function test_mass_assignment_cannot_set_unlisted_attributes(): void
    {
        $user = $this->userWithPermission('committees.create');

        $this->actingAs($user)->post('/admin/committees', [
            'name' => 'Mass Assignment Committee',
            'status' => 'active',
            'id' => 999999,
        ]);

        $committee = Committee::where('name', 'Mass Assignment Committee')->firstOrFail();
        $this->assertNotSame(999999, $committee->id);
    }

    public function test_unauthorized_user_cannot_view_a_committee(): void
    {
        $user = User::factory()->create();
        $committee = Committee::factory()->create();

        $this->actingAs($user)->get("/admin/committees/{$committee->id}")->assertForbidden();
    }

    public function test_authorized_user_can_view_a_committee(): void
    {
        $user = $this->userWithPermission('committees.view');
        $committee = Committee::factory()->create();

        $response = $this->actingAs($user)->get("/admin/committees/{$committee->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Committees/Show'));
    }

    public function test_unauthorized_user_cannot_update_a_committee(): void
    {
        $user = User::factory()->create();
        $committee = Committee::factory()->create();

        $this->actingAs($user)->put("/admin/committees/{$committee->id}", [
            'name' => 'Renamed',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertDatabaseMissing('committees', ['name' => 'Renamed']);
    }

    public function test_authorized_user_can_update_a_committee(): void
    {
        $user = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();

        $response = $this->actingAs($user)->put("/admin/committees/{$committee->id}", [
            'name' => 'Updated Name',
            'status' => 'active',
        ]);

        $response->assertRedirect("/admin/committees/{$committee->id}");
        $this->assertDatabaseHas('committees', ['id' => $committee->id, 'name' => 'Updated Name']);
    }

    public function test_unauthorized_user_cannot_change_status(): void
    {
        $user = $this->userWithPermission('committees.update');
        $committee = Committee::factory()->create();

        $this->actingAs($user)->patch("/admin/committees/{$committee->id}/status", [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->assertSame('active', $committee->fresh()->status->value);
    }

    public function test_authorized_user_can_deactivate_and_reactivate_a_committee(): void
    {
        $user = $this->userWithPermission('committees.archive');
        $committee = Committee::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/committees/{$committee->id}/status", ['status' => 'inactive'])
            ->assertRedirect("/admin/committees/{$committee->id}");

        $this->assertSame('inactive', $committee->fresh()->status->value);

        $this->actingAs($user)
            ->patch("/admin/committees/{$committee->id}/status", ['status' => 'active'])
            ->assertRedirect("/admin/committees/{$committee->id}");

        $this->assertSame('active', $committee->fresh()->status->value);
    }

    public function test_invalid_committee_returns_404_for_show(): void
    {
        $user = $this->userWithPermission('committees.view');

        $this->actingAs($user)->get('/admin/committees/999999')->assertNotFound();
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
