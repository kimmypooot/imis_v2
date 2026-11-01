<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Leave\CalendarAudience;
use App\Support\HR\Payroll\PayslipApprovers;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR's Leave Calendar: everyone's leave on a month grid, by office and
 * type, skipping weekends and day-off holidays.
 */
class LeaveCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private OrganizationalUnit $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
        // A Director IV: one of the few who see every office.
        $this->hr = $this->userWith(['leave.view']);
        $this->hr->update(['position' => 'Director IV']);
        $this->office = OrganizationalUnit::factory()->create();
        Holiday::query()->create(['date' => '2026-06-12', 'name' => 'Independence Day', 'type' => 'regular']);
    }

    public function test_hr_sees_everyones_leave_on_working_days(): void
    {
        $ana = $this->employee($this->office);
        $ben = $this->employee(OrganizationalUnit::factory()->create());
        // Thu 11 – Mon 15: the holiday and the weekend are skipped.
        $this->file($ana, 'VL', '2026-06-11', '2026-06-15', 'approved');
        // Maternity counts calendar days, into July.
        $this->file($ben, 'ML', '2026-06-26', '2026-07-10', 'approved');
        $this->file($ben, 'SL', '2026-06-03', '2026-06-03', 'for_review');
        $this->file($ben, 'VL', '2026-06-04', '2026-06-04', 'cancelled');
        $this->file($ana, 'VL', '2026-07-06', '2026-07-06', 'approved');

        $this->actingAs($this->hr)->get('/admin/hr/leave/calendar?month=2026-06')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Leave/Calendar')
            ->where('month', '2026-06')
            ->has('leave', 2)
            ->where('leave.0.name', $ana->user->name)
            ->where('leave.0.dates', ['2026-06-11', '2026-06-15'])
            ->where('leave.1.dates', ['2026-06-26', '2026-06-27', '2026-06-28', '2026-06-29', '2026-06-30'])
            ->where('holidays.0.name', 'Independence Day'));

        // Pending leave only when asked for.
        $this->actingAs($this->hr)->get('/admin/hr/leave/calendar?month=2026-06&pending=1')->assertInertia(fn (Assert $page) => $page
            ->has('leave', 3)
            ->where('leave.0.status', 'for_review'));

        // By office, and by type of leave.
        $this->actingAs($this->hr)->get("/admin/hr/leave/calendar?month=2026-06&office={$this->office->id}")->assertInertia(fn (Assert $page) => $page
            ->has('leave', 1)
            ->where('leave.0.name', $ana->user->name));
        $ml = LeaveType::query()->where('code', 'ML')->sole();
        $this->actingAs($this->hr)->get("/admin/hr/leave/calendar?month=2026-06&type={$ml->id}")->assertInertia(fn (Assert $page) => $page
            ->has('leave', 1)
            ->where('leave.0.type_code', 'ML'));
    }

    public function test_staff_see_who_in_their_division_has_filed_leave(): void
    {
        $me = $this->employee($this->office);
        $colleague = $this->employee($this->office);
        $elsewhere = $this->employee(OrganizationalUnit::factory()->create());
        $this->file($me, 'VL', '2026-06-08', '2026-06-08', 'approved');
        $this->file($colleague, 'SL', '2026-06-09', '2026-06-10', 'for_review');
        $this->file($colleague, 'VL', '2026-06-22', '2026-06-22', 'disapproved');
        $this->file($elsewhere, 'VL', '2026-06-09', '2026-06-09', 'approved');

        $this->actingAs($me->user)->get('/my/leave/calendar?month=2026-06')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/LeaveCalendar')
            ->where('division', $this->office->name)
            ->has('leave', 1)
            ->has('colleagues', 1)
            ->where('colleagues.0.name', $colleague->user->name)
            ->where('colleagues.0.status', 'for_review')
            ->where('colleagues.0.dates', ['2026-06-09', '2026-06-10'])
            // Who is out, not why.
            ->missing('colleagues.0.type_code')
            ->missing('colleagues.0.type_name')
            ->missing('colleagues.0.reference_number'));

        // No division, no co-workers.
        $loner = Employee::factory()->create();
        $loner->user->update(['organizational_unit_id' => null]);
        $this->actingAs($loner->user->fresh())->get('/my/leave/calendar?month=2026-06')->assertInertia(fn (Assert $page) => $page
            ->has('colleagues', 0));
    }

    public function test_hr_staff_outside_the_wide_audience_see_only_their_own_division(): void
    {
        $staff = $this->userWith(['leave.view']);
        $staff->update(['organizational_unit_id' => $this->office->id, 'position' => 'Human Resource Specialist']);
        $mine = $this->employee($this->office);
        $other = $this->employee(OrganizationalUnit::factory()->create());
        $this->file($mine, 'VL', '2026-06-08', '2026-06-08', 'approved');
        $this->file($other, 'VL', '2026-06-09', '2026-06-09', 'approved');

        // Asking for another office, or for everyone, still returns only theirs.
        foreach (['', '&office='.$other->user->organizational_unit_id] as $query) {
            $this->actingAs($staff)->get('/admin/hr/leave/calendar?month=2026-06'.$query)->assertInertia(fn (Assert $page) => $page
                ->where('seesEveryone', false)
                ->where('filters.office', $this->office->id)
                ->has('leave', 1)
                ->where('leave.0.name', $mine->user->name));
        }

        // The wide audience still gets everyone.
        $this->actingAs($this->hr)->get('/admin/hr/leave/calendar?month=2026-06')->assertInertia(fn (Assert $page) => $page
            ->where('seesEveryone', true)
            ->has('leave', 2));
    }

    public function test_the_portal_calendar_shows_every_office_only_to_the_wide_audience(): void
    {
        $elsewhere = $this->employee(OrganizationalUnit::factory()->create());
        $this->file($elsewhere, 'VL', '2026-06-09', '2026-06-09', 'approved');

        $director = $this->employee($this->office);
        $director->user->update(['position' => 'Director III']);
        $this->actingAs($director->user->fresh())->get('/my/leave/calendar?month=2026-06')->assertInertia(fn (Assert $page) => $page
            ->where('seesEveryone', true)
            ->has('colleagues', 1)
            ->where('colleagues.0.name', $elsewhere->user->name));

        $regular = $this->employee($this->office);
        $this->actingAs($regular->user)->get('/my/leave/calendar?month=2026-06')->assertInertia(fn (Assert $page) => $page
            ->where('seesEveryone', false)
            ->has('colleagues', 0));
    }

    public function test_who_sees_every_office(): void
    {
        $audience = app(CalendarAudience::class);

        $this->assertTrue($audience->seesEveryone(User::factory()->create(['position' => ' director iv '])));
        $this->assertTrue($audience->seesEveryone(User::factory()->create(['position' => 'Director III'])));
        $this->assertFalse($audience->seesEveryone(User::factory()->create(['position' => 'Director II'])));
        $this->assertFalse($audience->seesEveryone(User::factory()->create(['position' => 'Chief Human Resource Specialist'])));

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::factory()->create(['name' => 'super-admin']));
        $this->assertTrue($audience->seesEveryone($admin));

        // The head of the Human Resource Division (or whoever is designated head).
        $this->mock(PayslipApprovers::class, fn ($mock) => $mock->shouldReceive('isApprover')->andReturn(true));
        $this->assertTrue(app(CalendarAudience::class)->seesEveryone(User::factory()->create(['position' => 'Chief Human Resource Specialist'])));
    }

    public function test_it_needs_a_leave_permission_and_valid_filters(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/hr/leave/calendar')->assertForbidden();
        $this->actingAs($this->hr)->get('/admin/hr/leave/calendar')->assertInertia(fn (Assert $page) => $page->where('month', '2026-06'));
        $this->actingAs($this->hr)->get('/admin/hr/leave/calendar?month=June')->assertSessionHasErrors('month');
    }

    private function employee(OrganizationalUnit $office): Employee
    {
        $employee = Employee::factory()->create();
        $employee->user->update(['organizational_unit_id' => $office->id]);

        return $employee->fresh('user');
    }

    private function file(Employee $employee, string $code, string $from, string $to, string $status): LeaveApplication
    {
        return LeaveApplication::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', $code)->sole()->id,
            'date_from' => $from,
            'date_to' => $to,
            'days' => 1,
            'status' => $status,
        ]);
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
