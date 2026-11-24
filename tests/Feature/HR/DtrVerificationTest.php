<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\DtrAdjustment;
use App\Models\DtrLeaveDeduction;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\HrUpdate;
use App\Support\HR\Dtr\TardinessDeductions;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Days an employee enters themselves are verified by their supervisor or
 * office head (CS Form 48) before they are charged to leave credits; a
 * charged month is closed to the employee; habitual tardiness and
 * absenteeism (CSC MC No. 23, s. 1998) are flagged for HR.
 */
class DtrVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $supervisor;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
        $this->supervisor = User::factory()->create();
        $this->hr = $this->userWith(['leave.manage']);
        $this->employee = Employee::factory()->create(['biometric_id' => '906', 'immediate_supervisor_id' => $this->supervisor->id]);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $this->employee->id, 'work_schedule_id' => WorkSchedule::query()->where('name', 'like', 'Compressed%')->value('id'), 'valid_from' => '2026-01-01']);
        (new LeaveCredits)->postManual($this->employee, [
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'),
            'kind' => 'opening', 'amount' => 10, 'entry_date' => '2026-01-01', 'particulars' => 'Opening balance',
        ], $this->hr);
        $this->punch('906', '2026-10-06', '08:11', '19:20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_entering_times_tells_the_supervisor_who_then_verifies_them(): void
    {
        Notification::fake();
        $this->enter('2026-10-09');

        Notification::assertSentTo($this->supervisor, HrUpdate::class);
        $adjustment = DtrAdjustment::query()->sole();
        $this->assertNull($adjustment->verified_at);

        $this->actingAs($this->supervisor)->get('/dtr/entries')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/DtrVerify')->has('entries', 1)->where('entries.0.employee', $this->employee->user->name));

        $this->actingAs($this->supervisor)->post('/dtr/entries', ['ids' => [$adjustment->id]])->assertSessionHas('status');

        $this->assertSame($this->supervisor->id, $adjustment->fresh()->verified_by);
        $this->assertNotNull(AuditLog::query()->where('event', 'dtr_entry.verified')->first());
    }

    public function test_only_their_supervisor_or_head_or_hr_can_verify_and_never_the_employee(): void
    {
        $this->enter('2026-10-09');
        $id = DtrAdjustment::query()->value('id');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post('/dtr/entries', ['ids' => [$id]]);
        $this->actingAs($this->employee->user)->post('/dtr/entries', ['ids' => [$id]]);
        $this->assertNull(DtrAdjustment::query()->find($id)->verified_at);

        $this->actingAs($stranger)->get('/dtr/entries')->assertInertia(fn (Assert $page) => $page->has('entries', 0));
    }

    public function test_changing_an_entry_clears_its_verification(): void
    {
        $this->enter('2026-10-09');
        $this->actingAs($this->supervisor)->post('/dtr/entries', ['ids' => [DtrAdjustment::query()->value('id')]]);
        $this->assertNotNull(DtrAdjustment::query()->sole()->verified_at);

        $this->enter('2026-10-09', '07:40');

        $this->assertNull(DtrAdjustment::query()->sole()->verified_at);
    }

    public function test_an_unverified_day_blocks_the_charge_unless_hr_charges_anyway_and_it_is_audited(): void
    {
        $this->enter('2026-10-09');
        $deductions = new TardinessDeductions;

        try {
            $deductions->post($this->employee, Carbon::parse('2026-10-01'), 'VL', false, $this->hr);
            $this->fail('An unverified day should block the charge.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not verified', $e->errors()['month'][0]);
        }

        $deductions->post($this->employee, Carbon::parse('2026-10-01'), 'VL', false, $this->hr, true);

        $audit = AuditLog::query()->where('event', 'dtr_leave.deducted')->sole();
        $this->assertTrue($audit->metadata['posted_anyway']);
        $this->assertSame(1, $audit->metadata['unverified_days']);
    }

    public function test_hr_can_verify_the_month_as_a_fallback_and_the_row_shows_the_entered_days(): void
    {
        $this->enter('2026-10-09');

        $this->actingAs($this->hr)->get('/admin/hr/leave/tardiness?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.unverified_days', 1)->has('rows.0.entered_days', 1));

        $this->actingAs($this->hr)->post('/admin/hr/leave/tardiness/verify', ['employee_id' => $this->employee->id, 'month' => '2026-10'])->assertSessionHas('status');

        $this->actingAs($this->hr)->get('/admin/hr/leave/tardiness?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('rows.0.unverified_days', 0));
    }

    public function test_charge_all_reviewed_leaves_the_rows_that_need_a_decision(): void
    {
        $other = Employee::factory()->create(['biometric_id' => '907']);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $other->id, 'work_schedule_id' => WorkSchedule::query()->where('name', 'like', 'Compressed%')->value('id'), 'valid_from' => '2026-01-01']);
        (new LeaveCredits)->postManual($other, ['leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'), 'kind' => 'opening', 'amount' => 10, 'entry_date' => '2026-01-01', 'particulars' => 'Opening balance'], $this->hr);
        $this->punch('907', '2026-10-07', '08:20', '19:30');
        $this->enter('2026-10-09');

        $this->actingAs($this->hr)->post('/admin/hr/leave/tardiness/reviewed', ['month' => '2026-10'])->assertSessionHas('status');

        $this->assertDatabaseHas('dtr_leave_deductions', ['employee_id' => $other->id]);
        $this->assertDatabaseMissing('dtr_leave_deductions', ['employee_id' => $this->employee->id]);
    }

    public function test_sync_charges_what_is_ready_and_recharges_a_month_whose_dtr_changed(): void
    {
        $deductions = new TardinessDeductions;
        $first = $deductions->post($this->employee, Carbon::parse('2026-10-01'), 'VL', false, $this->hr);

        // A late import brings a second late day after the month was charged.
        $this->punch('906', '2026-10-07', '08:40', '19:30');

        $this->actingAs($this->hr)->post('/admin/hr/leave/tardiness/sync', ['month' => '2026-10'])->assertSessionHas('status');

        $this->assertNotNull($first->fresh()->reversed_at);
        $active = DtrLeaveDeduction::query()->whereNull('reversed_at')->sole();
        $this->assertGreaterThan($first->minutes, $active->minutes);
        $this->assertSame('VL', $active->leaveType->code);

        // Nothing left to do: a second sync changes nothing.
        $this->actingAs($this->hr)->post('/admin/hr/leave/tardiness/sync', ['month' => '2026-10'])->assertSessionHas('status', 'Already in sync with the DTR.');
        $this->assertSame(2, DtrLeaveDeduction::query()->count());
    }

    public function test_the_employee_is_told_when_tardiness_is_charged(): void
    {
        Notification::fake();

        (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-10-01'), 'VL', false, $this->hr);

        Notification::assertSentTo($this->employee->user, HrUpdate::class, fn (HrUpdate $notice) => str_contains($notice->toArray($this->employee->user)['message'], 'charged to your Vacation Leave'));
    }

    public function test_hr_is_reminded_once_a_month_while_tardiness_is_uncharged(): void
    {
        $this->artisan('imis:remind-tardiness-sync')->assertSuccessful();
        $this->artisan('imis:remind-tardiness-sync')->assertSuccessful();

        $this->assertSame(1, $this->hr->notifications()->where('data->title', 'Sync October 2026 tardiness with leave credits')->count());
    }

    public function test_no_reminder_when_everything_is_charged(): void
    {
        (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-10-01'), 'VL', false, $this->hr);
        Notification::fake();

        $this->artisan('imis:remind-tardiness-sync')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_sync_needs_leave_manage_and_a_finished_month(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/hr/leave/tardiness/sync', ['month' => '2026-10'])->assertForbidden();

        $this->actingAs($this->hr)->post('/admin/hr/leave/tardiness/sync', ['month' => '2026-11'])->assertSessionHasErrors('month');
    }

    public function test_a_month_charged_to_leave_credits_is_closed_to_the_employees_own_entries(): void
    {
        (new TardinessDeductions)->post($this->employee, Carbon::parse('2026-10-01'), 'VL', false, $this->hr);

        $this->enter('2026-10-09')->assertSessionHasErrors('entries');

        $this->assertSame(0, DtrAdjustment::query()->count());
        $this->actingAs($this->employee->user)->get('/my/dtr?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('chargedMonths', ['2026-10']));
    }

    public function test_ten_late_days_in_two_months_are_flagged_as_possible_habitual_tardiness(): void
    {
        $days = [
            '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-21', '2026-09-22', '2026-09-23',
            '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-26', '2026-10-27', '2026-10-28',
        ];
        foreach ($days as $date) {
            $this->punch('906', $date, '09:40', '19:40');
        }

        $this->actingAs($this->hr)->get('/admin/hr/leave/tardiness?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.habitual.tardiness', fn ($text) => str_contains($text, 'September') && str_contains($text, 'October')));
    }

    public function test_the_menu_entry_is_only_for_supervisors_and_heads(): void
    {
        $this->actingAs($this->supervisor)->get('/my/dtr')->assertInertia(fn (Assert $page) => $page->where('flags.dtrVerifier', true));
        $this->actingAs($this->employee->user)->get('/my/dtr')->assertInertia(fn (Assert $page) => $page->where('flags.dtrVerifier', false));
    }

    public function test_an_absence_offers_filing_leave_for_that_day(): void
    {
        $this->actingAs($this->employee->user)->get('/my/leave/apply?date_from=2026-10-13')->assertInertia(fn (Assert $page) => $page
            ->where('prefill.date_from', '2026-10-13'));
    }

    private function enter(string $date, string $in = '07:50'): TestResponse
    {
        return $this->actingAs($this->employee->user)->post('/my/dtr/adjustments', [
            'entries' => [['date' => $date, 'time_in' => $in, 'time_out' => '18:00', 'remark' => 'Fingerprint reader was down']],
            'certify' => true,
        ]);
    }

    private function punch(string $biometricId, string $date, string $in, string $out): void
    {
        $import = BiometricImport::factory()->create();

        foreach ([$in, $out] as $time) {
            BiometricPunch::query()->create(['biometric_id' => $biometricId, 'punched_at' => "{$date} {$time}:00", 'biometric_import_id' => $import->id]);
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
