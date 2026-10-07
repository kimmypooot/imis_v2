<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Human Resources dashboard (/admin/hr) and its analytics — an area
 * of its own, apart from the system administration dashboard (/admin).
 */
class HrDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-03 09:00:00');
    }

    public function test_hr_and_system_administration_are_separate_areas(): void
    {
        $this->get('/admin/hr')->assertRedirect('/login');

        $hr = $this->userWith(['employees.view']);
        $admin = $this->userWith(['users.view']);

        $this->actingAs($hr)->get('/admin/hr')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Dashboard'));
        $this->actingAs($hr)->get('/admin')->assertForbidden();

        $this->actingAs($admin)->get('/admin/hr')->assertForbidden();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('hr'));
    }

    public function test_any_hr_permission_opens_the_dashboard_but_only_viewers_of_employee_records_get_the_analytics(): void
    {
        $this->actingAs($this->userWith(['leave.view']))->get('/admin/hr')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hr', null));
        $this->assertDatabaseCount('audit_logs', 0);

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('hr.headcount')
                // Sections behind other permissions stay out.
                ->where('hr.leave', null)
                ->where('hr.plantilla', null)
                ->where('hr.learning', null)
                ->where('hr.pending', []));
    }

    public function test_headcount_and_breakdowns_count_only_staff_in_service(): void
    {
        $this->person('Ana', 'female', '1990-05-01', EmploymentStatus::Permanent);
        $this->person('Ben', 'male', '1970-01-15', EmploymentStatus::Permanent);
        $this->person('Cora', 'female', '2000-02-02', null);
        $this->person('Gone', 'male', '1980-01-01', EmploymentStatus::Permanent)->forceFill(['separation_date' => '2025-01-01'])->save();
        $this->person('Inactive', 'male', '1980-01-01', EmploymentStatus::Permanent)->user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr')->assertInertia(fn (Assert $page) => $page
            ->where('hr.headcount', 3)
            ->where('hr.sex', ['female' => 2, 'male' => 1, 'not_recorded' => 0])
            // Ages 26, 56 and 36 (2026-10-03).
            ->where('hr.age.labels', ['Under 30', '30–39', '40–49', '50–59', '60–64', '65 and over'])
            ->where('hr.age.values', [1, 1, 0, 1, 0, 0])
            ->where('hr.age.average', 39.3)
            ->where('hr.employmentStatus.labels', ['Permanent', 'Not Set'])
            ->where('hr.employmentStatus.values', [2, 1])
            ->where('hr.gaps.employmentStatus', 1));
    }

    public function test_birthdays_in_the_next_30_days_soonest_first_including_today_and_29_february(): void
    {
        $this->person('Later', 'female', '1990-10-20');
        $this->person('Today', 'male', '1985-10-03');
        $this->person('Leap', 'female', '1988-02-29');
        $this->person('Passed', 'male', '1990-10-01');

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr')->assertInertia(fn (Assert $page) => $page
            ->has('hr.birthdays', 2)
            ->where('hr.birthdays.0.name', 'Today')
            ->where('hr.birthdays.0.in_days', 0)
            ->where('hr.birthdays.0.turning', 41)
            ->where('hr.birthdays.1.name', 'Later')
            ->where('hr.birthdays.1.in_days', 17));

        // 29 February comes round on 28 February in a common year.
        $this->travelTo('2027-02-20 09:00:00');
        $this->flushSession(); // a fresh sign-in, months later
        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr')->assertInertia(fn (Assert $page) => $page
            ->where('hr.birthdays.0.name', 'Leap')
            ->where('hr.birthdays.0.date', '2027-02-28'));
    }

    public function test_retirement_lists_who_reaches_60_or_65_within_a_year_anyone_past_65_and_who_is_already_eligible(): void
    {
        $this->person('Past', 'male', '1960-06-01');       // 66
        $this->person('Compulsory', 'female', '1962-03-10'); // 65 on 2027-03-10
        $this->person('Optional', 'male', '1967-01-05');    // 60 on 2027-01-05
        $this->person('Eligible', 'female', '1964-01-01');  // 62: eligible now, listed last, dated at 65 (Compulsory, 64, is eligible too but listed as compulsory)
        $this->person('Young', 'male', '1990-01-01');

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr')->assertInertia(fn (Assert $page) => $page
            ->where('hr.retirement.people', fn ($people) => collect($people)->map(fn ($row) => [$row['name'], $row['kind'], $row['date']])->all() === [
                ['Past', 'past_compulsory', '2025-06-01'],
                ['Compulsory', 'compulsory', '2027-03-10'],
                ['Optional', 'optional', '2027-01-05'],
                ['Eligible', 'eligible', '2029-01-01'],
            ])
            ->where('hr.retirement.compulsory', 2)
            ->where('hr.retirement.optional', 1)
            ->where('hr.retirement.eligibleNow', 2));
    }

    public function test_length_of_service_and_loyalty_milestones_follow_the_original_appointment(): void
    {
        $this->person('Ten', 'female', '1980-01-01', appointed: '2016-07-01');
        $this->person('Twelve', 'male', '1980-01-01', appointed: '2014-07-01');
        $this->person('Unknown', 'male', '1980-01-01');

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr')->assertInertia(fn (Assert $page) => $page
            ->where('hr.service.values', [0, 0, 2, 0, 0, 0, 0])
            ->where('hr.service.unknown', 1)
            ->where('hr.loyalty.people', fn ($people) => collect($people)->pluck('name')->all() === ['Ten'])
            ->where('hr.loyalty.people.0.years', 10));
    }

    public function test_loyalty_milestones_count_only_service_in_the_csc(): void
    {
        // 15 years in government, but only 5 of them in the CSC.
        $this->person('Transferee', 'female', '1980-01-01', appointed: '2011-03-01')->forceFill(['csc_entry_date' => '2021-03-01'])->save();
        // 12 years in government, 10 in the CSC.
        $this->person('Joined', 'male', '1980-01-01', appointed: '2014-03-01')->forceFill(['csc_entry_date' => '2016-03-01'])->save();

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr')->assertInertia(fn (Assert $page) => $page
            ->where('hr.loyalty.people', fn ($people) => collect($people)->pluck('name')->all() === ['Joined'])
            ->where('hr.loyalty.people.0.years', 10)
            ->where('hr.loyalty.people.0.date', '2026-03-01'));
    }

    public function test_viewing_the_hr_analytics_is_audited_once_a_day(): void
    {
        $this->get('/admin/hr')->assertRedirect('/login');

        $hr = $this->userWith(['employees.view']);

        $this->actingAs($hr)->get('/admin/hr');
        $this->actingAs($hr)->get('/admin/hr');
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.hr_dashboard_viewed', 'actor_id' => $hr->id]);

        $this->travelTo('2026-10-04 09:00:00');
        $this->flushSession();
        $this->actingAs($hr)->get('/admin/hr');
        $this->assertDatabaseCount('audit_logs', 2);
    }

    private function person(string $name, string $sex, string $birthday, ?EmploymentStatus $status = null, ?string $appointed = null): Employee
    {
        $user = User::factory()->create(['name' => $name, 'sex' => $sex, 'birthday' => $birthday]);

        return Employee::factory()->for($user)->create(['employment_status' => $status, 'original_appointment_date' => $appointed]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['birthday' => null]);
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
