<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ETTS's server-to-server read of the people directory, to suggest a routing
 * slip addressee or signatory.
 */
class EttsEmployeesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['etts.api_token' => 'secret-token']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/etts/employees')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/etts/employees')->assertUnauthorized();

        config(['etts.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/etts/employees')->assertUnauthorized();
    }

    public function test_it_lists_active_employees_with_their_office_and_nothing_private(): void
    {
        $office = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'name' => 'Leyte Field Office', 'unit_type' => UnitType::FieldOffice]);
        $section = OrganizationalUnit::factory()->create(['parent_id' => $office->id, 'unit_type' => UnitType::Other]);

        User::factory()->create(['name' => 'Ana Reyes', 'position' => 'Director IV', 'organizational_unit_id' => $section->id]);
        User::factory()->create(['name' => 'Gone Person', 'status' => UserStatus::Inactive]);

        $data = $this->withToken('secret-token')->getJson('/api/v1/etts/employees')->assertOk()->json('data');

        $this->assertSame(['Ana Reyes'], array_column($data, 'name'));
        $this->assertSame('Director IV', $data[0]['position']);
        $this->assertSame('Leyte Field Office', $data[0]['office_name']);
        $this->assertSame('LFO-I', $data[0]['office_code']);
        $this->assertSame(['id', 'name', 'position', 'office_name', 'office_code'], array_keys($data[0]));
    }
}
