<?php

namespace Tests\Feature\HR;

use App\Enums\SalnType;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Pds\PdsChild;
use App\Models\Saln;
use App\Models\SalnVersion;
use App\Models\User;
use App\Support\HR\Saln\SalnPrefill;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A new SALN starts from what IMIS already knows: the account, the
 * office, and the PDS family background — or from last year's SALN.
 */
class SalnPrefillTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefill_from_pds(): void
    {
        $employee = $this->employeeWithFamily();

        $statement = app(SalnPrefill::class)->for($employee, SalnType::Annual, CarbonImmutable::parse('2025-12-31'));

        $this->assertSame([
            'family_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'middle_initial' => 'P.',
            'position' => 'Human Resource Specialist II',
            'agency' => 'Civil Service Commission RO VIII — HRD',
            'office_address' => 'Government Center, Palo, Leyte',
        ], $statement['declarant']);
        $this->assertSame('SANTOS', $statement['spouse']['family_name']);
        $this->assertFalse($statement['spouse']['in_government']);
        $this->assertSame([['name' => 'Ana Dela Cruz', 'birth_date' => '2015-06-01', 'age' => 10]], $statement['children']);
        $this->assertSame([], $statement['real_properties']);
        $this->assertFalse($statement['no_relatives']);
    }

    public function test_no_spouse_when_the_pds_has_none(): void
    {
        $employee = Employee::factory()->create();

        $statement = app(SalnPrefill::class)->for($employee, SalnType::Annual, CarbonImmutable::parse('2025-12-31'));

        $this->assertNull($statement['spouse']);
    }

    public function test_prefill_from_previous_resets_totals_and_id(): void
    {
        $employee = $this->employeeWithFamily();
        $previous = Saln::factory()->for($employee)->create(['reckoning_date' => '2024-12-31', 'current_version' => 1]);
        SalnVersion::factory()->for($previous)->create([
            'version' => 1,
            'statement' => [
                'children' => [['name' => 'Old Child', 'birth_date' => '2007-03-01', 'age' => 17]],
                'personal_properties' => [['owner' => 'declarant', 'description' => 'Car', 'acquisition_year' => '2020', 'acquisition_cost' => '800000.00']],
                'government_id' => ['type' => 'UMID', 'number' => '1', 'issued_on' => '2020-01-01'],
                'totals' => ['net_worth' => '800000.00'],
            ],
        ]);

        $statement = app(SalnPrefill::class)->fromPrevious($previous, SalnType::Annual, CarbonImmutable::parse('2025-12-31'));

        $this->assertSame('Car', $statement['personal_properties'][0]['description']);
        $this->assertSame([], $statement['children'], 'A child who has turned 18 drops off.');
        $this->assertArrayNotHasKey('totals', $statement);
        $this->assertSame(['type' => null, 'number' => null, 'issued_on' => null], $statement['government_id']);
    }

    private function employeeWithFamily(): Employee
    {
        $office = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD', 'address' => 'Government Center, Palo, Leyte']);
        $user = User::factory()->create([
            'first_name' => 'JUAN', 'middle_name' => 'PEREZ', 'last_name' => 'DELA CRUZ',
            'position' => 'Human Resource Specialist II', 'organizational_unit_id' => $office->id,
        ]);
        $employee = Employee::factory()->for($user)->create();
        $employee->pdsProfile()->create(['spouse_surname' => 'SANTOS', 'spouse_first_name' => 'MARIA', 'spouse_middle_name' => 'LOPEZ']);

        foreach ([['Ana Dela Cruz', '2015-06-01'], ['Ben Dela Cruz', '2005-02-01']] as $i => [$name, $born]) {
            PdsChild::query()->forceCreate(['employee_id' => $employee->id, 'sort_order' => $i, 'full_name' => $name, 'date_of_birth' => $born]);
        }

        return $employee;
    }
}
