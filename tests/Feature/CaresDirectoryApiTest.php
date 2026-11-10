<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CARES's server-to-server read of the people directory: employees to name as
 * action officer, the staff of a Field Office who prepare its reports, and who
 * heads each office today (the one who approves them).
 */
class CaresDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cares.api_token' => 'secret-token']);
    }

    private function directory(): array
    {
        return $this->withToken('secret-token')->getJson('/api/v1/cares/employees')->assertOk()->json();
    }

    private function fieldOffice(string $code = 'ESFO'): OrganizationalUnit
    {
        return OrganizationalUnit::factory()->create(['code' => $code, 'name' => "CSC Field Office - {$code}", 'unit_type' => UnitType::FieldOffice]);
    }

    public function test_the_endpoint_needs_the_shared_token(): void
    {
        $this->getJson('/api/v1/cares/employees')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/cares/employees')->assertUnauthorized();

        config(['cares.api_token' => null]);
        $this->withToken('')->getJson('/api/v1/cares/employees')->assertUnauthorized();
    }

    public function test_it_lists_active_employees_with_their_name_in_parts_and_their_office(): void
    {
        $office = $this->fieldOffice('ESFO');
        $section = OrganizationalUnit::factory()->create(['parent_id' => $office->id, 'unit_type' => UnitType::Other]);

        User::factory()->create([
            'first_name' => 'Ryan Jose', 'middle_name' => 'Mendoza', 'last_name' => 'Estoque', 'name_extension' => null,
            'position' => 'Senior Human Resource Specialist', 'organizational_unit_id' => $section->id,
        ]);
        User::factory()->create(['name' => 'Gone Person', 'status' => UserStatus::Inactive]);

        $data = $this->directory()['data'];

        $this->assertSame(['Ryan Jose M. Estoque'], array_column($data, 'name'), 'active accounts only, named with the middle initial as IMIS writes it');
        $this->assertSame('Mendoza', $data[0]['middle_name']);
        $this->assertSame('Senior Human Resource Specialist', $data[0]['position']);
        $this->assertSame('ESFO', $data[0]['office_code'], 'the office is the unit, or its nearest ancestor that is one');
        $this->assertSame('field_office', $data[0]['office_type']);
        $this->assertSame(
            ['id', 'name', 'first_name', 'middle_name', 'last_name', 'name_extension', 'position', 'salary_grade', 'office_name', 'office_code', 'office_type'],
            array_keys($data[0]),
            'nothing private: no e-mail, no username',
        );
    }

    public function test_the_head_of_a_field_office_is_its_director_ii(): void
    {
        $office = $this->fieldOffice('BFO');
        $director = User::factory()->create(['position' => 'Director II', 'organizational_unit_id' => $office->id]);
        User::factory()->create(['position' => 'Senior Human Resource Specialist', 'organizational_unit_id' => $office->id]);

        $heads = $this->directory()['heads'];

        $this->assertCount(1, $heads);
        $this->assertSame('BFO', $heads[0]['office_code']);
        $this->assertSame($director->id, $heads[0]['user_id']);
        $this->assertSame('CSC Field Office - BFO', $heads[0]['office_name']);
        $this->assertSame('field_office', $heads[0]['office_type']);
        $this->assertNull($heads[0]['capacity'], 'the regular head has no capacity');
    }

    public function test_an_officer_in_charge_takes_the_place_of_the_director_while_designated(): void
    {
        $office = $this->fieldOffice('ESFO');
        $oic = User::factory()->create(['position' => 'Supervising Human Resource Specialist', 'organizational_unit_id' => $office->id]);
        $employee = Employee::factory()->create(['user_id' => $oic->id]);

        EmployeeAssignment::make([
            'kind' => 'designation', 'as_head' => true, 'head_capacity' => 'oic', 'to_unit_id' => $office->id, 'effective_date' => today(),
        ])->forceFill(['employee_id' => $employee->id])->save();

        $heads = $this->directory()['heads'];

        $this->assertSame($oic->id, $heads[0]['user_id']);
        $this->assertSame('oic', $heads[0]['capacity']);
        $this->assertSame('Officer-in-Charge', $heads[0]['capacity_label']);
    }

    public function test_a_caretaker_is_reported_as_such_and_an_office_without_a_head_is_left_out(): void
    {
        $office = $this->fieldOffice('SLFO');
        $this->fieldOffice('NSFO'); // nobody heads it
        $caretaker = User::factory()->create(['organizational_unit_id' => $office->id]);
        $employee = Employee::factory()->create(['user_id' => $caretaker->id]);

        EmployeeAssignment::make([
            'kind' => 'designation', 'as_head' => true, 'head_capacity' => 'caretaker', 'to_unit_id' => $office->id, 'effective_date' => today(),
        ])->forceFill(['employee_id' => $employee->id])->save();

        $heads = $this->directory()['heads'];

        $this->assertSame(['SLFO'], array_column($heads, 'office_code'));
        $this->assertSame('caretaker', $heads[0]['capacity']);
    }

    public function test_each_employee_carries_the_salary_grade_of_their_position(): void
    {
        $office = $this->fieldOffice('ESFO');
        Position::create(['title' => 'Director IV', 'salary_grade' => 28, 'level' => 'second']);
        Position::create(['title' => 'Senior Human Resource Specialist', 'salary_grade' => 19, 'level' => 'second']);

        User::factory()->create(['name' => 'Dir Four', 'last_name' => 'Aaa', 'position' => ' director iv ', 'organizational_unit_id' => $office->id]);   // case and spacing do not matter
        User::factory()->create(['name' => 'Senior', 'last_name' => 'Bbb', 'position' => 'Senior Human Resource Specialist', 'organizational_unit_id' => $office->id]);
        User::factory()->create(['name' => 'Unlisted', 'last_name' => 'Ccc', 'position' => 'Some Title Nobody Catalogued', 'organizational_unit_id' => $office->id]);

        $grades = collect($this->directory()['data'])->pluck('salary_grade', 'name');

        $this->assertSame(28, $grades['Dir Four']);
        $this->assertSame(19, $grades['Senior']);
        $this->assertNull($grades['Unlisted'], 'a title that is not in the catalog has no grade');
    }
}
