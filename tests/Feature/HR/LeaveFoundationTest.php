<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Leave\LeaveCredits;
use App\Support\HR\Leave\WorkingDays;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-6a: leave types, holidays and working days, the credit ledger
 * (accrual, year start, opening balances, reversals) and leave settings.
 */
class LeaveFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 09:00:00');
        $this->seed(LeaveTypeSeeder::class);
    }

    public function test_pages_need_leave_permissions_and_viewing_cannot_change_anything(): void
    {
        $employee = $this->employee();
        $nobody = User::factory()->create();

        foreach (['/admin/hr/leave', '/admin/hr/leave/types', '/admin/hr/leave/holidays', "/admin/hr/employees/{$employee->id}/leave"] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
            $this->actingAs($this->userWith(['leave.view']))->get($url)->assertOk();
        }

        $viewer = $this->userWith(['leave.view']);
        $this->actingAs($viewer)->post('/admin/hr/leave/accrual', ['year' => 2026, 'month' => 5])->assertForbidden();
        $this->actingAs($viewer)->post("/admin/hr/employees/{$employee->id}/leave/entries", $this->opening('VL', 10))->assertForbidden();
    }

    public function test_seeded_types_follow_cs_form_6_and_reseeding_keeps_hr_edits(): void
    {
        $this->assertSame('VL', LeaveType::query()->where('code', 'FL')->sole()->chargedTo->code);
        $this->assertTrue(LeaveType::query()->where('code', 'SOLO')->sole()->requires_solo_parent_id);
        $this->assertFalse(LeaveType::query()->where('code', 'SPL')->sole()->requires_solo_parent_id);
        $this->assertNotSame(LeaveType::query()->where('code', 'SPL')->value('id'), LeaveType::query()->where('code', 'SOLO')->value('id'));

        LeaveType::query()->where('code', 'PL')->update(['max_days_per_filing' => 14]);
        $this->seed(LeaveTypeSeeder::class);

        $this->assertSame('14.00', LeaveType::query()->where('code', 'PL')->value('max_days_per_filing'));
        $this->assertSame(15, LeaveType::query()->count());
        // Wellness Leave waits, inactive, for HR to enter its terms.
        $this->assertFalse(LeaveType::query()->where('code', 'WL')->sole()->is_active);
    }

    public function test_working_days_skip_weekends_and_days_off_but_not_special_working_days(): void
    {
        Holiday::query()->create(['date' => '2026-06-12', 'name' => 'Independence Day', 'type' => 'regular']);
        Holiday::query()->create(['date' => '2026-06-17', 'name' => 'A special working day', 'type' => 'special_working']);
        $days = app(WorkingDays::class);

        // Mon 8 – Fri 19 June: 10 weekdays, minus the 12th.
        $this->assertSame(9.0, $days->count('2026-06-08', '2026-06-19'));
        $this->assertSame(0.5, $days->count('2026-06-15', '2026-06-15', halfDay: true));
        $this->assertSame(0.0, $days->count('2026-06-13', '2026-06-13', halfDay: true));
        $this->assertSame(12.0, $days->count('2026-06-08', '2026-06-19', calendarDays: true));
        $this->assertSame(0.0, $days->count('2026-06-19', '2026-06-08'));
    }

    public function test_monthly_accrual_credits_the_eligible_once(): void
    {
        $permanent = $this->employee(EmploymentStatus::Permanent);
        $notSet = $this->employee(null);
        $gone = Employee::factory()->create(['employment_status' => EmploymentStatus::Permanent, 'separation_date' => '2026-04-30', 'separation_reason' => 'Retirement']);
        $hr = $this->hr();

        $this->actingAs($hr)->post('/admin/hr/leave/accrual', ['year' => 2026, 'month' => 5])->assertSessionHas('status');

        $credits = app(LeaveCredits::class);
        $this->assertSame(1.25, $credits->balance($permanent, $this->type('VL')));
        $this->assertSame(1.25, $credits->balance($permanent, $this->type('SL')));
        // Employment status not set: not eligible for VL/SL.
        $this->assertSame(0.0, $credits->balance($notSet, $this->type('VL')));
        $this->assertSame(0.0, $credits->balance($gone, $this->type('VL')));

        // Re-running posts nothing; a reversed accrual stays reversed.
        $entry = $permanent->leaveLedger()->where('leave_type_id', $this->type('VL')->id)->sole();
        $credits->reverse($entry, 'Posted in error', $hr);
        $this->artisan('imis:leave-accrual --year=2026 --month=5')->assertSuccessful();
        $this->assertSame(0.0, $credits->balance($permanent, $this->type('VL')));
        $this->assertSame(1.25, $credits->balance($permanent, $this->type('SL')));

        // A month that hasn't started can't be accrued.
        $this->actingAs($hr)->post('/admin/hr/leave/accrual', ['year' => 2026, 'month' => 7])->assertSessionHas('error');
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_credit.accrual_run']);
    }

    public function test_the_year_start_lapses_unused_grants_and_posts_new_ones(): void
    {
        $employee = $this->employee();
        $soloParent = $this->employee();
        $soloParent->forceFill(['is_solo_parent' => true, 'solo_parent_id_number' => 'SP-123', 'solo_parent_id_expiry' => '2027-12-31'])->save();
        $credits = app(LeaveCredits::class);

        $credits->startYear(2025);
        $this->assertSame(3.0, $credits->balance($employee, $this->type('SPL'), now()->setDate(2025, 6, 1)));
        $this->assertSame(0.0, $credits->balance($employee, $this->type('SOLO'), now()->setDate(2025, 6, 1)));
        $this->assertSame(7.0, $credits->balance($soloParent, $this->type('SOLO'), now()->setDate(2025, 6, 1)));

        $result = $credits->startYear(2026);
        // Last year's 3 SPL lapsed on Dec 31; this year's 3 granted on Jan 1.
        $this->assertSame(0.0, $credits->balance($employee, $this->type('SPL'), now()->setDate(2025, 12, 31)));
        $this->assertSame(3.0, $credits->balance($employee, $this->type('SPL')));
        $this->assertGreaterThan(0, $result['lapsed']);

        // Re-running changes nothing.
        $again = $credits->startYear(2026);
        $this->assertSame(0, $again['granted']);
        $this->assertSame(0, $again['lapsed']);
        $this->assertSame(3.0, $credits->balance($employee, $this->type('SPL')));
    }

    public function test_hr_posts_opening_balances_and_reverses_entries_but_never_on_their_own_card(): void
    {
        $employee = $this->employee();
        $hr = $this->hr();
        $url = "/admin/hr/employees/{$employee->id}/leave";

        $this->actingAs($hr)->post("{$url}/entries", $this->opening('VL', 42.5))->assertSessionHasNoErrors();
        $this->actingAs($hr)->post("{$url}/entries", $this->opening('VL', -1))->assertSessionHasErrors('amount');
        $this->actingAs($hr)->post("{$url}/entries", $this->opening('ML', 5))->assertSessionHasErrors('leave_type_id');

        $entry = LeaveLedgerEntry::query()->sole();
        $this->actingAs($hr)->post("{$url}/entries/{$entry->id}/reverse", ['reason' => 'Wrong figure'])->assertSessionHasNoErrors();
        $this->actingAs($hr)->post("{$url}/entries/{$entry->id}/reverse", ['reason' => 'Again'])->assertSessionHasErrors('reason');
        $this->assertSame(0.0, app(LeaveCredits::class)->balance($employee, $this->type('VL')));
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_credit.reversed']);

        $own = Employee::factory()->for($hr)->create();
        $this->actingAs($hr)->post("/admin/hr/employees/{$own->id}/leave/entries", $this->opening('VL', 10))->assertForbidden();
    }

    public function test_the_card_runs_balances_from_what_was_brought_forward(): void
    {
        $employee = $this->employee();
        $hr = $this->hr();
        $url = "/admin/hr/employees/{$employee->id}/leave";
        $this->actingAs($hr)->post("{$url}/entries", [...$this->opening('VL', 10), 'entry_date' => '2025-12-31']);
        $this->actingAs($hr)->post("{$url}/entries", [...$this->opening('VL', -2), 'kind' => 'adjustment', 'entry_date' => '2026-03-01']);
        app(LeaveCredits::class)->accrueMonth(2026, 5);

        $this->actingAs($this->userWith(['leave.view']))->get("{$url}?year=2026")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/Employees/Leave')
                ->where('card.forward.'.$this->type('VL')->id, 10)
                ->where('card.lines.0.amount', -2)
                ->where('card.lines.0.balance', 8)
                ->where('card.lines.1.code', 'VL')
                ->where('card.lines.1.balance', 9.25)
                ->where('canManage', false));

        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_card.viewed', 'target_id' => $employee->id]);

        $this->actingAs($employee->user)->get('/my/leave')
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyLeave')->where('hasRecord', true)->has('card.lines', 3));
    }

    public function test_leave_settings_keep_the_solo_parent_id_encrypted(): void
    {
        $employee = $this->employee();
        $supervisor = User::factory()->create();
        $url = "/admin/hr/employees/{$employee->id}/leave/settings";

        $this->actingAs($this->hr())->put($url, ['immediate_supervisor_id' => $employee->user_id])->assertSessionHasErrors('immediate_supervisor_id');
        $this->actingAs($this->hr())->put($url, ['immediate_supervisor_id' => $supervisor->id, 'is_solo_parent' => true])->assertSessionHasErrors('solo_parent_id_number');

        $this->actingAs($this->hr())->put($url, [
            'immediate_supervisor_id' => $supervisor->id, 'is_solo_parent' => true, 'solo_parent_id_number' => 'SP-2026-0042', 'solo_parent_id_expiry' => '2027-06-30',
        ])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame($supervisor->id, $employee->immediate_supervisor_id);
        $this->assertTrue($employee->hasValidSoloParentId());
        $this->assertNotSame('SP-2026-0042', DB::table('employees')->where('id', $employee->id)->value('solo_parent_id_number'));
        $this->assertDatabaseMissing('audit_logs', ['metadata' => json_encode(['solo_parent_id_number' => 'SP-2026-0042'])]);
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function opening(string $code, float $amount): array
    {
        return ['leave_type_id' => $this->type($code)->id, 'kind' => 'opening', 'entry_date' => '2026-01-01', 'amount' => $amount, 'particulars' => 'Carried over from the paper leave card'];
    }

    private function employee(?EmploymentStatus $status = EmploymentStatus::Permanent): Employee
    {
        return Employee::factory()->create(['employment_status' => $status, 'original_appointment_date' => '2020-01-02']);
    }

    private function hr(): User
    {
        return $this->userWith(['leave.manage']);
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
