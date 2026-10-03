<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\Holiday;
use App\Models\LeaveMonetization;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\User;
use App\Support\HR\Leave\LeaveCredits;
use App\Support\HR\Leave\WorkingDays;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-6c: monetization and terminal leave, flexible work and weekly
 * reports (incl. compressed-work-week rest days in leave counts), the
 * leave reports and the calendar. Salary amounts are made up.
 */
class LeaveExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
        $this->hr = $this->userWith(['leave.manage']);

        $this->employee = Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent, 'original_appointment_date' => '2015-01-05']);

        // Monthly salary 22,000: a daily rate of exactly 1,000.
        $schedule = SalarySchedule::query()->create(['name' => 'Test', 'effective_date' => '2026-01-01']);
        $schedule->rates()->create(['salary_grade' => 15, 'step' => 1, 'monthly_salary' => 22000]);
        $item = PlantillaItem::query()->create(['item_number' => 'AO4-1', 'position_title' => 'Administrative Officer IV', 'salary_grade' => 15]);
        $period = new PlantillaAppointment(['step' => 1, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2020-01-02']);
        $period->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $this->employee->id])->save();

        $this->credit('VL', 30);
        $this->credit('SL', 10);
    }

    public function test_partial_monetization_follows_the_legacy_rules(): void
    {
        $this->actingAs($this->employee->user)->get('/my/leave/monetization')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/MyMonetization')
            ->where('terms.max_days', 15)
            ->where('terms.daily_rate', 1000)
            ->where('terms.issues', []));

        $this->actingAs($this->employee->user)->post('/my/leave/monetization', ['days' => 16])->assertSessionHasErrors('days');
        $this->actingAs($this->employee->user)->post('/my/leave/monetization', ['days' => 10])->assertSessionHasNoErrors();

        $request = LeaveMonetization::query()->sole();
        $this->assertSame('10000.00', $request->amount);
        $this->assertSame('MON-2026-'.str_pad((string) $request->id, 5, '0', STR_PAD_LEFT), $request->reference_number);

        // Once a year.
        $this->actingAs($this->employee->user)->post('/my/leave/monetization', ['days' => 1])->assertSessionHasErrors('days');

        // HR approves (the VL comes off), then marks it released.
        $this->actingAs($this->hr)->post("/admin/hr/leave/monetization/{$request->id}/decide", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(20.0, app(LeaveCredits::class)->balance($this->employee, $this->type('VL')));
        $this->actingAs($this->hr)->post("/admin/hr/leave/monetization/{$request->id}/release")->assertSessionHasNoErrors();
        $this->assertSame('released', $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_monetization.decided']);
    }

    public function test_hr_never_decides_their_own_and_rejection_needs_a_reason(): void
    {
        $this->actingAs($this->employee->user)->post('/my/leave/monetization', ['days' => 5]);
        $request = LeaveMonetization::query()->sole();

        $this->actingAs($this->hr)->post("/admin/hr/leave/monetization/{$request->id}/decide", ['decision' => 'rejected'])->assertSessionHasErrors('remarks');

        $this->employee->user->roles()->attach($this->hr->roles()->first());
        $this->actingAs($this->employee->user->fresh())->post("/admin/hr/leave/monetization/{$request->id}/decide", ['decision' => 'approved'])->assertForbidden();
    }

    public function test_terminal_leave_commutes_all_vl_and_sl_on_separation(): void
    {
        $this->actingAs($this->hr)->post('/admin/hr/leave/monetization/terminal', ['employee_id' => $this->employee->id])->assertSessionHasErrors('employee_id');

        $this->employee->forceFill(['separation_date' => '2026-05-31', 'separation_reason' => 'Retirement'])->save();
        $this->actingAs($this->hr)->post('/admin/hr/leave/monetization/terminal', ['employee_id' => $this->employee->id])->assertSessionHasNoErrors();

        $request = LeaveMonetization::query()->sole();
        $this->assertSame('terminal', $request->kind);
        $this->assertSame('40000.00', $request->amount);

        $this->actingAs($this->hr)->post("/admin/hr/leave/monetization/{$request->id}/decide", ['decision' => 'approved']);
        $credits = app(LeaveCredits::class);
        $this->assertSame(0.0, $credits->balance($this->employee, $this->type('VL')));
        $this->assertSame(0.0, $credits->balance($this->employee, $this->type('SL')));
    }

    public function test_a_compressed_work_week_rest_day_is_not_a_leave_day(): void
    {
        $user = $this->employee->user;

        $this->actingAs($user)->post('/my/flexible-work', ['type' => 'cww', 'effective_from' => '2026-06-01', 'schedule' => ['Mon' => 'office', 'Fri' => 'office']])
            ->assertSessionHasErrors('schedule');
        $this->actingAs($user)->post('/my/flexible-work', ['type' => 'cww', 'effective_from' => '2026-06-01', 'schedule' => ['Mon' => 'office', 'Fri' => 'off', 'Sat' => 'off', 'Sun' => 'off']])
            ->assertSessionHasNoErrors();
        $arrangement = FwaArrangement::query()->sole();

        // Overlapping requests are refused.
        $this->actingAs($user)->post('/my/flexible-work', ['type' => 'wfh', 'effective_from' => '2026-07-01'])->assertSessionHasErrors('effective_from');

        $days = app(WorkingDays::class);
        $this->assertSame(5.0, $days->count('2026-06-15', '2026-06-19', employee: $this->employee));

        $this->actingAs($this->hr)->post("/admin/hr/leave/flexible-work/{$arrangement->id}/decide", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(4.0, $days->count('2026-06-15', '2026-06-19', employee: $this->employee));
        $this->assertSame(5.0, $days->count('2026-06-15', '2026-06-19'));

        // HR ends it early.
        $this->actingAs($this->hr)->post("/admin/hr/leave/flexible-work/{$arrangement->id}/terminate", ['effective_to' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->assertSame('terminated', $arrangement->fresh()->status);
    }

    public function test_weekly_reports_need_an_arrangement_and_lock_once_acknowledged(): void
    {
        $user = $this->employee->user;
        $week = ['week_start' => '2026-06-03', 'targets' => 'Draft the HR memo', 'accomplishments' => 'Drafted and circulated it'];

        $this->actingAs($user)->post('/my/flexible-work/reports', $week)->assertSessionHasErrors('week_start');

        $arrangement = new FwaArrangement;
        $arrangement->forceFill(['employee_id' => $this->employee->id, 'type' => 'wfh', 'effective_from' => '2026-06-01', 'status' => 'approved'])->save();

        $this->actingAs($user)->post('/my/flexible-work/reports', $week)->assertSessionHasNoErrors();
        $report = FwaReport::query()->sole();
        $this->assertSame('2026-06-01', $report->week_start->toDateString());
        $this->assertSame('2026-06-05', $report->week_end->toDateString());

        $this->actingAs($this->hr)->post("/admin/hr/leave/flexible-work/reports/{$report->id}/acknowledge")->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/my/flexible-work/reports', [...$week, 'accomplishments' => 'Changed'])->assertSessionHasErrors('week_start');
    }

    public function test_reports_and_the_calendar(): void
    {
        $viewer = $this->userWith(['leave.view']);
        Holiday::query()->create(['date' => '2026-06-12', 'name' => 'Independence Day', 'type' => 'regular']);

        // 30 VL, none taken: on the forced-leave list.
        $this->actingAs($viewer)->get('/admin/hr/leave/reports?report=forced_leave&year=2026')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Leave/Reports')
            ->has('rows', 1)
            ->where('rows.0.short', 5));
        $this->actingAs($viewer)->get('/admin/hr/leave/reports?report=adjustments&year=2026')->assertInertia(fn (Assert $page) => $page->has('rows', 2));
        $this->actingAs($viewer)->get('/admin/hr/leave/reports?report=bogus')->assertSessionHasErrors('report');
        $this->actingAs(User::factory()->create())->get('/admin/hr/leave/reports')->assertForbidden();

        $this->actingAs($this->employee->user)->get('/my/leave/calendar?month=2026-06')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/LeaveCalendar')
            ->where('month', '2026-06')
            ->where('holidays.0.name', 'Independence Day'));
    }

    private function credit(string $code, float $days): void
    {
        app(LeaveCredits::class)->postManual($this->employee, [
            'leave_type_id' => $this->type($code)->id, 'kind' => 'opening', 'entry_date' => '2026-01-01', 'amount' => $days, 'particulars' => 'Opening balance',
        ], $this->hr);
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->sole();
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
