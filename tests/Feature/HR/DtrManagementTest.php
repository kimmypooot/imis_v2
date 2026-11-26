<?php

namespace Tests\Feature\HR;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * DTR management for HRD: everyone's month with what needs attention. HR
 * only; supervisors do not get it.
 */
class DtrManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
        $this->hr = $this->userWith(['dtr.view']);
    }

    public function test_the_list_shows_totals_and_flags_for_the_month(): void
    {
        $complete = $this->employee('Amy Complete', '101', schedule: true);
        $this->punch('101', '2026-11-03 08:00:00');
        $this->punch('101', '2026-11-03 17:00:00');
        $this->employee('Ben NoId', null, schedule: true);
        $this->employee('Cara NoSchedule', '103', schedule: false);

        $this->actingAs($this->hr)->get('/admin/hr/dtr?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Dtr/Index')
            ->where('counts.no_id', 1)
            ->where('counts.no_schedule', 1)
            ->where('counts.no_punches', 1)
            ->has('employees.data', 3)
            ->where('employees.data.0.name', 'Amy Complete')
            ->where('employees.data.0.biometric_id', '101')
            ->where('employees.data.0.schedule', $complete->workSchedules()->first()->schedule->name));
    }

    public function test_a_filter_narrows_the_list(): void
    {
        $this->employee('Amy Complete', '101', schedule: true);
        $this->employee('Ben NoId', null, schedule: true);

        $this->actingAs($this->hr)->get('/admin/hr/dtr?month=2026-11&filter=no_id')->assertInertia(fn (Assert $page) => $page
            ->has('employees.data', 1)
            ->where('employees.data.0.name', 'Ben NoId'));
    }

    public function test_voided_punches_do_not_count_as_punches(): void
    {
        $this->employee('Amy Complete', '101', schedule: true);
        $punch = $this->punch('101', '2026-11-03 08:00:00');
        $punch->forceFill(['voided_at' => now()])->save();

        $this->actingAs($this->hr)->get('/admin/hr/dtr?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('counts.no_punches', 1));
    }

    public function test_entries_waiting_for_verification_are_counted(): void
    {
        $amy = $this->employee('Amy Complete', '101', schedule: true);
        DtrAdjustment::query()->create(['employee_id' => $amy->id, 'date' => '2026-11-04', 'kind' => 'times', 'time_in' => '08:00', 'time_out' => '17:00', 'remark' => 'Forgot', 'entered_by_employee' => true, 'created_by' => $amy->user_id]);

        $this->actingAs($this->hr)->get('/admin/hr/dtr?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('counts.unverified', 1)
            ->where('employees.data.0.unverified_days', 1));
    }

    public function test_the_search_finds_people_by_name(): void
    {
        $this->employee('Amy Complete', '101', schedule: true);
        $this->employee('Ben NoId', '102', schedule: true);

        $this->actingAs($this->hr)->get('/admin/hr/dtr?month=2026-11&search=ben')->assertInertia(fn (Assert $page) => $page
            ->has('employees.data', 1)
            ->where('employees.data.0.name', 'Ben NoId'));
    }

    public function test_the_table_chooses_how_many_rows_a_page_shows(): void
    {
        $this->employee('Amy Complete', '101', schedule: true);

        $this->actingAs($this->hr)->get('/admin/hr/dtr?month=2026-11&per_page=10')->assertInertia(fn (Assert $page) => $page
            ->where('employees.per_page', 10)
            ->has('employees.data.0.photo_url'));

        // A size the table doesn't offer falls back to the default.
        $this->actingAs($this->hr)->get('/admin/hr/dtr?month=2026-11&per_page=7')->assertInertia(fn (Assert $page) => $page
            ->where('employees.per_page', 25));
    }

    public function test_it_is_for_hr_only(): void
    {
        $supervisor = User::factory()->create();

        $this->actingAs($supervisor)->get('/admin/hr/dtr')->assertForbidden();
    }

    private function employee(string $name, ?string $biometricId, bool $schedule): Employee
    {
        $employee = Employee::factory()->create([
            'user_id' => User::factory()->create(['name' => $name]),
            'biometric_id' => $biometricId,
        ]);

        if ($schedule) {
            EmployeeWorkSchedule::factory()->create([
                'employee_id' => $employee->id,
                'work_schedule_id' => WorkSchedule::query()->where('name', 'not like', 'Compressed%')->value('id'),
                'valid_from' => '2026-01-01',
            ]);
        }

        return $employee;
    }

    private function punch(string $biometricId, string $at): BiometricPunch
    {
        return BiometricPunch::query()->create(['biometric_id' => $biometricId, 'punched_at' => $at, 'biometric_import_id' => BiometricImport::factory()->create()->id]);
    }

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
