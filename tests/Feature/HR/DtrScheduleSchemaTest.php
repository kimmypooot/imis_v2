<?php

namespace Tests\Feature\HR;

use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The DTR schedule tables: two default schedules, date-ranged assignments
 * and one adjustment per employee per day.
 */
class DtrScheduleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_default_schedules_exist_with_the_agreed_values(): void
    {
        $cww = WorkSchedule::query()->where('name', 'like', 'Compressed Work Week%')->firstOrFail();
        $regular = WorkSchedule::query()->where('name', 'like', 'Regular%')->firstOrFail();

        $this->assertSame([600, [5], '12:00', 60], [$cww->required_minutes, $cww->rest_weekdays, substr($cww->break_start, 0, 5), $cww->break_minutes]);
        $this->assertSame(['07:00', '08:00'], [substr($cww->start_time, 0, 5), substr($cww->latest_arrival, 0, 5)]);
        $this->assertSame([480, [], '12:00', 60], [$regular->required_minutes, $regular->rest_weekdays, substr($regular->break_start, 0, 5), $regular->break_minutes]);
        $this->assertSame(['08:00', '08:00'], [substr($regular->start_time, 0, 5), substr($regular->latest_arrival, 0, 5)]);
    }

    public function test_schedule_helpers_resolve_times_on_a_day(): void
    {
        $cww = WorkSchedule::query()->where('name', 'like', 'Compressed Work Week%')->firstOrFail();
        $regular = WorkSchedule::query()->where('name', 'like', 'Regular%')->firstOrFail();
        $day = Carbon::parse('2026-10-06');

        $this->assertSame('2026-10-06 08:00:00', $cww->latestArrivalAt($day)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 07:00:00', $cww->startsAt($day)->format('Y-m-d H:i:s'));
        [$from, $to] = $cww->breakWindow($day);
        $this->assertSame(['12:00', '13:00'], [$from->format('H:i'), $to->format('H:i')]);
        [$from, $to] = $regular->breakWindow($day);
        $this->assertSame(['12:00', '13:00'], [$from->format('H:i'), $to->format('H:i')]);
        $this->assertTrue($cww->isRestWeekday(Carbon::parse('2026-10-09')));
        $this->assertFalse($cww->isRestWeekday(Carbon::parse('2026-10-08')));
    }

    public function test_an_adjustment_is_unique_per_employee_and_date(): void
    {
        $employee = Employee::factory()->create();
        DtrAdjustment::factory()->create(['employee_id' => $employee->id, 'date' => '2026-10-05']);

        $this->expectException(QueryException::class);
        DtrAdjustment::factory()->create(['employee_id' => $employee->id, 'date' => '2026-10-05']);
    }

    public function test_assignment_covering_date_respects_open_ranges(): void
    {
        $employee = Employee::factory()->create();
        $open = EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        $closed = EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'valid_from' => '2025-01-01', 'valid_to' => '2025-12-31']);

        $this->assertSame([$open->id], EmployeeWorkSchedule::query()->coveringDate(Carbon::parse('2026-03-01'))->pluck('id')->all());
        $this->assertSame([$open->id], EmployeeWorkSchedule::query()->coveringDate(Carbon::parse('2026-01-01'))->pluck('id')->all(), 'The start day is covered.');
        $this->assertSame([$closed->id], EmployeeWorkSchedule::query()->coveringDate(Carbon::parse('2025-06-01'))->pluck('id')->all());
    }
}
