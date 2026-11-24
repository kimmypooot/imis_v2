<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\WorkSchedule;
use App\Support\HR\Dtr\WorkSchedules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Which work schedule an employee follows on a date, and assigning one
 * without overlapping the employee's other assignments.
 */
class WorkSchedulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_returns_the_schedule_in_force_on_a_date_and_null_otherwise(): void
    {
        $employee = Employee::factory()->create();
        $schedule = WorkSchedule::factory()->create();
        (new WorkSchedules)->assign($employee, $schedule, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));

        $service = new WorkSchedules;
        $this->assertSame($schedule->id, $service->on($employee, Carbon::parse('2026-02-15'))->id);
        $this->assertSame($schedule->id, $service->on($employee, Carbon::parse('2026-02-28'))->id);
        $this->assertNull($service->on($employee, Carbon::parse('2026-03-01')));
        $this->assertNull($service->on($employee, Carbon::parse('2026-01-31')));
    }

    public function test_two_adjoining_assignments_are_allowed_and_overlapping_ones_rejected(): void
    {
        $employee = Employee::factory()->create();
        $service = new WorkSchedules;
        $first = WorkSchedule::factory()->create();
        $second = WorkSchedule::factory()->create();

        $service->assign($employee, $first, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
        $service->assign($employee, $second, Carbon::parse('2026-02-01'), null);

        $this->assertSame(2, EmployeeWorkSchedule::query()->count());

        $this->expectException(ValidationException::class);
        $service->assign($employee, $first, Carbon::parse('2026-01-15'), Carbon::parse('2026-02-15'));
    }

    public function test_end_before_start_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new WorkSchedules)->assign(Employee::factory()->create(), WorkSchedule::factory()->create(), Carbon::parse('2026-02-01'), Carbon::parse('2026-01-01'));
    }

    public function test_ending_an_assignment_lets_the_next_one_start_the_day_after(): void
    {
        $employee = Employee::factory()->create();
        $service = new WorkSchedules;
        $open = $service->assign($employee, WorkSchedule::factory()->create(), Carbon::parse('2026-01-01'), null);

        $service->end($open, Carbon::parse('2026-03-31'));
        $next = $service->assign($employee, WorkSchedule::factory()->create(), Carbon::parse('2026-04-01'), null);

        $this->assertSame('2026-03-31', $open->fresh()->valid_to->toDateString());
        $this->assertSame($next->work_schedule_id, $service->on($employee, Carbon::parse('2026-04-10'))->id);
    }

    public function test_an_assignment_cannot_be_ended_into_a_later_one(): void
    {
        $employee = Employee::factory()->create();
        $service = new WorkSchedules;
        $first = $service->assign($employee, WorkSchedule::factory()->create(), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
        $service->assign($employee, WorkSchedule::factory()->create(), Carbon::parse('2026-03-01'), null);

        $this->expectException(ValidationException::class);
        $service->end($first, Carbon::parse('2026-03-15'));
    }

    public function test_removing_an_assignment_deletes_it(): void
    {
        $assignment = (new WorkSchedules)->assign(Employee::factory()->create(), WorkSchedule::factory()->create(), Carbon::parse('2026-01-01'), null);

        (new WorkSchedules)->remove($assignment);

        $this->assertSame(0, EmployeeWorkSchedule::query()->count());
    }
}
