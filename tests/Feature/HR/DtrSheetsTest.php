<?php

namespace Tests\Feature\HR;

use App\Enums\AssignmentKind;
use App\Enums\UnitType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeWorkSchedule;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\HR\Dtr\DtrSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A month of an employee's DTR, one sheet per office they served in.
 */
class DtrSheetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_month_is_one_sheet_with_its_rows_schedule_and_head(): void
    {
        $unit = OrganizationalUnit::factory()->create(['short_name' => 'SFO', 'unit_type' => UnitType::FieldOffice]);
        $this->director($unit, 'Eleonor Garcia');
        $employee = $this->employee($unit);
        $cww = WorkSchedule::query()->where('name', 'like', 'Compressed%')->firstOrFail();
        EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'work_schedule_id' => $cww->id, 'valid_from' => '2026-01-01']);

        $sheets = (new DtrSheets)->forMonth($employee, Carbon::parse('2026-10-15'));

        $this->assertSame('October 2026', $sheets['label']);
        $this->assertSame($employee->user->name, $sheets['employee']['name']);
        $this->assertCount(1, $sheets['sheets']);
        $sheet = $sheets['sheets'][0];
        $this->assertSame(['October 1-31', 'SFO', $cww->name, 'Eleonor Garcia', 31], [$sheet['period'], $sheet['unit'], $sheet['schedule'], $sheet['head'], count($sheet['rows'])]);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$sheet['from'], $sheet['to']]);
    }

    public function test_a_mid_month_reassignment_gives_two_sheets_each_with_its_own_office_and_head(): void
    {
        $msd = OrganizationalUnit::factory()->create(['short_name' => 'MSD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $psed = OrganizationalUnit::factory()->create(['short_name' => 'PSED', 'unit_type' => UnitType::FieldOffice]);
        $this->director($psed, 'Banello Gabon');
        $officerInCharge = Employee::factory()->for(User::factory()->state(['name' => 'Acting MSD Head']))->create();
        $this->move($officerInCharge, AssignmentKind::Designation, $msd, $msd, '2026-09-01', '2026-10-18', true);
        $employee = $this->employee($psed);
        $this->move($employee, AssignmentKind::Reassignment, $msd, $psed, '2026-10-19');

        $sheets = (new DtrSheets)->forMonth($employee, Carbon::parse('2026-10-01'))['sheets'];

        $this->assertCount(2, $sheets);
        $this->assertSame(['October 1-18', 'MSD', 'Acting MSD Head'], [$sheets[0]['period'], $sheets[0]['unit'], $sheets[0]['head']]);
        $this->assertSame(['October 19-31', 'PSED', 'Banello Gabon'], [$sheets[1]['period'], $sheets[1]['unit'], $sheets[1]['head']]);
    }

    public function test_a_past_sheet_gets_the_regular_head_not_todays_officer_in_charge(): void
    {
        $unit = OrganizationalUnit::factory()->create(['short_name' => 'SFO', 'unit_type' => UnitType::FieldOffice]);
        $this->director($unit, 'Eleonor Garcia');
        $officerInCharge = Employee::factory()->for(User::factory()->state(['name' => 'Acting Head Today']))->create();
        $this->move($officerInCharge, AssignmentKind::Designation, $unit, $unit, today()->subDay()->toDateString(), null, true);
        $employee = $this->employee($unit);

        $sheet = (new DtrSheets)->forMonth($employee, Carbon::parse('2026-01-01'))['sheets'][0];

        $this->assertSame('Eleonor Garcia', $sheet['head']);
    }

    public function test_a_range_that_crosses_a_reassignment_is_rejected(): void
    {
        $msd = OrganizationalUnit::factory()->create(['short_name' => 'MSD']);
        $psed = OrganizationalUnit::factory()->create(['short_name' => 'PSED']);
        $employee = $this->employee($psed);
        $this->move($employee, AssignmentKind::Reassignment, $msd, $psed, '2026-10-19');

        $this->expectException(ValidationException::class);

        (new DtrSheets)->forRange($employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    }

    public function test_an_office_with_no_head_gives_a_blank_head(): void
    {
        $employee = $this->employee(OrganizationalUnit::factory()->create(['unit_type' => UnitType::Other]));

        $this->assertNull((new DtrSheets)->forMonth($employee, Carbon::parse('2026-10-01'))['sheets'][0]['head']);
    }

    public function test_a_month_with_no_schedule_and_no_punches_renders_with_zero_totals(): void
    {
        $employee = $this->employee(OrganizationalUnit::factory()->create());

        $sheet = (new DtrSheets)->forMonth($employee, Carbon::parse('2026-10-01'))['sheets'][0];

        $this->assertNull($sheet['schedule']);
        $this->assertSame(0, $sheet['totals']['minutes']);
        $this->assertSame('no_schedule', $sheet['rows'][0]['status']);
    }

    public function test_a_range_gives_one_sheet_for_exactly_those_days(): void
    {
        $employee = $this->employee(OrganizationalUnit::factory()->create(['short_name' => 'MSD']));

        $sheet = (new DtrSheets)->forRange($employee, Carbon::parse('2026-10-19'), Carbon::parse('2026-10-31'));

        $this->assertSame(['October 19-31', 13, 'MSD'], [$sheet['period'], count($sheet['rows']), $sheet['unit']]);
    }

    private function employee(OrganizationalUnit $unit): Employee
    {
        return Employee::factory()->for(User::factory()->state(['organizational_unit_id' => $unit->id]))->create();
    }

    private function director(OrganizationalUnit $unit, string $name): User
    {
        return User::factory()->create(['name' => $name, 'position' => 'Director II', 'organizational_unit_id' => $unit->id, 'status' => 'active']);
    }

    private function move(Employee $employee, AssignmentKind $kind, OrganizationalUnit $from, OrganizationalUnit $to, string $date, ?string $end = null, bool $asHead = false): void
    {
        (new EmployeeAssignment)->forceFill([
            'employee_id' => $employee->id,
            'kind' => $kind,
            'as_head' => $asHead,
            'from_unit_id' => $from->id,
            'to_unit_id' => $to->id,
            'effective_date' => $date,
            'end_date' => $end,
        ])->save();
    }
}
