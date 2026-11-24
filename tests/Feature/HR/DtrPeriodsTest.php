<?php

namespace Tests\Feature\HR;

use App\Enums\AssignmentKind;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\HR\Dtr\DtrPeriods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A DTR period is split wherever the employee was reassigned to another
 * office, one sheet per office (as the MSD and PSED sheets of the template).
 */
class DtrPeriodsTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $msd;

    private OrganizationalUnit $psed;

    private OrganizationalUnit $samar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->msd = OrganizationalUnit::factory()->create(['short_name' => 'MSD']);
        $this->psed = OrganizationalUnit::factory()->create(['short_name' => 'PSED']);
        $this->samar = OrganizationalUnit::factory()->create(['short_name' => 'SFO']);
    }

    public function test_no_reassignment_gives_one_segment_in_the_current_unit(): void
    {
        $employee = $this->employee($this->msd);

        $this->assertSame([['2026-10-01', '2026-10-31', $this->msd->id]], $this->segments($employee, '2026-10-01', '2026-10-31'));
    }

    public function test_a_mid_period_reassignment_splits_the_range(): void
    {
        $employee = $this->employee($this->psed);
        $this->move($employee, AssignmentKind::Reassignment, $this->msd, $this->psed, '2026-10-19');

        $this->assertSame([
            ['2026-10-01', '2026-10-18', $this->msd->id],
            ['2026-10-19', '2026-10-31', $this->psed->id],
        ], $this->segments($employee, '2026-10-01', '2026-10-31'));
    }

    public function test_two_reassignments_give_three_segments(): void
    {
        $employee = $this->employee($this->samar);
        $this->move($employee, AssignmentKind::Reassignment, $this->msd, $this->psed, '2026-10-10');
        $this->move($employee, AssignmentKind::Reassignment, $this->psed, $this->samar, '2026-10-20');

        $this->assertSame([
            ['2026-10-01', '2026-10-09', $this->msd->id],
            ['2026-10-10', '2026-10-19', $this->psed->id],
            ['2026-10-20', '2026-10-31', $this->samar->id],
        ], $this->segments($employee, '2026-10-01', '2026-10-31'));
    }

    public function test_a_reassignment_before_the_range_sets_the_starting_unit(): void
    {
        $employee = $this->employee($this->psed);
        $this->move($employee, AssignmentKind::Reassignment, $this->msd, $this->psed, '2026-09-15');

        $this->assertSame([['2026-10-01', '2026-10-31', $this->psed->id]], $this->segments($employee, '2026-10-01', '2026-10-31'));
    }

    public function test_a_reassignment_on_the_first_day_starts_in_the_new_unit(): void
    {
        $employee = $this->employee($this->psed);
        $this->move($employee, AssignmentKind::Reassignment, $this->msd, $this->psed, '2026-10-01');

        $this->assertSame([['2026-10-01', '2026-10-31', $this->psed->id]], $this->segments($employee, '2026-10-01', '2026-10-31'));
    }

    public function test_details_and_designations_do_not_split(): void
    {
        $employee = $this->employee($this->msd);
        $this->move($employee, AssignmentKind::Detail, $this->msd, $this->samar, '2026-10-10');
        $this->move($employee, AssignmentKind::Designation, $this->msd, $this->psed, '2026-10-12');

        $this->assertCount(1, $this->segments($employee, '2026-10-01', '2026-10-31'));
    }

    private function employee(OrganizationalUnit $unit): Employee
    {
        return Employee::factory()->for(User::factory()->state(['organizational_unit_id' => $unit->id]))->create();
    }

    private function move(Employee $employee, AssignmentKind $kind, OrganizationalUnit $from, OrganizationalUnit $to, string $date): void
    {
        (new EmployeeAssignment)->forceFill([
            'employee_id' => $employee->id,
            'kind' => $kind,
            'from_unit_id' => $from->id,
            'to_unit_id' => $to->id,
            'effective_date' => $date,
        ])->save();
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?int}>
     */
    private function segments(Employee $employee, string $from, string $to): array
    {
        return (new DtrPeriods)->for($employee, Carbon::parse($from), Carbon::parse($to))
            ->map(fn (array $segment) => [$segment['from']->toDateString(), $segment['to']->toDateString(), $segment['unit_id']])
            ->values()->all();
    }
}
