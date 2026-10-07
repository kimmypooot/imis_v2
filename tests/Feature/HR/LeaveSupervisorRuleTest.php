<?php

namespace Tests\Feature\HR;

use App\Enums\AssignmentKind;
use App\Enums\UnitType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\HR\Leave\Supervisors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who recommends someone's leave when HR hasn't set it: the Chief Human
 * Resource Specialist for Regional Support Unit staff, the Director II for
 * Field Office staff, the Director IV for ORD staff, the Director III and
 * the Chiefs, and the CSC Chairperson (outside IMIS) for the Director IV.
 */
class LeaveSupervisorRuleTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $ord;

    private OrganizationalUnit $hrd;

    private OrganizationalUnit $leyte;

    private Employee $directorIv;

    private Employee $chief;

    private Employee $directorIi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 09:00:00');

        $this->ord = OrganizationalUnit::factory()->create(['code' => 'ORD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $this->hrd = OrganizationalUnit::factory()->create(['code' => 'HRD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $this->leyte = OrganizationalUnit::factory()->create(['code' => 'LFO', 'unit_type' => UnitType::FieldOffice]);

        $this->directorIv = $this->staff($this->ord, 'Director IV');
        $this->chief = $this->staff($this->hrd, 'Chief Human Resource Specialist');
        $this->directorIi = $this->staff($this->leyte, 'Director II');
    }

    public function test_supervisors_follow_position_and_office(): void
    {
        $this->assertSame($this->chief->user_id, $this->supervisor($this->staff($this->hrd, 'Senior Human Resource Specialist'))['id']);
        $this->assertSame($this->directorIi->user_id, $this->supervisor($this->staff($this->leyte, 'Human Resource Specialist I'))['id']);
        $this->assertSame($this->directorIv->user_id, $this->supervisor($this->staff($this->ord, 'Director III'))['id']);
        $this->assertSame($this->directorIv->user_id, $this->supervisor($this->staff($this->ord, 'Administrative Aide VI'))['id']);
        $this->assertSame($this->directorIv->user_id, $this->supervisor($this->chief)['id']);
        $this->assertNull($this->supervisor($this->directorIi)['source']);
    }

    public function test_the_director_iv_reports_to_the_csc_chairperson_outside_imis(): void
    {
        $supervisor = $this->supervisor($this->directorIv);

        $this->assertNull($supervisor['id']);
        $this->assertSame('external', $supervisor['source']);
        $this->assertSame('Atty. Marilyn B. Yap, DPA', $supervisor['name']);
        $this->assertSame('Chairperson, Civil Service Commission', $supervisor['position']);
    }

    public function test_what_hr_set_wins_and_a_designated_head_takes_the_heads_place(): void
    {
        $staff = $this->staff($this->leyte, 'Administrative Assistant III');
        $oic = $this->staff($this->hrd, 'Supervising Human Resource Specialist');

        EmployeeAssignment::query()->forceCreate([
            'employee_id' => $oic->id, 'kind' => AssignmentKind::Designation, 'as_head' => true,
            'to_unit_id' => $this->leyte->id, 'effective_date' => '2026-10-01',
        ]);
        $this->assertSame($oic->user_id, $this->supervisor($staff)['id']);

        $staff->forceFill(['immediate_supervisor_id' => $this->chief->user_id])->save();
        $this->assertSame(['id' => $this->chief->user_id, 'source' => 'set'], array_intersect_key($this->supervisor($staff->fresh()), ['id' => 0, 'source' => 0]));
    }

    public function test_an_inactive_head_supervises_no_one(): void
    {
        $this->directorIi->user->forceFill(['status' => 'inactive'])->save();

        $this->assertNull($this->supervisor($this->staff($this->leyte, 'Human Resource Specialist I'))['source']);
    }

    /**
     * @return array{id: ?int, name: ?string, position: ?string, source: ?string}
     */
    private function supervisor(Employee $employee): array
    {
        return app(Supervisors::class)->for($employee);
    }

    private function staff(OrganizationalUnit $unit, string $position): Employee
    {
        return Employee::factory()->for(User::factory()->state(['organizational_unit_id' => $unit->id, 'position' => $position]))->create();
    }
}
