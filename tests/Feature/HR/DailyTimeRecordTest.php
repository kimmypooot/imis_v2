<?php

namespace Tests\Feature\HR;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\WorkSchedule;
use App\Support\HR\Dtr\DailyTimeRecord;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The daily time record: punches, schedule, holidays, leave and HRD
 * adjustments for each day of a period, and the totals the form prints.
 * Employee used here follows the Compressed Work Week (07:00 start, late
 * after 08:00, 600 minutes, Friday off) from before every period tested.
 */
class DailyTimeRecordTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private WorkSchedule $cww;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);
        $this->cww = WorkSchedule::query()->where('name', 'like', 'Compressed%')->firstOrFail();
        $this->employee = Employee::factory()->create(['biometric_id' => '906']);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $this->employee->id, 'work_schedule_id' => $this->cww->id, 'valid_from' => '2026-01-01']);
    }

    public function test_a_normal_week_is_calculated_day_by_day(): void
    {
        $this->punch('2026-10-05', '07:51', '20:32');
        $this->punch('2026-10-06', '08:11', '19:20');
        $this->punch('2026-10-08', '08:01', '19:22');
        $this->punch('2026-10-09', '08:00', '17:00');

        $record = $this->record('2026-10-05', '2026-10-11');
        $rows = $record['days']->keyBy('date');

        $this->assertSame(['present', 600], [$rows['2026-10-05']['status'], $rows['2026-10-05']['minutes']]);
        $this->assertSame(['late', 589], [$rows['2026-10-06']['status'], $rows['2026-10-06']['minutes']]);
        $this->assertSame(['absent', 0], [$rows['2026-10-07']['status'], $rows['2026-10-07']['minutes']]);
        $this->assertSame(['late', 599], [$rows['2026-10-08']['status'], $rows['2026-10-08']['minutes']]);
        $this->assertSame(['rest_day', 'Compressed Work Week'], [$rows['2026-10-09']['status'], $rows['2026-10-09']['remark']]);
        $this->assertSame(['weekend', 'Weekend'], [$rows['2026-10-10']['status'], $rows['2026-10-10']['remark']]);
        $this->assertSame(1, $record['totals']['absences']);
        $this->assertSame(2, $record['totals']['late_count']);
        $this->assertSame(12, $record['totals']['late_minutes']);
        $this->assertSame(600 + 589 + 599, $record['totals']['minutes']);
    }

    public function test_the_october_template_sheet_is_reproduced_with_correct_hours(): void
    {
        $this->punch('2026-10-01', '07:51', '20:32');
        $this->punch('2026-10-05', '07:45', null);
        $this->punch('2026-10-06', '08:11', '19:20');
        $this->punch('2026-10-07', '07:58', '19:22');
        $this->punch('2026-10-08', '08:01', '19:22');
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'Pasasalamat Program']);
        $this->leave('VL', '2026-10-12', '2026-10-15');

        $record = $this->record('2026-10-01', '2026-10-18');
        $totals = $record['totals'];
        $rows = $record['days']->keyBy('date');

        $this->assertSame(18, $record['days']->count());
        $this->assertSame(5388, $totals['minutes']);
        $this->assertSame(12, $totals['late_minutes']);
        $this->assertSame(2, $totals['late_count']);
        $this->assertSame(0, $totals['undertime_count']);
        $this->assertSame(4.0, $totals['leave_days']);
        $this->assertSame(0, $totals['absences']);
        $this->assertSame([12, 6, 0], [$totals['regular_days'], $totals['weekend_days'], $totals['holiday_days']]);
        $this->assertSame(['credit', 600, 'Pasasalamat Program'], [$rows['2026-10-05']['status'], $rows['2026-10-05']['minutes'], $rows['2026-10-05']['remark']]);
        $this->assertSame(['leave', 600, 'Vacation Leave'], [$rows['2026-10-13']['status'], $rows['2026-10-13']['minutes'], $rows['2026-10-13']['remark']]);
    }

    public function test_the_break_punches_show_only_when_there_are_exactly_four(): void
    {
        $this->punch('2026-10-06', '07:30', '19:00');
        $this->punchAt('2026-10-06', ['12:14', '12:32']);
        $this->punch('2026-10-07', '07:30', '19:00');
        $this->punchAt('2026-10-07', ['12:14']);

        $rows = $this->record('2026-10-06', '2026-10-07')['days']->keyBy('date');

        $this->assertSame(['07:30', '12:14', '12:32', '19:00'], [$rows['2026-10-06']['in'], $rows['2026-10-06']['break_out'], $rows['2026-10-06']['break_in'], $rows['2026-10-06']['out']]);
        $this->assertSame([null, null], [$rows['2026-10-07']['break_out'], $rows['2026-10-07']['break_in']]);
    }

    public function test_a_holiday_credits_a_full_day_and_ignores_punches(): void
    {
        Holiday::query()->create(['date' => '2026-10-20', 'name' => 'Leyte Gulf Landing', 'type' => 'special_non_working']);
        $this->punch('2026-10-20', '08:00', '17:00');

        $record = $this->record('2026-10-19', '2026-10-21');
        $row = $record['days']->keyBy('date')['2026-10-20'];

        $this->assertSame(['holiday', 600, 'Leyte Gulf Landing', []], [$row['status'], $row['minutes'], $row['remark'], $row['flags']]);
        $this->assertSame(1, $record['totals']['holiday_days']);
    }

    public function test_leave_counts_only_on_working_days(): void
    {
        $this->leave('VL', '2026-10-09', '2026-10-12');

        $record = $this->record('2026-10-09', '2026-10-12');
        $rows = $record['days']->keyBy('date');

        $this->assertSame('rest_day', $rows['2026-10-09']['status']);
        $this->assertSame('weekend', $rows['2026-10-10']['status']);
        $this->assertSame('leave', $rows['2026-10-12']['status']);
        $this->assertSame(1.0, $record['totals']['leave_days']);
    }

    public function test_half_day_leave_adds_half_the_day_to_the_punch_half(): void
    {
        $this->leave('VL', '2026-10-06', '2026-10-06', halfDay: true);
        $this->punch('2026-10-06', '07:50', '12:50');

        $record = $this->record('2026-10-06', '2026-10-06');

        $this->assertSame(600, $record['days'][0]['minutes']);
        $this->assertSame(0.5, $record['totals']['leave_days']);
    }

    public function test_lwop_leave_credits_nothing(): void
    {
        $this->leave('LWOP', '2026-10-06', '2026-10-06');

        $record = $this->record('2026-10-06', '2026-10-06');

        $this->assertSame(['leave', 0], [$record['days'][0]['status'], $record['days'][0]['minutes']]);
        $this->assertSame(1.0, $record['totals']['leave_days']);
        $this->assertSame(0, $record['totals']['absences']);
    }

    public function test_an_adjustment_beats_leave_and_holiday(): void
    {
        Holiday::query()->create(['date' => '2026-10-06', 'name' => 'A holiday', 'type' => 'regular']);
        $this->leave('VL', '2026-10-06', '2026-10-06');
        $adjustment = DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-06', 'kind' => 'credit', 'remark' => 'Official business']);

        $row = $this->record('2026-10-06', '2026-10-06')['days'][0];

        $this->assertSame(['credit', 600, 'Official business', ['adjusted']], [$row['status'], $row['minutes'], $row['remark'], $row['flags']]);
        $this->assertSame($adjustment->id, $row['adjustment_id']);
    }

    public function test_a_times_adjustment_replaces_the_punches_for_that_day(): void
    {
        $this->punch('2026-10-06', '07:45', null);
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-06', 'kind' => 'times', 'time_in' => '07:45:00', 'time_out' => '18:45:00', 'remark' => 'Forgot to punch out']);

        $row = $this->record('2026-10-06', '2026-10-06')['days'][0];

        $this->assertSame(['present', 600, ['adjusted'], '07:45', '18:45'], [$row['status'], $row['minutes'], $row['flags'], $row['in'], $row['out']]);
        $this->assertSame('Forgot to punch out', $row['remark']);
        $this->assertSame(['kind' => 'times', 'time_in' => '07:45', 'time_out' => '18:45', 'remark' => 'Forgot to punch out'], collect($row['adjustment'])->only(['kind', 'time_in', 'time_out', 'remark'])->all());
    }

    public function test_a_lone_punch_becomes_the_time_out_when_hrd_supplies_the_missing_time_in(): void
    {
        $this->punch('2026-10-06', '17:30', null);
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-06', 'kind' => 'times', 'time_in' => '07:45:00', 'time_out' => null, 'remark' => 'Forgot to punch in']);

        $row = $this->record('2026-10-06', '2026-10-06')['days'][0];

        $this->assertSame(['07:45', '17:30', 525, 75], [$row['in'], $row['out'], $row['minutes'], $row['undertime_minutes']]);
    }

    public function test_a_times_adjustment_with_only_a_time_out_keeps_the_real_time_in(): void
    {
        $this->punch('2026-10-06', '08:30', null);
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-06', 'kind' => 'times', 'time_in' => null, 'time_out' => '19:00:00', 'remark' => 'Forgot to punch out']);

        $row = $this->record('2026-10-06', '2026-10-06')['days'][0];

        $this->assertSame(['late', 30, '08:30', '19:00'], [$row['status'], $row['late_minutes'], $row['in'], $row['out']]);
    }

    public function test_a_times_adjustment_with_only_a_time_in_keeps_the_real_time_out(): void
    {
        $this->punch('2026-10-06', '09:00', '19:00');
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-06', 'kind' => 'times', 'time_in' => '07:50:00', 'time_out' => null, 'remark' => 'Punched late']);

        $row = $this->record('2026-10-06', '2026-10-06')['days'][0];

        $this->assertSame(['present', 600, '07:50', '19:00'], [$row['status'], $row['minutes'], $row['in'], $row['out']]);
    }

    public function test_half_day_leave_with_no_punches_counts_the_other_half_as_a_half_day_absence_not_undertime(): void
    {
        $this->leave('VL', '2026-10-06', '2026-10-06', halfDay: true);

        $record = $this->record('2026-10-06', '2026-10-06');
        $row = $record['days'][0];

        $this->assertSame(['leave', 300, 0, 300], [$row['status'], $row['minutes'], $row['undertime_minutes'], $row['absent_minutes']]);
        $this->assertSame([0, 0, 1], [$record['totals']['undertime_count'], $record['totals']['undertime_minutes'], $record['totals']['half_day_absences']]);
    }

    public function test_half_day_leave_with_only_one_punch_is_also_a_half_day_absence(): void
    {
        $this->leave('VL', '2026-10-06', '2026-10-06', halfDay: true);
        $this->punch('2026-10-06', '07:50', null);

        $record = $this->record('2026-10-06', '2026-10-06');

        $this->assertSame([0, 300], [$record['days'][0]['undertime_minutes'], $record['days'][0]['absent_minutes']]);
        $this->assertSame(1, $record['totals']['half_day_absences']);
    }

    public function test_a_half_day_leave_with_a_short_other_half_is_still_undertime(): void
    {
        $this->leave('VL', '2026-10-06', '2026-10-06', halfDay: true);
        $this->punch('2026-10-06', '07:50', '11:00');

        $record = $this->record('2026-10-06', '2026-10-06');

        $this->assertSame([110, 0], [$record['days'][0]['undertime_minutes'], $record['days'][0]['absent_minutes']]);
        $this->assertSame(0, $record['totals']['half_day_absences']);
    }

    public function test_a_travel_adjustment_counts_as_a_travel_day(): void
    {
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-06', 'kind' => 'travel', 'remark' => 'Travel to Tacloban']);

        $record = $this->record('2026-10-06', '2026-10-06');

        $this->assertSame(['travel', 600], [$record['days'][0]['status'], $record['days'][0]['minutes']]);
        $this->assertSame(1, $record['totals']['travel_days']);
    }

    public function test_a_punch_on_a_weekend_is_shown_but_not_credited(): void
    {
        $this->punch('2026-10-10', '08:00', '12:00');

        $row = $this->record('2026-10-10', '2026-10-10')['days'][0];

        $this->assertSame(['weekend', 0, ['worked_day_off'], '08:00', '12:00'], [$row['status'], $row['minutes'], $row['flags'], $row['in'], $row['out']]);
    }

    public function test_an_incomplete_day_credits_nothing_and_is_flagged(): void
    {
        $this->punch('2026-10-06', '07:45', null);

        $row = $this->record('2026-10-06', '2026-10-06')['days'][0];

        $this->assertSame(['incomplete', 0, '07:45', null], [$row['status'], $row['minutes'], $row['in'], $row['out']]);
    }

    public function test_without_a_schedule_the_rows_say_so_and_nothing_throws(): void
    {
        EmployeeWorkSchedule::query()->delete();
        $this->punch('2026-10-06', '08:00', '17:00');

        $record = $this->record('2026-10-05', '2026-10-11');
        $rows = $record['days']->keyBy('date');

        $this->assertSame(['no_schedule', '08:00', '17:00'], [$rows['2026-10-06']['status'], $rows['2026-10-06']['in'], $rows['2026-10-06']['out']]);
        $this->assertSame('weekend', $rows['2026-10-10']['status']);
        $this->assertSame([0, 0], [$record['totals']['minutes'], $record['totals']['absences']]);

        $nobody = (new DailyTimeRecord)->build(Employee::factory()->create(), Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11'));
        $this->assertSame(0, $nobody['totals']['minutes']);
    }

    public function test_days_that_have_not_happened_yet_are_not_absences(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->punch('2026-10-06', '08:00', '19:00');

        $record = $this->record('2026-10-06', '2026-10-09');
        $rows = $record['days']->keyBy('date');

        $this->assertSame('upcoming', $rows['2026-10-07']['status']);
        $this->assertSame('upcoming', $rows['2026-10-08']['status']);
        $this->assertSame(0, $record['totals']['absences']);

        Carbon::setTestNow();
    }

    public function test_the_requested_range_limits_the_rows(): void
    {
        $record = $this->record('2026-10-19', '2026-10-31');

        $this->assertSame(13, $record['days']->count());
        $this->assertSame('2026-10-19', $record['days']->first()['date']);
        $this->assertSame('2026-10-31', $record['days']->last()['date']);
    }

    /**
     * @return array{days: Collection<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    private function record(string $from, string $to): array
    {
        return (new DailyTimeRecord)->build($this->employee, Carbon::parse($from), Carbon::parse($to));
    }

    private function punch(string $date, string $in, ?string $out): void
    {
        $import = BiometricImport::factory()->create();

        foreach (array_filter([$in, $out]) as $time) {
            BiometricPunch::query()->create(['biometric_id' => '906', 'punched_at' => "{$date} {$time}:00", 'biometric_import_id' => $import->id]);
        }
    }

    /**
     * @param  list<string>  $times
     */
    private function punchAt(string $date, array $times): void
    {
        $this->punch($date, $times[0], $times[1] ?? null);
    }

    private function leave(string $code, string $from, string $to, bool $halfDay = false): void
    {
        LeaveApplication::query()->create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', $code)->sole()->id,
            'date_from' => $from,
            'date_to' => $to,
            'is_half_day' => $halfDay,
            'days' => 1,
            'status' => 'approved',
        ]);
    }
}
