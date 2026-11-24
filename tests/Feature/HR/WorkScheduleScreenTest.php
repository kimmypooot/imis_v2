<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HRD maintains work schedules and assigns them to employees.
 */
class WorkScheduleScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_hrd_can_add_and_edit_a_schedule(): void
    {
        $hrd = $this->userWith(['dtr.manage']);
        $data = ['name' => 'Flexi (7:00 to 4:00)', 'start_time' => '07:00', 'latest_arrival' => '09:00', 'required_minutes' => 480, 'break_start' => '12:00', 'break_minutes' => 60, 'rest_weekdays' => []];

        $this->actingAs($hrd)->post('/admin/hr/dtr/schedules', $data)->assertRedirect();

        $schedule = WorkSchedule::query()->where('name', 'Flexi (7:00 to 4:00)')->firstOrFail();
        $this->assertSame(480, $schedule->required_minutes);
        $this->assertDatabaseHas('audit_logs', ['event' => 'work_schedule.saved']);

        $this->actingAs($hrd)->put("/admin/hr/dtr/schedules/{$schedule->id}", [...$data, 'required_minutes' => 450, 'rest_weekdays' => [5]])->assertRedirect();
        $this->assertSame([450, [5]], [$schedule->fresh()->required_minutes, $schedule->fresh()->rest_weekdays]);
    }

    public function test_a_schedule_must_be_valid_and_uniquely_named(): void
    {
        $hrd = $this->userWith(['dtr.manage']);
        $existing = WorkSchedule::query()->firstOrFail();

        $this->actingAs($hrd)->post('/admin/hr/dtr/schedules', ['name' => $existing->name, 'start_time' => '25:00', 'latest_arrival' => '08:00', 'required_minutes' => 0, 'rest_weekdays' => [9]])
            ->assertSessionHasErrors(['name', 'start_time', 'required_minutes', 'rest_weekdays.0']);
    }

    public function test_break_minutes_need_a_break_start_and_arrival_cannot_precede_the_start(): void
    {
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($hrd)->post('/admin/hr/dtr/schedules', ['name' => 'Bad break', 'start_time' => '08:00', 'latest_arrival' => '08:00', 'required_minutes' => 480, 'break_minutes' => 60, 'break_start' => null])
            ->assertSessionHasErrors('break_start');
        $this->actingAs($hrd)->post('/admin/hr/dtr/schedules', ['name' => 'Early late', 'start_time' => '08:00', 'latest_arrival' => '07:00', 'required_minutes' => 480])
            ->assertSessionHasErrors('latest_arrival');
        $this->actingAs($hrd)->post('/admin/hr/dtr/schedules', ['name' => 'Sunday off', 'start_time' => '08:00', 'latest_arrival' => '08:00', 'required_minutes' => 480, 'rest_weekdays' => [7]])
            ->assertSessionHasErrors('rest_weekdays.0');
    }

    public function test_changing_a_schedule_audits_the_before_and_after_values(): void
    {
        $schedule = WorkSchedule::query()->where('name', 'like', 'Regular%')->firstOrFail();

        $this->actingAs($this->userWith(['dtr.manage']))->put("/admin/hr/dtr/schedules/{$schedule->id}", [
            'name' => $schedule->name, 'start_time' => '08:00', 'latest_arrival' => '08:30', 'required_minutes' => 480, 'break_start' => '12:00', 'break_minutes' => 60, 'rest_weekdays' => [],
        ])->assertRedirect();

        $audit = AuditLog::query()->where('event', 'work_schedule.saved')->latest('id')->firstOrFail();
        $this->assertNotEmpty($audit->metadata['changes']['latest_arrival'] ?? null);
    }

    public function test_hrd_can_assign_a_schedule_to_an_employee_for_a_range(): void
    {
        $employee = Employee::factory()->create();
        $schedule = WorkSchedule::query()->firstOrFail();

        $this->actingAs($this->userWith(['dtr.manage']))
            ->post('/admin/hr/dtr/schedules/assign', ['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-10-01', 'valid_to' => null])
            ->assertRedirect();

        $this->assertSame(1, EmployeeWorkSchedule::query()->where('employee_id', $employee->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'work_schedule.assigned']);
    }

    public function test_an_overlapping_assignment_shows_a_validation_error(): void
    {
        $employee = Employee::factory()->create();
        $schedule = WorkSchedule::query()->firstOrFail();
        EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->actingAs($this->userWith(['dtr.manage']))
            ->post('/admin/hr/dtr/schedules/assign', ['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-06-01', 'valid_to' => null])
            ->assertSessionHasErrors('valid_from');
    }

    public function test_one_schedule_can_be_applied_to_many_employees_at_once(): void
    {
        $schedule = WorkSchedule::query()->firstOrFail();
        $other = WorkSchedule::query()->create(['name' => 'Compressed', 'start_time' => '07:00', 'latest_arrival' => '07:00', 'required_minutes' => 540, 'break_start' => '12:00', 'break_minutes' => 60, 'rest_weekdays' => [5]]);
        [$fresh, $current, $later, $same] = Employee::factory()->count(4)->create();

        // $current follows another schedule from January; $later has one starting inside the new range; $same already follows it.
        EmployeeWorkSchedule::factory()->create(['employee_id' => $current->id, 'work_schedule_id' => $other->id, 'valid_from' => '2026-01-01', 'valid_to' => null]);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $later->id, 'work_schedule_id' => $other->id, 'valid_from' => '2026-11-15', 'valid_to' => null]);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $same->id, 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $response = $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/schedules/assign-many', [
            'employee_ids' => [$fresh->id, $current->id, $later->id, $same->id],
            'work_schedule_id' => $schedule->id,
            'valid_from' => '2026-11-01',
            'valid_to' => null,
            'replace' => true,
        ]);

        $response->assertRedirect()->assertSessionHas('status')->assertSessionHas('error');
        // Two applied: the new one, and the current one (its old assignment ended the day before).
        $this->assertSame('2026-11-01', EmployeeWorkSchedule::query()->where('employee_id', $fresh->id)->sole()->valid_from->toDateString());
        $this->assertSame('2026-10-31', EmployeeWorkSchedule::query()->where('employee_id', $current->id)->where('work_schedule_id', $other->id)->sole()->valid_to->toDateString());
        $this->assertSame($schedule->id, EmployeeWorkSchedule::query()->where('employee_id', $current->id)->where('valid_from', '2026-11-01')->sole()->work_schedule_id);
        // The other two are left as they were, and named.
        $this->assertSame(1, EmployeeWorkSchedule::query()->where('employee_id', $later->id)->count());
        $this->assertSame(1, EmployeeWorkSchedule::query()->where('employee_id', $same->id)->count());
        $this->assertStringContainsString('Skipped 2', session('error'));
        $this->assertStringContainsString($later->user->name, session('error'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'work_schedule.assigned', 'description' => "Assigned {$schedule->name} to 2 employee(s) at once"]);
    }

    public function test_applying_to_many_without_replacing_skips_anyone_who_already_has_one(): void
    {
        $schedule = WorkSchedule::query()->firstOrFail();
        [$free, $busy] = Employee::factory()->count(2)->create();
        EmployeeWorkSchedule::factory()->create(['employee_id' => $busy->id, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/schedules/assign-many', [
            'employee_ids' => [$free->id, $busy->id], 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-11-01', 'replace' => false,
        ])->assertRedirect()->assertSessionHas('status')->assertSessionHas('error');

        $this->assertSame(1, EmployeeWorkSchedule::query()->where('employee_id', $free->id)->count());
        $this->assertNull(EmployeeWorkSchedule::query()->where('employee_id', $busy->id)->sole()->valid_to, 'Nothing was ended.');
    }

    public function test_applying_to_many_needs_someone_chosen_and_dtr_manage(): void
    {
        $schedule = WorkSchedule::query()->firstOrFail();
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/schedules/assign-many', ['employee_ids' => [], 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-11-01'])
            ->assertSessionHasErrors('employee_ids');
        $this->actingAs($this->userWith(['dtr.view']))->post('/admin/hr/dtr/schedules/assign-many', ['employee_ids' => [$employee->id], 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-11-01'])
            ->assertForbidden();
        $this->assertSame(0, EmployeeWorkSchedule::query()->count());
    }

    public function test_an_assignment_can_be_ended_and_removed(): void
    {
        $assignment = EmployeeWorkSchedule::factory()->create(['valid_from' => '2026-01-01', 'valid_to' => null]);
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($hrd)->patch("/admin/hr/dtr/schedules/assignments/{$assignment->id}", ['valid_to' => '2026-06-30'])->assertRedirect();
        $this->assertSame('2026-06-30', $assignment->fresh()->valid_to->toDateString());

        $this->actingAs($hrd)->delete("/admin/hr/dtr/schedules/assignments/{$assignment->id}")->assertRedirect();
        $this->assertSame(0, EmployeeWorkSchedule::query()->count());
    }

    public function test_several_assignments_can_be_removed_at_once(): void
    {
        [$a, $b, $kept] = EmployeeWorkSchedule::factory()->count(3)->create(['valid_from' => '2026-01-01', 'valid_to' => null]);

        $this->actingAs($this->userWith(['dtr.view']))->post('/admin/hr/dtr/schedules/assignments/remove-many', ['assignment_ids' => [$a->id]])->assertForbidden();
        $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/schedules/assignments/remove-many', ['assignment_ids' => []])->assertSessionHasErrors('assignment_ids');

        $this->actingAs($this->userWith(['dtr.manage']))->post('/admin/hr/dtr/schedules/assignments/remove-many', ['assignment_ids' => [$a->id, $b->id]])
            ->assertRedirect()->assertSessionHas('status', 'Removed 2 assignments.');

        $this->assertSame([$kept->id], EmployeeWorkSchedule::query()->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'work_schedule.assigned', 'description' => 'Removed 2 work schedule assignment(s) at once']);
    }

    public function test_several_assignments_can_be_given_one_end_date_and_clashes_are_named(): void
    {
        [$a, $b] = EmployeeWorkSchedule::factory()->count(2)->create(['valid_from' => '2026-01-01', 'valid_to' => null]);
        $late = EmployeeWorkSchedule::factory()->create(['valid_from' => '2026-09-01', 'valid_to' => null]);
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($this->userWith(['dtr.view']))->post('/admin/hr/dtr/schedules/assignments/end-many', ['assignment_ids' => [$a->id], 'valid_to' => '2026-06-30'])->assertForbidden();
        $this->actingAs($hrd)->post('/admin/hr/dtr/schedules/assignments/end-many', ['assignment_ids' => [$a->id]])->assertSessionHasErrors('valid_to');

        $this->actingAs($hrd)->post('/admin/hr/dtr/schedules/assignments/end-many', ['assignment_ids' => [$a->id, $b->id, $late->id], 'valid_to' => '2026-06-30'])
            ->assertRedirect()->assertSessionHas('status')->assertSessionHas('error');

        $this->assertSame('2026-06-30', $a->fresh()->valid_to->toDateString());
        $this->assertSame('2026-06-30', $b->fresh()->valid_to->toDateString());
        $this->assertNull($late->fresh()->valid_to);
        $this->assertStringContainsString($late->employee->user->name, session('error'));
    }

    public function test_a_viewer_cannot_change_schedules(): void
    {
        $viewer = $this->userWith(['dtr.view']);
        $schedule = WorkSchedule::query()->firstOrFail();

        $this->actingAs($viewer)->post('/admin/hr/dtr/schedules', ['name' => 'x', 'start_time' => '08:00', 'latest_arrival' => '08:00', 'required_minutes' => 480])->assertForbidden();
        $this->actingAs($viewer)->put("/admin/hr/dtr/schedules/{$schedule->id}", ['name' => 'y'])->assertForbidden();
        $this->actingAs($viewer)->post('/admin/hr/dtr/schedules/assign', ['employee_id' => Employee::factory()->create()->id, 'work_schedule_id' => $schedule->id, 'valid_from' => '2026-01-01'])->assertForbidden();
    }

    public function test_the_page_lists_schedules_and_assignments(): void
    {
        $employee = Employee::factory()->create();
        $schedule = WorkSchedule::query()->where('name', 'like', 'Compressed%')->firstOrFail();
        EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id]);

        $this->actingAs($this->userWith(['dtr.view']))
            ->get('/admin/hr/dtr/schedules')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/Dtr/Schedules')
                ->where('canManage', false)
                ->has('schedules', 2)
                ->where('assignments.0.employee', $employee->user->name)
                ->where('assignments.0.schedule', $schedule->name));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }
        $user->roles()->attach($role);

        return $user;
    }
}
