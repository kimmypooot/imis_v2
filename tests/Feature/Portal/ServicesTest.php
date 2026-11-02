<?php

namespace Tests\Feature\Portal;

use App\Models\OrganizationalUnit;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_services(): void
    {
        $this->get('/services')->assertRedirect('/login');
    }

    public function test_active_service_renders_in_the_directory(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['name' => 'Visible Service']);

        $response = $this->actingAs($user)->get('/services');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Portal/Services/Index')
            ->has('services', 1)
            ->where('services.0.name', $service->name));
    }

    public function test_turning_services_off_closes_the_pages_and_empties_the_home_and_search(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['name' => 'Hidden Service']);
        SettingRegistry::sync();
        Setting::query()->where('key', 'portal_services_enabled')->first()->forceFill(['value' => '0'])->save();

        $this->actingAs($user)->get('/services')->assertNotFound();
        $this->actingAs($user)->get("/services/{$service->id}")->assertNotFound();
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('services', 0)
            ->where('flags.services', false));
    }

    public function test_inactive_service_is_not_exposed(): void
    {
        $user = User::factory()->create();
        Service::factory()->inactive()->create();

        $response = $this->actingAs($user)->get('/services');

        $response->assertInertia(fn ($page) => $page->has('services', 0));
    }

    public function test_invalid_service_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/services/999999')->assertNotFound();
    }

    public function test_inactive_service_detail_returns_404(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->inactive()->create();

        $this->actingAs($user)->get("/services/{$service->id}")->assertNotFound();
    }

    public function test_active_service_detail_renders(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['name' => 'Detail Service']);

        $response = $this->actingAs($user)->get("/services/{$service->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('service.name', 'Detail Service'));
    }

    public function test_directory_search_matches_name(): void
    {
        $user = User::factory()->create();
        Service::factory()->create(['name' => 'Findable Service']);
        Service::factory()->create(['name' => 'Something Else']);

        $response = $this->actingAs($user)->get('/services?search=Findable');

        $response->assertInertia(fn ($page) => $page
            ->has('services', 1)
            ->where('services.0.name', 'Findable Service'));
    }

    public function test_directory_can_be_filtered_by_category(): void
    {
        $user = User::factory()->create();
        $categoryA = ServiceCategory::factory()->create();
        $categoryB = ServiceCategory::factory()->create();
        Service::factory()->create(['category_id' => $categoryA->id]);
        Service::factory()->create(['category_id' => $categoryB->id]);

        $response = $this->actingAs($user)->get("/services?category_id={$categoryA->id}");

        $response->assertInertia(fn ($page) => $page->has('services', 1));
    }

    public function test_directory_can_be_filtered_by_organizational_unit(): void
    {
        $user = User::factory()->create();
        $unit = OrganizationalUnit::factory()->create();
        Service::factory()->create(['organizational_unit_id' => $unit->id]);
        Service::factory()->create();

        $response = $this->actingAs($user)->get("/services?organizational_unit_id={$unit->id}");

        $response->assertInertia(fn ($page) => $page->has('services', 1));
    }

    public function test_featured_services_are_prioritized_first(): void
    {
        $user = User::factory()->create();
        Service::factory()->create(['name' => 'Regular Service', 'sort_order' => 0]);
        Service::factory()->featured()->create(['name' => 'Featured Service', 'sort_order' => 5]);

        $response = $this->actingAs($user)->get('/services');

        $response->assertInertia(fn ($page) => $page->where('services.0.name', 'Featured Service'));
    }
}
