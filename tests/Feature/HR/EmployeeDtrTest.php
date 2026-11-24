<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HRD opens anyone's monthly DTR and corrects days with adjustments.
 */
class EmployeeDtrTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_viewer_sees_the_month_and_the_employees_sheets(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.view']))
            ->get("/admin/hr/employees/{$employee->id}/dtr?month=2026-10")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/Employees/Dtr')
                ->where('month', '2026-10')
                ->where('label', 'October 2026')
                ->where('employee.id', $employee->id)
                ->where('canManage', false)
                ->has('sheets', 1)
                ->has('sheets.0.rows', 31));
    }

    public function test_an_invalid_month_falls_back_to_this_month(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.view']))
            ->get("/admin/hr/employees/{$employee->id}/dtr?month=nonsense")
            ->assertInertia(fn (Assert $page) => $page->where('month', now()->format('Y-m')));
    }

    public function test_opening_someone_elses_dtr_is_audited_but_your_own_is_not(): void
    {
        $employee = Employee::factory()->create();
        $hrd = $this->userWith(['dtr.view']);
        $own = Employee::factory()->create(['user_id' => $hrd->id]);

        $this->actingAs($hrd)->get("/admin/hr/employees/{$employee->id}/dtr");
        $this->assertSame(1, AuditLog::query()->where('event', 'dtr.viewed')->count());

        $this->actingAs($hrd)->get("/admin/hr/employees/{$own->id}/dtr");
        $this->assertSame(1, AuditLog::query()->where('event', 'dtr.viewed')->count());
    }

    public function test_a_user_without_dtr_permissions_is_denied(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith([]))->get("/admin/hr/employees/{$employee->id}/dtr")->assertForbidden();
    }

    public function test_hrd_can_add_and_remove_an_adjustment_and_both_are_audited(): void
    {
        $employee = Employee::factory()->create();
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($hrd)->post("/admin/hr/employees/{$employee->id}/dtr/adjustments", [
            'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'Pasasalamat Program',
        ])->assertRedirect();

        $adjustment = DtrAdjustment::query()->sole();
        $this->assertSame(['credit', 'Pasasalamat Program', $hrd->id], [$adjustment->kind, $adjustment->remark, $adjustment->created_by]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'dtr_adjustment.saved']);

        $this->actingAs($hrd)->delete("/admin/hr/employees/{$employee->id}/dtr/adjustments/{$adjustment->id}")->assertRedirect();

        $this->assertSame(0, DtrAdjustment::query()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'dtr_adjustment.removed']);
    }

    public function test_a_times_adjustment_saves_the_times(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.manage']))->post("/admin/hr/employees/{$employee->id}/dtr/adjustments", [
            'date' => '2026-10-05', 'kind' => 'times', 'time_in' => '07:45', 'time_out' => '18:00', 'remark' => 'Forgot to punch out',
        ])->assertRedirect();

        $this->assertSame(['07:45', '18:00'], [substr(DtrAdjustment::query()->sole()->time_in, 0, 5), substr(DtrAdjustment::query()->sole()->time_out, 0, 5)]);
    }

    public function test_a_viewer_cannot_adjust(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.view']))->post("/admin/hr/employees/{$employee->id}/dtr/adjustments", [
            'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'x',
        ])->assertForbidden();

        $this->assertSame(0, DtrAdjustment::query()->count());
    }

    public function test_hrd_cannot_adjust_their_own_dtr(): void
    {
        $hrd = $this->userWith(['dtr.manage']);
        $own = Employee::factory()->create(['user_id' => $hrd->id]);

        $this->actingAs($hrd)->post("/admin/hr/employees/{$own->id}/dtr/adjustments", [
            'date' => '2026-10-05', 'kind' => 'credit', 'remark' => 'x',
        ])->assertForbidden();
    }

    public function test_bad_input_returns_validation_errors(): void
    {
        $employee = Employee::factory()->create();
        $hrd = $this->userWith(['dtr.manage']);

        $this->actingAs($hrd)->post("/admin/hr/employees/{$employee->id}/dtr/adjustments", ['date' => 'nope', 'kind' => 'holiday', 'remark' => ''])
            ->assertSessionHasErrors(['date', 'kind', 'remark']);
        $this->actingAs($hrd)->post("/admin/hr/employees/{$employee->id}/dtr/adjustments", ['date' => now()->addDays(3)->toDateString(), 'kind' => 'credit', 'remark' => 'x'])
            ->assertSessionHasErrors('date');
    }

    public function test_hrd_can_print_a_sheet_and_it_is_audited(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.view']))
            ->get("/admin/hr/employees/{$employee->id}/dtr/print?from=2026-10-19&to=2026-10-31")
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/DtrPrint')
                ->where('employee.id', $employee->id)
                ->where('sheet.period', 'October 19-31')
                ->has('sheet.rows', 13)
                ->has('generated_at'));

        $this->assertSame(1, AuditLog::query()->where('event', 'dtr.printed')->count());
    }

    public function test_printing_your_own_is_not_audited(): void
    {
        $hrd = $this->userWith(['dtr.view']);
        $own = Employee::factory()->create(['user_id' => $hrd->id]);

        $this->actingAs($hrd)->get("/admin/hr/employees/{$own->id}/dtr/print?from=2026-10-01&to=2026-10-31")->assertOk();

        $this->assertSame(0, AuditLog::query()->where('event', 'dtr.printed')->count());
    }

    public function test_a_print_range_must_stay_in_one_month_and_not_run_backwards(): void
    {
        $employee = Employee::factory()->create();
        $viewer = $this->userWith(['dtr.view']);

        $this->actingAs($viewer)->get("/admin/hr/employees/{$employee->id}/dtr/print?from=2026-10-20&to=2026-11-05")->assertSessionHasErrors('to');
        $this->actingAs($viewer)->get("/admin/hr/employees/{$employee->id}/dtr/print?from=2026-10-20&to=2026-10-10")->assertSessionHasErrors('to');
        $this->actingAs($viewer)->get("/admin/hr/employees/{$employee->id}/dtr/print")->assertSessionHasErrors(['from', 'to']);
    }

    public function test_a_malformed_print_range_is_a_validation_error_not_a_crash(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.view']))
            ->get("/admin/hr/employees/{$employee->id}/dtr/print?from[]=x&to=2026-10-31")
            ->assertSessionHasErrors('from');
    }

    public function test_the_audit_entries_say_which_month_or_range(): void
    {
        $employee = Employee::factory()->create();
        $viewer = $this->userWith(['dtr.view']);

        $this->actingAs($viewer)->get("/admin/hr/employees/{$employee->id}/dtr?month=2026-10");
        $this->actingAs($viewer)->get("/admin/hr/employees/{$employee->id}/dtr/print?from=2026-10-19&to=2026-10-31");

        $this->assertSame('2026-10', AuditLog::query()->where('event', 'dtr.viewed')->sole()->metadata['month']);
        $printed = AuditLog::query()->where('event', 'dtr.printed')->sole()->metadata;
        $this->assertSame(['2026-10-19', '2026-10-31'], [$printed['from'], $printed['to']]);
    }

    public function test_an_adjustment_date_must_be_a_plain_date(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith(['dtr.manage']))->post("/admin/hr/employees/{$employee->id}/dtr/adjustments", ['date' => 'next friday', 'kind' => 'credit', 'remark' => 'x'])
            ->assertSessionHasErrors('date');
    }

    public function test_a_user_without_permission_cannot_print_anyone_elses(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->userWith([]))->get("/admin/hr/employees/{$employee->id}/dtr/print?from=2026-10-01&to=2026-10-31")->assertForbidden();
    }

    public function test_an_adjustment_of_another_employee_cannot_be_removed_through_this_one(): void
    {
        $employee = Employee::factory()->create();
        $other = DtrAdjustment::factory()->create(['date' => '2026-10-05']);

        $this->actingAs($this->userWith(['dtr.manage']))->delete("/admin/hr/employees/{$employee->id}/dtr/adjustments/{$other->id}")->assertNotFound();

        $this->assertSame(1, DtrAdjustment::query()->count());
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
