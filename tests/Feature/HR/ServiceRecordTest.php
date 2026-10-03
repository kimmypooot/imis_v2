<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-5: the Service Record, built from plantilla periods (HR-4) and the
 * reassignment history (HR-1b). Amounts are made up for the tests.
 */
class ServiceRecordTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private OrganizationalUnit $hrd;

    private OrganizationalUnit $samar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 09:00:00');
        $this->hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division']);
        $this->samar = OrganizationalUnit::factory()->create(['name' => 'CSC Field Office - Samar']);

        $user = User::factory()->create(['first_name' => 'Juan', 'middle_name' => 'Dela', 'last_name' => 'Cruz', 'birthday' => '1990-04-05', 'organizational_unit_id' => $this->samar->id]);
        $this->employee = Employee::factory()->for($user)->create();

        // Two schedules: the second takes effect mid-period.
        $old = SalarySchedule::query()->create(['name' => 'Old', 'effective_date' => '2024-01-01']);
        $old->rates()->createMany([['salary_grade' => 11, 'step' => 1, 'monthly_salary' => 20000], ['salary_grade' => 15, 'step' => 1, 'monthly_salary' => 30000]]);
        $new = SalarySchedule::query()->create(['name' => 'Third tranche, 2026', 'reference' => 'NBC No. 601', 'effective_date' => '2026-01-01']);
        $new->rates()->createMany([['salary_grade' => 15, 'step' => 1, 'monthly_salary' => 31000]]);

        $ao2 = PlantillaItem::query()->create(['item_number' => 'AO2-1', 'position_title' => 'Administrative Officer II', 'salary_grade' => 11]);
        $ao4 = PlantillaItem::query()->create(['item_number' => 'AO4-1', 'position_title' => 'Administrative Officer IV', 'salary_grade' => 15]);

        $this->period($ao2, ['date_from' => '2024-02-01', 'date_to' => '2025-06-30', 'end_reason' => 'Promotion to AO4-1']);
        $this->period($ao4, ['nature' => 'promotion', 'date_from' => '2025-07-01']);

        // Reassigned from HRD to Samar on 2026-03-01.
        $move = new EmployeeAssignment(['kind' => 'reassignment', 'from_unit_id' => $this->hrd->id, 'to_unit_id' => $this->samar->id, 'effective_date' => '2026-03-01']);
        $move->forceFill(['employee_id' => $this->employee->id, 'applied_at' => now()])->save();
    }

    public function test_lines_break_at_new_schedules_and_reassignments(): void
    {
        $this->actingAs($this->userWith(['service_records.view']))->get("/admin/hr/employees/{$this->employee->id}/service-record")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/Employees/ServiceRecord')
                ->where('person.surname', 'Cruz')
                ->has('lines', 4)
                // AO II, whole period, priced by the old schedule; separation cause on its last line.
                ->where('lines.0.designation', 'Administrative Officer II')
                ->where('lines.0.date_to', '2025-06-30')
                ->where('lines.0.annual_salary', 240000)
                ->where('lines.0.station', 'Human Resource Division')
                ->where('lines.0.separation_cause', 'Promotion to AO4-1')
                ->where('lines.0.branch', 'National')
                // AO IV until the new schedule…
                ->where('lines.1.date_from', '2025-07-01')
                ->where('lines.1.date_to', '2025-12-31')
                ->where('lines.1.annual_salary', 360000)
                ->where('lines.1.separation_cause', null)
                // …then the new schedule until the reassignment…
                ->where('lines.2.date_from', '2026-01-01')
                ->where('lines.2.date_to', '2026-02-28')
                ->where('lines.2.annual_salary', 372000)
                // …then Samar, to the present.
                ->where('lines.3.date_from', '2026-03-01')
                ->where('lines.3.date_to', null)
                ->where('lines.3.station', 'CSC Field Office - Samar'));

        $this->assertDatabaseHas('audit_logs', ['event' => 'service_record.viewed', 'target_id' => $this->employee->id]);
    }

    public function test_hr_prints_a_certified_copy_signed_by_the_officer_in_settings(): void
    {
        SettingRegistry::sync();
        Setting::query()->where('key', 'hr_certifying_officer_name')->update(['value' => 'Maria Santos']);
        Settings::flush();

        $this->actingAs($this->userWith(['service_records.view']))->get("/admin/hr/employees/{$this->employee->id}/service-record/print")
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/ServiceRecordPrint')
                ->where('certified', true)
                ->where('certification.name', 'Maria Santos')
                ->has('lines', 4));

        $this->assertDatabaseHas('audit_logs', ['event' => 'service_record.printed']);
    }

    public function test_access_is_hr_or_the_employee_never_a_supervisor(): void
    {
        $supervisor = $this->userWith(['employees.view_unit']);
        $supervisor->forceFill(['organizational_unit_id' => $this->samar->id])->save();
        $this->actingAs($supervisor)->get("/admin/hr/employees/{$this->employee->id}/service-record")->assertForbidden();

        $this->actingAs($this->employee->user)->get('/my/service-record')
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyServiceRecord')->where('hasRecord', true)->has('lines', 4));

        // Their own printout is a reference copy, not certified.
        $this->actingAs($this->employee->user)->get('/my/service-record/print')
            ->assertInertia(fn (Assert $page) => $page->where('certified', false));

        $this->assertDatabaseMissing('audit_logs', ['event' => 'service_record.viewed']);
    }

    public function test_someone_without_an_employee_record_sees_an_empty_state(): void
    {
        $this->actingAs(User::factory()->create())->get('/my/service-record')
            ->assertInertia(fn (Assert $page) => $page->where('hasRecord', false));
        $this->actingAs(User::factory()->create())->get('/my/service-record/print')->assertNotFound();
    }

    public function test_a_period_on_an_item_without_a_grade_stays_off_until_completed(): void
    {
        $item = PlantillaItem::query()->create(['position_title' => 'Director II']);
        $this->period($item, ['date_from' => '2020-01-02', 'date_to' => '2024-01-31', 'end_reason' => 'Transfer']);

        $hr = $this->userWith(['service_records.view']);
        $this->actingAs($hr)->get("/admin/hr/employees/{$this->employee->id}/service-record")
            ->assertInertia(fn (Assert $page) => $page->has('lines', 4));

        $item->update(['salary_grade' => 26]);
        $this->actingAs($hr)->get("/admin/hr/employees/{$this->employee->id}/service-record")
            // Two lines: the period crosses the 2024-01-01 schedule.
            ->assertInertia(fn (Assert $page) => $page->has('lines', 6)->where('lines.0.designation', 'Director II')->where('lines.1.designation', 'Director II'));
    }

    public function test_the_branch_comes_from_settings(): void
    {
        $this->assertSame('National', SettingRegistry::default('hr_service_record_branch'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function period(PlantillaItem $item, array $attributes): void
    {
        $period = new PlantillaAppointment(['step' => 1, 'status' => 'permanent', 'nature' => 'original', ...$attributes]);
        $period->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $this->employee->id])->save();
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
