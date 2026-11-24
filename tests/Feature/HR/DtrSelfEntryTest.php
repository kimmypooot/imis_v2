<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An employee enters their own in and out times on /my/dtr, after certifying
 * them as true and correct. HRD's adjustments stay HRD's.
 */
class DtrSelfEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_employee_can_enter_their_times_once_they_certify_and_it_shows_as_employee_entered(): void
    {
        $employee = $this->employeeWithSchedule();

        $this->actingAs($employee->user)->post('/my/dtr/adjustments', [
            'entries' => [['date' => '2026-11-10', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => 'Fingerprint reader was down']],
            'certify' => true,
        ])->assertRedirect()->assertSessionHas('status');

        $saved = DtrAdjustment::query()->sole();
        $this->assertTrue($saved->entered_by_employee);
        $this->assertSame($employee->user->id, $saved->created_by);

        $this->actingAs($employee->user)->get('/my/dtr?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('sheets.0.rows.9.in', '07:50')
            ->where('sheets.0.rows.9.remark', 'Employee-entered: Fingerprint reader was down')
            ->where('sheets.0.rows.9.adjustment.by_employee', true));

        $audit = AuditLog::query()->where('event', 'dtr_adjustment.saved')->sole();
        $this->assertTrue($audit->metadata['by_employee']);
        $this->assertSame('2026-11-10', $audit->metadata['entries'][0]['date']);
    }

    public function test_nothing_is_saved_without_the_certification(): void
    {
        $employee = $this->employeeWithSchedule();

        $this->actingAs($employee->user)->post('/my/dtr/adjustments', [
            'entries' => [['date' => '2026-11-10', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => 'Reader down']],
        ])->assertSessionHasErrors('certify');
        $this->actingAs($employee->user)->post('/my/dtr/adjustments', [
            'entries' => [['date' => '2026-11-10', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => 'Reader down']],
            'certify' => false,
        ])->assertSessionHasErrors('certify');

        $this->assertSame(0, DtrAdjustment::query()->count());
    }

    public function test_a_reason_is_required_and_one_bad_row_saves_nothing(): void
    {
        $employee = $this->employeeWithSchedule();

        $this->actingAs($employee->user)->post('/my/dtr/adjustments', [
            'entries' => [
                ['date' => '2026-11-10', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => 'Reader down'],
                ['date' => '2026-11-11', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => ''],
            ],
            'certify' => true,
        ])->assertSessionHasErrors('entries');

        $this->assertSame(0, DtrAdjustment::query()->count());
    }

    public function test_the_time_out_must_follow_the_time_in_and_the_future_and_old_days_are_refused(): void
    {
        $employee = $this->employeeWithSchedule();

        foreach ([
            ['date' => '2026-11-10', 'time_in' => '17:00', 'time_out' => '08:00', 'remark' => 'x'],
            ['date' => '2026-11-25', 'time_in' => '08:00', 'time_out' => '17:00', 'remark' => 'x'],
            ['date' => '2026-09-30', 'time_in' => '08:00', 'time_out' => '17:00', 'remark' => 'x'],
        ] as $entry) {
            $this->actingAs($employee->user)->post('/my/dtr/adjustments', ['entries' => [$entry], 'certify' => true])->assertSessionHasErrors('entries');
        }

        $this->assertSame(0, DtrAdjustment::query()->count());
    }

    public function test_a_day_hrd_adjusted_stays_hrds(): void
    {
        $employee = $this->employeeWithSchedule();
        $hrd = DtrAdjustment::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-10', 'kind' => 'credit', 'remark' => 'Official business']);

        $this->actingAs($employee->user)->post('/my/dtr/adjustments', [
            'entries' => [['date' => '2026-11-10', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => 'Mine']],
            'certify' => true,
        ])->assertSessionHasErrors('entries');

        $this->assertSame('Official business', $hrd->fresh()->remark);
        $this->assertFalse($hrd->fresh()->entered_by_employee);
    }

    public function test_clearing_both_times_takes_back_the_employees_own_entry(): void
    {
        $employee = $this->employeeWithSchedule();
        $this->actingAs($employee->user)->post('/my/dtr/adjustments', [
            'entries' => [['date' => '2026-11-10', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => 'Reader down']],
            'certify' => true,
        ]);

        $this->actingAs($employee->user)->post('/my/dtr/adjustments', [
            'entries' => [['date' => '2026-11-10', 'time_in' => null, 'time_out' => null, 'remark' => '']],
            'certify' => true,
        ])->assertSessionHas('status');

        $this->assertSame(0, DtrAdjustment::query()->count());
    }

    public function test_someone_without_an_employee_record_cannot_enter_times(): void
    {
        $this->actingAs(User::factory()->create())->post('/my/dtr/adjustments', [
            'entries' => [['date' => '2026-11-10', 'time_in' => '07:50', 'time_out' => '17:10', 'remark' => 'x']],
            'certify' => true,
        ])->assertNotFound();
    }

    private function employeeWithSchedule(): Employee
    {
        $employee = Employee::factory()->create();
        EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'work_schedule_id' => WorkSchedule::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_to' => null]);

        return $employee;
    }
}
