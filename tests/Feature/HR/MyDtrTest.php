<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every employee reads their own DTR with no permission.
 */
class MyDtrTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_sees_their_own_sheets_and_nothing_is_audited(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '906']);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'work_schedule_id' => WorkSchedule::query()->where('name', 'like', 'Compressed%')->value('id'), 'valid_from' => '2026-01-01']);
        $import = BiometricImport::factory()->create();
        foreach (['2026-10-06 07:51:00', '2026-10-06 19:20:00'] as $time) {
            BiometricPunch::query()->create(['biometric_id' => '906', 'punched_at' => $time, 'biometric_import_id' => $import->id]);
        }

        $this->actingAs($employee->user)->get('/my/dtr?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/MyDtr')
                ->where('hasRecord', true)
                ->where('month', '2026-10')
                ->where('employee.id', $employee->id)
                ->where('sheets.0.rows.5.in', '07:51')
                ->where('sheets.0.rows.5.minutes', 600));

        $this->assertSame(0, AuditLog::query()->where('event', 'dtr.viewed')->count());
    }

    public function test_a_user_without_an_employee_record_gets_an_empty_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/my/dtr')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyDtr')->where('hasRecord', false)->where('sheets', []));
    }

    public function test_an_invalid_month_falls_back_to_this_month(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)->get('/my/dtr?month=2026-13')
            ->assertInertia(fn (Assert $page) => $page->where('month', now()->format('Y-m')));
    }

    public function test_you_only_ever_get_your_own_record(): void
    {
        $mine = Employee::factory()->create();
        $other = Employee::factory()->create();

        $this->actingAs($mine->user)->get("/my/dtr?employee={$other->id}")
            ->assertInertia(fn (Assert $page) => $page->where('employee.id', $mine->id));
    }

    public function test_an_employee_can_print_their_own_sheet_without_an_audit_entry(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)->get('/my/dtr/print?from=2026-10-01&to=2026-10-18')
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/DtrPrint')
                ->where('employee.id', $employee->id)
                ->where('sheet.period', 'October 1-18')
                ->has('sheet.rows', 18));

        $this->assertSame(0, AuditLog::query()->where('event', 'dtr.printed')->count());
    }

    public function test_a_user_without_an_employee_record_cannot_print(): void
    {
        $this->actingAs(User::factory()->create())->get('/my/dtr/print?from=2026-10-01&to=2026-10-18')->assertNotFound();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/my/dtr')->assertRedirect();
    }
}
