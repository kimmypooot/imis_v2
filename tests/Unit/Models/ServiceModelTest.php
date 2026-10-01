<?php

namespace Tests\Unit\Models;

use App\Enums\Visibility;
use App\Models\OrganizationalUnit;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_belongs_to_a_category(): void
    {
        $category = ServiceCategory::factory()->create();
        $service = Service::factory()->create(['category_id' => $category->id]);

        $this->assertTrue($service->category->is($category));
    }

    public function test_service_belongs_to_an_organizational_unit(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $service = Service::factory()->create(['organizational_unit_id' => $unit->id]);

        $this->assertTrue($service->organizationalUnit->is($unit));
        $this->assertTrue($unit->services->contains($service));
    }

    public function test_service_can_exist_without_a_category_or_unit(): void
    {
        $service = Service::factory()->create(['category_id' => null, 'organizational_unit_id' => null]);

        $this->assertNull($service->category);
        $this->assertNull($service->organizationalUnit);
    }

    public function test_slug_is_generated_uniquely_for_duplicate_names(): void
    {
        $first = Service::generateUniqueSlug('Sample Service');
        Service::factory()->create(['slug' => $first]);

        $second = Service::generateUniqueSlug('Sample Service');

        $this->assertNotSame($first, $second);
    }

    public function test_service_visibility_is_enforced_via_shared_trait(): void
    {
        $service = Service::factory()->create(['visibility' => Visibility::Administrative]);
        $user = User::factory()->create();

        $this->assertFalse($service->isVisibleTo($user));
        $this->assertFalse($service->isVisibleTo(null));
    }

    public function test_category_active_scope_excludes_inactive_categories(): void
    {
        ServiceCategory::factory()->create();
        ServiceCategory::factory()->inactive()->create();

        $active = ServiceCategory::query()->active()->get();

        $this->assertCount(1, $active);
    }
}
