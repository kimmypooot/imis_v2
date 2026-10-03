<?php

namespace Tests\Feature\Admin;

use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/services')->assertRedirect('/login');
    }

    public function test_unauthorized_user_cannot_view_the_index(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/services')->assertForbidden();
    }

    public function test_authorized_user_can_view_the_index(): void
    {
        $user = $this->userWithPermission('services.view');
        Service::factory()->create(['name' => 'Listed Service']);

        $response = $this->actingAs($user)->get('/admin/services');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Services/Index')
            ->has('services', 1));
    }

    public function test_index_supports_searching_by_name(): void
    {
        $user = $this->userWithPermission('services.view');
        Service::factory()->create(['name' => 'Findable Service']);
        Service::factory()->create(['name' => 'Something Else']);

        $response = $this->actingAs($user)->get('/admin/services?search=Findable');

        $response->assertInertia(fn ($page) => $page
            ->has('services', 1)
            ->where('services.0.name', 'Findable Service'));
    }

    public function test_index_supports_filtering_by_category(): void
    {
        $user = $this->userWithPermission('services.view');
        $categoryA = ServiceCategory::factory()->create();
        $categoryB = ServiceCategory::factory()->create();
        Service::factory()->create(['category_id' => $categoryA->id]);
        Service::factory()->create(['category_id' => $categoryB->id]);

        $response = $this->actingAs($user)->get("/admin/services?category_id={$categoryA->id}");

        $response->assertInertia(fn ($page) => $page->has('services', 1));
    }

    public function test_index_supports_filtering_by_organizational_unit(): void
    {
        $user = $this->userWithPermission('services.view');
        $unit = OrganizationalUnit::factory()->create();
        Service::factory()->create(['organizational_unit_id' => $unit->id]);
        Service::factory()->create();

        $response = $this->actingAs($user)->get("/admin/services?organizational_unit_id={$unit->id}");

        $response->assertInertia(fn ($page) => $page->has('services', 1));
    }

    public function test_unauthorized_user_cannot_create_a_service(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/admin/services', [
            'name' => 'New Service',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ])->assertForbidden();

        $this->assertDatabaseMissing('services', ['name' => 'New Service']);
    }

    public function test_authorized_user_can_create_a_service(): void
    {
        $user = $this->userWithPermission('services.create');
        $category = ServiceCategory::factory()->create();
        $unit = OrganizationalUnit::factory()->create();

        $response = $this->actingAs($user)->post('/admin/services', [
            'name' => 'New Service',
            'short_name' => 'NS',
            'category_id' => $category->id,
            'organizational_unit_id' => $unit->id,
            'description' => 'A demonstration service.',
            'purpose' => 'Purpose text',
            'requirements' => 'Requirements text',
            'procedure' => 'Procedure text',
            'fees' => 'Free',
            'processing_time' => '1 day',
            'contact_information' => 'contact@example.test',
            'external_url' => 'https://example.test/service',
            'status' => 'active',
            'visibility' => 'authenticated',
            'featured' => true,
            'sort_order' => 3,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('services', [
            'name' => 'New Service',
            'category_id' => $category->id,
            'organizational_unit_id' => $unit->id,
            'featured' => true,
        ]);

        $created = Service::where('name', 'New Service')->firstOrFail();
        $this->assertNotNull($created->slug);
    }

    public function test_slug_is_generated_uniquely_for_duplicate_names(): void
    {
        $user = $this->userWithPermission('services.create');

        $payload = [
            'name' => 'Duplicate Name Service',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ];

        $this->actingAs($user)->post('/admin/services', $payload);
        $this->actingAs($user)->post('/admin/services', $payload);

        $services = Service::where('name', 'Duplicate Name Service')->get();
        $this->assertCount(2, $services);
        $this->assertNotSame($services[0]->slug, $services[1]->slug);
    }

    public function test_required_fields_are_validated_on_create(): void
    {
        $user = $this->userWithPermission('services.create');

        $response = $this->actingAs($user)->post('/admin/services', []);

        $response->assertSessionHasErrors(['name', 'status', 'visibility', 'sort_order']);
    }

    public function test_invalid_external_url_is_rejected(): void
    {
        $user = $this->userWithPermission('services.create');

        $response = $this->actingAs($user)->post('/admin/services', [
            'name' => 'URL Service',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'external_url' => 'not-a-url',
        ]);

        $response->assertSessionHasErrors('external_url');
    }

    public function test_non_http_external_url_scheme_is_rejected(): void
    {
        $user = $this->userWithPermission('services.create');

        $response = $this->actingAs($user)->post('/admin/services', [
            'name' => 'Scheme Service',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'external_url' => 'javascript:alert(1)',
        ]);

        $response->assertSessionHasErrors('external_url');
    }

    public function test_mass_assignment_cannot_set_unlisted_attributes(): void
    {
        $user = $this->userWithPermission('services.create');

        $this->actingAs($user)->post('/admin/services', [
            'name' => 'Mass Assignment Service',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
            'id' => 999999,
        ]);

        $service = Service::where('name', 'Mass Assignment Service')->firstOrFail();
        $this->assertNotSame(999999, $service->id);
    }

    public function test_unauthorized_user_cannot_update_a_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create();

        $this->actingAs($user)->put("/admin/services/{$service->id}", [
            'name' => 'Renamed',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ])->assertForbidden();

        $this->assertDatabaseMissing('services', ['name' => 'Renamed']);
    }

    public function test_authorized_user_can_update_a_service(): void
    {
        $user = $this->userWithPermission('services.update');
        $service = Service::factory()->create();

        $response = $this->actingAs($user)->put("/admin/services/{$service->id}", [
            'name' => 'Updated Name',
            'status' => 'active',
            'visibility' => 'authenticated',
            'sort_order' => 0,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Updated Name']);
    }

    public function test_unauthorized_user_cannot_change_status(): void
    {
        $user = $this->userWithPermission('services.update');
        $service = Service::factory()->create();

        $this->actingAs($user)->patch("/admin/services/{$service->id}/status", [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->assertSame('active', $service->fresh()->status->value);
    }

    public function test_authorized_user_can_deactivate_and_reactivate_a_service(): void
    {
        $user = $this->userWithPermission('services.archive');
        $service = Service::factory()->create();

        $this->actingAs($user)
            ->patch("/admin/services/{$service->id}/status", ['status' => 'inactive'])
            ->assertRedirect();

        $this->assertSame('inactive', $service->fresh()->status->value);

        $this->actingAs($user)
            ->patch("/admin/services/{$service->id}/status", ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', $service->fresh()->status->value);
    }

    public function test_invalid_service_returns_404_for_edit(): void
    {
        $user = $this->userWithPermission('services.update');

        $this->actingAs($user)->put('/admin/services/999999', [])->assertNotFound();
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
