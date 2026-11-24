<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\DtrAdjustment;
use App\Models\DtrLeaveDeduction;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\LeaveApplication;
use App\Models\LeaveCardMonth;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\HR\Dtr\TardinessDeductions;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Charging a month's tardiness and undertime to leave credits, converted
 * with CSC Table IV (MC No. 14, s. 1999) and charged to Vacation Leave
 * (Omnibus Rules on Leave, Rule XVI Sec. 34). HR reviews, then posts.
 *
 * Fixture: October 2026, Compressed Work Week. Oct 6: 11 minutes late. Oct 7:
 * 19 late. Oct 8: leaves 1h50 short. So 30 late + 110 undertime = 140 minutes
 * = 2h20m = .250 + .042 = .292 day.
 */
class TardinessDeductionsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
        $this->hr = $this->userWith(['leave.manage']);
        $this->employee = Employee::factory()->create(['biometric_id' => '906']);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $this->employee->id, 'work_schedule_id' => WorkSchedule::query()->where('name', 'like', 'Compressed%')->value('id'), 'valid_from' => '2026-01-01']);
        $this->credit('VL', 10);
        $this->credit('SL', 10);
        $this->punch('2026-10-05', '07:45', '18:00');
        $this->punch('2026-10-06', '08:11', '19:20');
        $this->punch('2026-10-07', '08:19', '19:30');
        $this->punch('2026-10-08', '07:50', '16:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_month_lists_late_and_undertime_with_the_table_iv_days_and_the_balance(): void
    {
        $row = collect((new TardinessDeductions)->forMonth(Carbon::parse('2026-10-01')))->firstWhere('employee_id', $this->employee->id);

        $this->assertSame([30, 110, 140], [$row['late_minutes'], $row['undertime_minutes'], $row['minutes']]);
        $this->assertEqualsWithDelta(.292, $row['days'], 0.00001);
        $this->assertEqualsWithDelta(10.0, $row['available'], 0.00001);
        $this->assertEqualsWithDelta(0.0, $row['without_pay'], 0.00001);
        $this->assertNull($row['deduction']);
    }

    public function test_someone_with_no_late_or_undertime_is_not_listed(): void
    {
        $clean = Employee::factory()->create(['biometric_id' => '959']);

        $ids = collect((new TardinessDeductions)->forMonth(Carbon::parse('2026-10-01')))->pluck('employee_id');

        $this->assertTrue($ids->contains($this->employee->id));
        $this->assertFalse($ids->contains($clean->id));
    }

    public function test_posting_deducts_vacation_leave_dated_the_end_of_the_month(): void
    {
        $deduction = $this->chargeOctober();

        $entry = $deduction->entry;
        $this->assertSame(['VL', '2026-10-31'], [$entry->leaveType->code, $entry->entry_date->toDateString()]);
        $this->assertEqualsWithDelta(-.292, (float) $entry->amount, 0.00001);
        $this->assertStringContainsString('October 2026', $entry->particulars);
        $this->assertStringContainsString('2:20', $entry->particulars);
        $this->assertEqualsWithDelta(9.708, $this->balance('VL'), 0.00001);
        $this->assertSame([140, 10, 2026], [$deduction->minutes, $deduction->period_month, $deduction->period_year]);
        $this->assertSame(1, AuditLog::query()->where('event', 'dtr_leave.deducted')->count());
    }

    public function test_a_month_is_charged_once_until_the_charge_is_reversed(): void
    {
        $deduction = $this->chargeOctober();

        try {
            $this->chargeOctober();
            $this->fail('Charged the same month twice.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        (new TardinessDeductions)->reverse($deduction, 'Posted in error', $this->hr);

        $this->assertEqualsWithDelta(10.0, $this->balance('VL'), 0.00001);
        $this->assertSame(1, AuditLog::query()->where('event', 'dtr_leave.reversed')->count());

        $again = $this->chargeOctober();
        $this->assertNotSame($deduction->id, $again->id);
        $this->assertEqualsWithDelta(9.708, $this->balance('VL'), 0.00001);
    }

    public function test_the_row_shows_the_charge_and_flags_a_dtr_that_changed_afterwards(): void
    {
        $this->chargeOctober();
        DtrAdjustment::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-10-08', 'kind' => 'credit', 'remark' => 'Official business']);

        $row = collect((new TardinessDeductions)->forMonth(Carbon::parse('2026-10-01')))->firstWhere('employee_id', $this->employee->id);

        $this->assertEqualsWithDelta(.292, $row['deduction']['days'], 0.00001);
        $this->assertSame('VL', $row['deduction']['type']);
        $this->assertTrue($row['deduction']['dtr_changed']);
        $this->assertEqualsWithDelta(.062, $row['days'], 0.00001);
    }

    public function test_sick_leave_needs_the_health_reason_confirmed(): void
    {
        try {
            (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-10-01'), 'SL', false, $this->hr);
            $this->fail('Charged Sick Leave without a medical certificate.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $deduction = (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-10-01'), 'SL', true, $this->hr);

        $this->assertSame('SL', $deduction->entry->leaveType->code);
        $this->assertEqualsWithDelta(9.708, $this->balance('SL'), 0.00001);
        $this->assertEqualsWithDelta(10.0, $this->balance('VL'), 0.00001);
    }

    public function test_only_vacation_or_sick_leave_can_be_charged(): void
    {
        $this->expectException(ValidationException::class);

        (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-10-01'), 'FL', true, $this->hr);
    }

    public function test_a_month_that_has_not_ended_cannot_be_charged(): void
    {
        $this->expectException(ValidationException::class);

        (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-11-01'), 'VL', false, $this->hr);
    }

    public function test_a_month_with_nothing_to_charge_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-09-01'), 'VL', false, $this->hr);
    }

    public function test_the_unworked_half_of_a_half_day_leave_is_an_absence_and_is_not_charged(): void
    {
        LeaveApplication::query()->create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'),
            'date_from' => '2026-10-12', 'date_to' => '2026-10-12', 'is_half_day' => true, 'days' => .5, 'status' => 'approved',
        ]);

        $row = collect((new TardinessDeductions)->forMonth(Carbon::parse('2026-10-01')))->firstWhere('employee_id', $this->employee->id);

        $this->assertSame(140, $row['minutes']);
        $this->assertSame(1, $row['half_day_absences']);
    }

    public function test_only_the_credits_there_are_at_the_months_end_are_charged_and_the_rest_is_without_pay(): void
    {
        $this->adjust('VL', -9.9, '2026-10-15');
        $this->adjust('VL', -9.0, '2026-11-10');

        $row = collect((new TardinessDeductions)->forMonth(Carbon::parse('2026-10-01')))->firstWhere('employee_id', $this->employee->id);
        $this->assertEqualsWithDelta(.1, $row['available'], 0.00001);
        $this->assertEqualsWithDelta(.192, $row['without_pay'], 0.00001);

        $deduction = $this->chargeOctober();

        $this->assertEqualsWithDelta(.1, (float) $deduction->days, 0.00001);
        $this->assertEqualsWithDelta(.192, (float) $deduction->days_without_pay, 0.00001);
        $this->assertEqualsWithDelta(-.1, (float) $deduction->entry->amount, 0.00001);
    }

    public function test_a_balance_of_nothing_charges_nothing_but_still_records_the_days_without_pay(): void
    {
        $this->adjust('VL', -10, '2026-10-15');

        $deduction = $this->chargeOctober();

        $this->assertNull($deduction->entry);
        $this->assertEqualsWithDelta(.292, (float) $deduction->days_without_pay, 0.00001);
        $this->assertEqualsWithDelta(0.0, $this->balance('VL'), 0.00001);
        $this->assertSame(0, LeaveLedgerEntry::query()->where('particulars', 'like', 'Tardiness%')->count());

        (new TardinessDeductions)->reverse($deduction, 'Wrong month', $this->hr);

        $this->assertSame(1, AuditLog::query()->where('event', 'dtr_leave.reversed')->count());
        $this->assertNotNull($this->chargeOctober());
    }

    public function test_a_month_the_hr_workbook_already_covers_is_flagged_and_not_charged_here(): void
    {
        LeaveCardMonth::factory()->create(['employee_id' => $this->employee->id, 'year' => 2026, 'month' => 10]);

        $row = collect((new TardinessDeductions)->forMonth(Carbon::parse('2026-10-01')))->firstWhere('employee_id', $this->employee->id);
        $this->assertTrue($row['card_managed']);
        $this->assertFalse($row['card_pending']);

        $this->expectException(ValidationException::class);
        $this->chargeOctober();
    }

    public function test_a_month_after_the_workbooks_last_month_is_charged_here_as_pending(): void
    {
        LeaveCardMonth::factory()->create(['employee_id' => $this->employee->id, 'year' => 2026, 'month' => 7]);

        $row = collect((new TardinessDeductions)->forMonth(Carbon::parse('2026-10-01')))->firstWhere('employee_id', $this->employee->id);
        $this->assertFalse($row['card_managed']);
        $this->assertTrue($row['card_pending']);

        $deduction = $this->chargeOctober();

        $this->assertNotNull($deduction->entry);
        $this->assertStringContainsString('pending the leave card file', $deduction->entry->particulars);
    }

    public function test_a_tardiness_charge_cannot_be_corrected_from_the_leave_card(): void
    {
        $deduction = $this->chargeOctober();

        $this->expectException(ValidationException::class);
        (new LeaveCredits)->correct($deduction->entry, -.1, 'Wrong amount', $this->hr);
    }

    public function test_the_page_lists_the_month_and_hr_can_post_and_reverse(): void
    {
        $this->actingAs($this->userWith(['leave.view']))->get('/admin/hr/leave/tardiness?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/Leave/Tardiness')
                ->where('month', '2026-10')
                ->where('canManage', false)
                ->where('rows.0.employee_id', $this->employee->id)
                ->where('rows.0.minutes', 140)
                ->where('conversionTable.hours.8', 1)
                ->where('conversionTable.minutes.60', 0.125)
                ->has('conversionTable.minutes', 60));

        $this->actingAs($this->hr)->post('/admin/hr/leave/tardiness', ['employee_id' => $this->employee->id, 'month' => '2026-10', 'charge' => 'VL'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $deduction = DtrLeaveDeduction::query()->sole();

        $this->actingAs($this->hr)->post("/admin/hr/leave/tardiness/{$deduction->id}/reverse", ['reason' => 'Posted in error'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, LeaveLedgerEntry::query()->whereNotNull('reverses_id')->count());
    }

    public function test_a_viewer_cannot_post_and_hr_cannot_charge_their_own_leave(): void
    {
        $this->actingAs($this->userWith(['leave.view']))->post('/admin/hr/leave/tardiness', ['employee_id' => $this->employee->id, 'month' => '2026-10', 'charge' => 'VL'])
            ->assertForbidden();

        $this->actingAs($this->employee->user)->post('/admin/hr/leave/tardiness', ['employee_id' => $this->employee->id, 'month' => '2026-10', 'charge' => 'VL'])
            ->assertForbidden();

        $own = $this->userWith(['leave.manage']);
        $ownEmployee = Employee::factory()->create(['user_id' => $own->id, 'biometric_id' => '777']);
        $this->actingAs($own)->post('/admin/hr/leave/tardiness', ['employee_id' => $ownEmployee->id, 'month' => '2026-10', 'charge' => 'VL'])
            ->assertForbidden();

        $this->assertSame(0, DtrLeaveDeduction::query()->count());
    }

    public function test_the_page_is_closed_to_users_without_leave_permissions(): void
    {
        $this->actingAs($this->userWith([]))->get('/admin/hr/leave/tardiness')->assertForbidden();
    }

    private function chargeOctober(): DtrLeaveDeduction
    {
        return (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-10-01'), 'VL', false, $this->hr);
    }

    private function adjust(string $code, float $days, string $date): void
    {
        (new LeaveCredits)->postManual($this->employee, [
            'leave_type_id' => LeaveType::query()->where('code', $code)->value('id'),
            'kind' => 'adjustment',
            'amount' => $days,
            'entry_date' => $date,
            'particulars' => 'Test adjustment',
        ], $this->hr);
    }

    private function balance(string $code): float
    {
        return (new LeaveCredits)->balance($this->employee, LeaveType::query()->where('code', $code)->sole());
    }

    private function credit(string $code, float $days): void
    {
        (new LeaveCredits)->postManual($this->employee, [
            'leave_type_id' => LeaveType::query()->where('code', $code)->value('id'),
            'kind' => 'opening',
            'amount' => $days,
            'entry_date' => '2026-01-01',
            'particulars' => 'Opening balance',
        ], $this->hr);
    }

    private function punch(string $date, string $in, string $out): void
    {
        $import = BiometricImport::factory()->create();

        foreach ([$in, $out] as $time) {
            BiometricPunch::query()->create(['biometric_id' => '906', 'punched_at' => "{$date} {$time}:00", 'biometric_import_id' => $import->id]);
        }
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
