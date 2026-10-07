<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wellness Leave (CSC MC No. 01, s. 2026): 5 days a year from the date
 * of assumption of duty, at most 3 days in a row, filed 5 days ahead or
 * on return in an emergency.
 */
class WellnessLeaveTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 09:00:00'); // A Monday.
        $this->seed(LeaveTypeSeeder::class);

        $this->employee = Employee::factory()->create([
            'employment_status' => EmploymentStatus::Permanent,
            'original_appointment_date' => '2020-01-02',
            'immediate_supervisor_id' => User::factory()->create()->id,
        ]);
    }

    public function test_it_is_granted_from_the_date_of_assumption_of_duty(): void
    {
        $newHire = Employee::factory()->create(['employment_status' => EmploymentStatus::Contractual, 'original_appointment_date' => '2026-05-04']);
        $jobOrder = Employee::factory()->create(['employment_status' => EmploymentStatus::JobOrder, 'original_appointment_date' => '2025-01-06']);

        $this->artisan('imis:leave-accrual')->assertSuccessful();

        $this->assertSame('2026-01-01', $this->grant($this->employee)?->entry_date->toDateString());
        $this->assertSame('2026-05-04', $this->grant($newHire)?->entry_date->toDateString());
        $this->assertNull($this->grant($jobOrder)); // Only as their contract provides.
        $this->assertSame(5.0, app(LeaveCredits::class)->balance($newHire, $this->wellness()));

        // Re-running grants nothing twice.
        app(LeaveCredits::class)->grantDue();
        app(LeaveCredits::class)->startYear(2026);
        $this->assertSame(1, LeaveLedgerEntry::query()->where('employee_id', $newHire->id)->where('leave_type_id', $this->wellness()->id)->count());
    }

    public function test_at_most_three_days_in_a_row_even_across_filings(): void
    {
        app(LeaveCredits::class)->grantDue();

        $this->apply('2026-06-15', '2026-06-18')->assertSessionHasErrors('date_to'); // 4 days.
        $this->apply('2026-06-19', '2026-06-19')->assertSessionHasNoErrors(); // Friday.
        // Monday to Wednesday right after that Friday makes 4 in a row.
        $this->apply('2026-06-22', '2026-06-24')->assertSessionHasErrors('date_to');
        // With the Monday worked, Tuesday and Wednesday are fine.
        $this->apply('2026-06-23', '2026-06-24')->assertSessionHasNoErrors();

        $this->assertSame(2, LeaveApplication::query()->count());
    }

    public function test_an_emergency_is_filed_on_return_instead_of_in_advance(): void
    {
        app(LeaveCredits::class)->grantDue();
        app(LeaveCredits::class)->postManual($this->employee, [
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'), 'kind' => 'opening', 'entry_date' => '2026-01-01', 'amount' => 10, 'particulars' => 'Opening balance',
        ], User::factory()->create());

        // Last Friday, without and with "Emergency".
        $this->apply('2026-05-29', '2026-05-29')->assertSessionHasErrors('date_from');
        $this->apply('2026-05-29', '2026-05-29', ['is_emergency' => true])->assertSessionHasNoErrors();
        $this->assertTrue(LeaveApplication::query()->sole()->is_emergency);

        // Not for leave that hasn't started yet…
        $this->apply('2026-06-03', '2026-06-03', ['is_emergency' => true])->assertSessionHasErrors('date_from');
        // …nor for a leave without the emergency rule.
        $this->actingAs($this->employee->user)->post('/my/leave/applications', [
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'), 'date_from' => '2026-05-29', 'date_to' => '2026-05-29', 'detail' => 'within_ph', 'is_emergency' => true,
        ])->assertSessionHasErrors('date_from');

        // Filed well ahead, the box changes nothing.
        $this->apply('2026-06-15', '2026-06-15', ['is_emergency' => true])->assertSessionHasNoErrors();
        $this->assertFalse(LeaveApplication::query()->latest('id')->first()->is_emergency);
    }

    private function apply(string $from, string $to, array $extra = [])
    {
        return $this->actingAs($this->employee->user)->post('/my/leave/applications', [
            'leave_type_id' => $this->wellness()->id, 'date_from' => $from, 'date_to' => $to, ...$extra,
        ]);
    }

    private function grant(Employee $employee): ?LeaveLedgerEntry
    {
        return LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $this->wellness()->id)->where('kind', 'grant')->first();
    }

    private function wellness(): LeaveType
    {
        return LeaveType::query()->where('code', 'WL')->sole();
    }
}
