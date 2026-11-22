<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TIMS's server-to-server read of the employees it may add as staff: the field
 * and satellite offices, the ORD and the Human Resource Division, and nobody else.
 */
class TimsEmployeesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tims.api_token' => 'secret-token', 'tims.application' => 'TIMS']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/tims/employees')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/tims/employees')->assertUnauthorized();

        config(['tims.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/tims/employees')->assertUnauthorized();
    }

    public function test_it_lists_only_field_offices_the_ord_and_hrd_with_any_tims_role(): void
    {
        $fieldOffice = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'name' => 'Leyte Field Office', 'unit_type' => UnitType::FieldOffice]);
        $satellite = OrganizationalUnit::factory()->create(['code' => 'WLSO', 'unit_type' => UnitType::SatelliteOffice]);
        $ord = OrganizationalUnit::factory()->create(['code' => 'ORD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $hrd = OrganizationalUnit::factory()->create(['code' => 'HRD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $esd = OrganizationalUnit::factory()->create(['code' => 'ESD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $hrdSection = OrganizationalUnit::factory()->create(['parent_id' => $hrd->id, 'unit_type' => UnitType::Other]);

        $place = fn (OrganizationalUnit $unit, array $attributes = []) => User::factory()->create(['organizational_unit_id' => $unit->id] + $attributes);

        $ana = $place($fieldOffice, ['last_name' => 'Abad']);
        $place($satellite, ['last_name' => 'Bello']);
        $place($ord, ['last_name' => 'Cruz']);
        $hr = $place($hrdSection, ['last_name' => 'Diaz']);
        $place($esd, ['last_name' => 'Esto']);                                              // another division
        $place($fieldOffice, ['last_name' => 'Fuentes', 'status' => UserStatus::Inactive]); // inactive

        $tims = Application::factory()->create(['short_name' => 'TIMS']);
        $admin = ApplicationRole::factory()->for($tims)->create(['code' => 'admin']);
        ApplicationAccess::factory()->forRole($admin)->create(['user_id' => $hr->id]);

        $rows = collect($this->withToken('secret-token')->getJson('/api/v1/tims/employees')->assertOk()->json('data'));

        $this->assertSame(['Abad', 'Bello', 'Cruz', 'Diaz'], $rows->pluck('lname')->all());

        $first = $rows->firstWhere('id', $ana->id);
        $this->assertSame('LFO-I', $first['office_code']);
        $this->assertSame('field_office', $first['office_type']);
        $this->assertNull($first['role_code']);

        // A section inside HRD belongs to HRD, and the grant comes through.
        $this->assertSame('HRD', $rows->firstWhere('id', $hr->id)['office_code']);
        $this->assertSame('admin', $rows->firstWhere('id', $hr->id)['role_code']);
    }
}
