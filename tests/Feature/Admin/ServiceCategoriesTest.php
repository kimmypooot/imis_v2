<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/service-categories')->assertRedirect('/login');
    }

    public function test_unauthorized_user_cannot_view_the_index(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/service-categories')->assertForbidden();
    }

    public function test_index_lists_categories_in_display_order_with_service_counts(): void
    {
        $user = $this->userWithPermission('services.view');
        $second = ServiceCategory::factory()->create(['name' => 'Alpha', 'sort_order' => 2]);
        $first = ServiceCategory::factory()->inactive()->create(['name' => 'Zulu', 'sort_order' => 1]);
        Service::factory()->count(2)->create(['category_id' => $second->id]);

        $response = $this->actingAs($user)->get('/admin/service-categories');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ServiceCategories/Index')
            ->has('categories', 2)
            ->where('categories.0.id', $first->id)
            ->where('categories.0.services_count', 0)
            ->where('categories.1.id', $second->id)
            ->where('categories.1.services_count', 2));
    }

    public function test_authorized_user_can_create_a_category(): void
    {
        $user = $this->userWithPermission('services.create');

        $this->actingAs($user)->post('/admin/service-categories', [
            'name' => 'Examination Services',
            'description' => 'Civil service examinations.',
            'status' => 'active',
            'sort_order' => 3,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('service_categories', [
            'name' => 'Examination Services',
            'slug' => 'examination-services',
            'sort_order' => 3,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'service_category.created']);
    }

    public function test_user_without_create_permission_cannot_create(): void
    {
        $user = $this->userWithPermission('services.view');

        $this->actingAs($user)->post('/admin/service-categories', [
            'name' => 'Nope', 'status' => 'active', 'sort_order' => 0,
        ])->assertForbidden();

        $this->assertDatabaseCount('service_categories', 0);
    }

    public function test_name_is_required_and_unique(): void
    {
        $user = $this->userWithPermission('services.create');
        ServiceCategory::factory()->create(['name' => 'Taken']);

        $this->actingAs($user)->post('/admin/service-categories', ['name' => '', 'status' => 'active', 'sort_order' => 0])
            ->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/admin/service-categories', ['name' => 'Taken', 'status' => 'active', 'sort_order' => 0])
            ->assertSessionHasErrors('name');
    }

    public function test_slug_cannot_be_mass_assigned(): void
    {
        $user = $this->userWithPermission('services.create');

        $this->actingAs($user)->post('/admin/service-categories', [
            'name' => 'Legal Services', 'slug' => 'hijacked', 'status' => 'active', 'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('service_categories', ['name' => 'Legal Services', 'slug' => 'legal-services']);
    }

    public function test_authorized_user_can_update_a_category_and_keep_its_own_name(): void
    {
        $user = $this->userWithPermission('services.update');
        $category = ServiceCategory::factory()->create(['name' => 'Original']);

        $this->actingAs($user)->put("/admin/service-categories/{$category->id}", [
            'name' => 'Original', 'description' => 'Changed.', 'status' => 'active', 'sort_order' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('service_categories', ['id' => $category->id, 'description' => 'Changed.', 'sort_order' => 5]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'service_category.updated']);
    }

    public function test_user_without_update_permission_cannot_update(): void
    {
        $user = $this->userWithPermission('services.view');
        $category = ServiceCategory::factory()->create();

        $this->actingAs($user)->put("/admin/service-categories/{$category->id}", [
            'name' => 'Changed', 'status' => 'active', 'sort_order' => 0,
        ])->assertForbidden();
    }

    public function test_deactivating_keeps_services_linked_and_hides_it_from_pickers(): void
    {
        $user = $this->userWithPermission('services.archive');
        $user->roles()->first()->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => 'services.view'], ['display_name' => 'services.view']),
        );
        $category = ServiceCategory::factory()->create();
        $service = Service::factory()->create(['category_id' => $category->id]);

        $this->actingAs($user)->patch("/admin/service-categories/{$category->id}/status", ['status' => 'inactive'])
            ->assertSessionHasNoErrors();

        $this->assertSame('inactive', $category->fresh()->status->value);
        $this->assertSame($category->id, $service->fresh()->category_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'service_category.status_changed']);

        $this->actingAs($user)->get('/admin/services')
            ->assertInertia(fn ($page) => $page->has('categoryOptions', 0));
    }

    public function test_user_without_archive_permission_cannot_change_status(): void
    {
        $user = $this->userWithPermission('services.update');
        $category = ServiceCategory::factory()->create();

        $this->actingAs($user)->patch("/admin/service-categories/{$category->id}/status", ['status' => 'inactive'])
            ->assertForbidden();
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
