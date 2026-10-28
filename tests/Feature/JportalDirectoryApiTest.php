<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JPortal's server-to-server read of the public office directory: regional
 * support units and field/satellite offices, with whoever currently leads
 * each one.
 */
class JportalDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jportal.api_token' => 'secret-token']);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/jportal/directory')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/jportal/directory')->assertUnauthorized();

        config(['jportal.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/jportal/directory')->assertUnauthorized();
    }

    public function test_it_lists_only_active_support_units_and_field_offices_with_their_head(): void
    {
        $hrd = OrganizationalUnit::factory()->create([
            'name' => 'Human Resource Division',
            'unit_type' => UnitType::RegionalSupportUnit,
            'email' => 'ro08.hrd@csc.gov.ph',
        ]);
        OrganizationalUnit::factory()->inactive()->create(['unit_type' => UnitType::FieldOffice]);
        OrganizationalUnit::factory()->create(['unit_type' => UnitType::Other]);

        User::factory()->create([
            'name' => 'Jay M. Merelos',
            'position' => 'Chief Human Resource Specialist',
            'organizational_unit_id' => $hrd->id,
        ]);

        $rows = $this->withToken('secret-token')->getJson('/api/v1/jportal/directory')
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Human Resource Division', $rows[0]['name']);
        $this->assertSame('regional_support_unit', $rows[0]['unit_type']);
        $this->assertCount(1, $rows[0]['heads']);
        $this->assertSame('Jay M. Merelos', $rows[0]['heads'][0]['name']);
        $this->assertSame('Chief Human Resource Specialist', $rows[0]['heads'][0]['position']);
        $this->assertNull($rows[0]['heads'][0]['capacity_label']);
    }

    public function test_an_officer_in_charge_designation_shows_as_a_head_with_a_capacity(): void
    {
        $fo = OrganizationalUnit::factory()->create(['unit_type' => UnitType::FieldOffice]);
        $oic = User::factory()->create(['name' => 'Richmond A. Sanglay', 'position' => 'Supervising Human Resource Specialist']);
        $employee = Employee::factory()->create(['user_id' => $oic->id]);

        EmployeeAssignment::make([
            'kind' => 'designation',
            'as_head' => true,
            'head_capacity' => 'oic',
            'to_unit_id' => $fo->id,
            'effective_date' => today(),
        ])->forceFill(['employee_id' => $employee->id])->save();

        $rows = $this->withToken('secret-token')->getJson('/api/v1/jportal/directory')->assertOk()->json('data');

        $this->assertSame('Richmond A. Sanglay', $rows[0]['heads'][0]['name']);
        $this->assertSame('Officer-in-Charge', $rows[0]['heads'][0]['capacity_label']);
    }

    public function test_a_regional_support_unit_lists_both_its_director_iv_and_director_iii(): void
    {
        $ord = OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]);
        User::factory()->create(['name' => 'Atty. Marilyn E. Taldo', 'position' => 'Director IV', 'organizational_unit_id' => $ord->id]);
        User::factory()->create(['name' => 'Atty. Gina A. Crucio', 'position' => 'Director III', 'organizational_unit_id' => $ord->id]);
        // An inactive former Director III must not show up alongside them.
        User::factory()->inactive()->create(['name' => 'Atty. Flordeliza C. Algas', 'position' => 'Director III', 'organizational_unit_id' => $ord->id]);

        $rows = $this->withToken('secret-token')->getJson('/api/v1/jportal/directory')->assertOk()->json('data');

        $names = array_column($rows[0]['heads'], 'name');
        $this->assertCount(2, $rows[0]['heads']);
        $this->assertContains('Atty. Marilyn E. Taldo', $names);
        $this->assertContains('Atty. Gina A. Crucio', $names);
        $this->assertNotContains('Atty. Flordeliza C. Algas', $names);
    }
}
