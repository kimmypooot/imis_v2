<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/applications')->assertRedirect('/login');
    }

    public function test_unauthorized_user_cannot_view_the_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/applications')->assertForbidden();
    }

    public function test_authorized_user_can_view_the_index(): void
    {
        $user = $this->userWithPermission('applications.view');
        Application::factory()->create(['name' => 'Listed System']);

        $response = $this->actingAs($user)->get('/admin/applications');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Applications/Index')
            ->has('applications', 1));
    }

    public function test_index_supports_searching_by_name(): void
    {
        $user = $this->userWithPermission('applications.view');
        Application::factory()->create(['name' => 'Findable System']);
        Application::factory()->create(['name' => 'Something Else']);

        $response = $this->actingAs($user)->get('/admin/applications?search=Findable');

        $response->assertInertia(fn ($page) => $page
            ->has('applications', 1)
            ->where('applications.0.name', 'Findable System'));
    }

    public function test_index_supports_filtering_by_status(): void
    {
        $user = $this->userWithPermission('applications.view');
        Application::factory()->create();
        Application::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/admin/applications?status=inactive');

        $response->assertInertia(fn ($page) => $page->has('applications', 1));
    }

    public function test_index_supports_filtering_by_organizational_unit(): void
    {
        $user = $this->userWithPermission('applications.view');
        $unit = OrganizationalUnit::factory()->create();
        Application::factory()->create(['organizational_unit_id' => $unit->id]);
        Application::factory()->create();

        $response = $this->actingAs($user)->get("/admin/applications?organizational_unit_id={$unit->id}");

        $response->assertInertia(fn ($page) => $page->has('applications', 1));
    }

    public function test_unauthorized_user_cannot_create_an_application(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/admin/applications', [
            'name' => 'New System',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ])->assertForbidden();

        $this->assertDatabaseMissing('applications', ['name' => 'New System']);
    }

    public function test_authorized_user_can_create_an_application(): void
    {
        $user = $this->userWithPermission('applications.create');
        $unit = OrganizationalUnit::factory()->create();

        $response = $this->actingAs($user)->post('/admin/applications', [
            'name' => 'New System',
            'short_name' => 'NS',
            'organizational_unit_id' => $unit->id,
            'description' => 'A demonstration system.',
            'icon' => 'grid',
            'category' => 'records',
            'url' => 'https://example.test/system',
            'external' => true,
            'status' => 'active',
            'visibility' => 'authenticated',
            'featured' => true,
            'sort_order' => 3,
        ]);

        $response->assertRedirect('/admin/applications');
        $this->assertDatabaseHas('applications', [
            'name' => 'New System',
            'organizational_unit_id' => $unit->id,
            'featured' => true,
        ]);
    }

    public function test_required_fields_are_validated_on_create(): void
    {
        $user = $this->userWithPermission('applications.create');

        $response = $this->actingAs($user)->post('/admin/applications', []);

        $response->assertSessionHasErrors(['name', 'status', 'visibility', 'sort_order']);
    }

    public function test_invalid_url_is_rejected(): void
    {
        $user = $this->userWithPermission('applications.create');

        $response = $this->actingAs($user)->post('/admin/applications', [
            'name' => 'URL System',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('url');
    }

    public function test_non_http_url_scheme_is_rejected(): void
    {
        $user = $this->userWithPermission('applications.create');

        $response = $this->actingAs($user)->post('/admin/applications', [
            'name' => 'Scheme System',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'url' => 'javascript:alert(1)',
        ]);

        $response->assertSessionHasErrors('url');
    }

    public function test_mass_assignment_cannot_set_unlisted_attributes(): void
    {
        $user = $this->userWithPermission('applications.create');

        $this->actingAs($user)->post('/admin/applications', [
            'name' => 'Mass Assignment System',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'id' => 999999,
        ]);

        $application = Application::where('name', 'Mass Assignment System')->firstOrFail();
        $this->assertNotSame(999999, $application->id);
    }

    public function test_unauthorized_user_cannot_update_an_application(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();

        $this->actingAs($user)->put("/admin/applications/{$application->id}", [
            'name' => 'Renamed',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ])->assertForbidden();

        $this->assertDatabaseMissing('applications', ['name' => 'Renamed']);
    }

    public function test_authorized_user_can_update_an_application(): void
    {
        $user = $this->userWithPermission('applications.update');
        $application = Application::factory()->create();

        $response = $this->actingAs($user)->put("/admin/applications/{$application->id}", [
            'name' => 'Updated Name',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ]);

        $response->assertRedirect('/admin/applications');
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'name' => 'Updated Name']);
    }

    public function test_unauthorized_user_cannot_change_status(): void
    {
        $user = $this->userWithPermission('applications.update');
        $application = Application::factory()->create();

        $this->actingAs($user)->patch("/admin/applications/{$application->id}/status", [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->assertSame('active', $application->fresh()->status->value);
    }

    public function test_authorized_user_can_deactivate_and_reactivate_an_application(): void
    {
        $user = $this->userWithPermission('applications.archive');
        $application = Application::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/applications/{$application->id}/status", ['status' => 'inactive'])
            ->assertRedirect('/admin/applications');

        $this->assertSame('inactive', $application->fresh()->status->value);

        $this->actingAs($user)
            ->patch("/admin/applications/{$application->id}/status", ['status' => 'active'])
            ->assertRedirect('/admin/applications');

        $this->assertSame('active', $application->fresh()->status->value);
    }

    public function test_invalid_application_returns_404_for_edit(): void
    {
        $user = $this->userWithPermission('applications.update');

        $this->actingAs($user)->get('/admin/applications/999999/edit')->assertNotFound();
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
