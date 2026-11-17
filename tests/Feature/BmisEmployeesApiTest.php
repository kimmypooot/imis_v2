<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BMIS's server-to-server read of the current employees, for its Employee manager and organization chart.
 */
class BmisEmployeesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bmis.api_token' => 'secret-token']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/bmis/employees')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/bmis/employees')->assertUnauthorized();

        config(['bmis.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/bmis/employees')->assertUnauthorized();
    }

    public function test_it_lists_active_employees_with_their_unit_and_office(): void
    {
        $office = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'name' => 'Leyte Field Office', 'unit_type' => UnitType::FieldOffice]);
        $section = OrganizationalUnit::factory()->create(['parent_id' => $office->id, 'name' => 'Cashier Section', 'unit_type' => UnitType::Other]);

        User::factory()->create(['name' => 'Ana Reyes', 'position' => 'Administrative Aide', 'organizational_unit_id' => $section->id]);
        User::factory()->create(['name' => 'Gone Person', 'status' => UserStatus::Inactive, 'organizational_unit_id' => $section->id]);

        $rows = collect($this->withToken('secret-token')->getJson('/api/v1/bmis/employees')->assertOk()->json('data'));

        $this->assertSame(['Ana Reyes'], $rows->pluck('name')->all());
        $this->assertSame('Cashier Section', $rows->first()['unit_name']);
        $this->assertSame('Leyte Field Office', $rows->first()['office_name']);
        $this->assertSame('LFO-I', $rows->first()['office_code']);
    }
}
