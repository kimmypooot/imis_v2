<?php

namespace Tests\Feature\HR;

use App\Enums\AppointmentNature;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-4: plantilla items, who holds them (periods of service), and the
 * salary schedules that price them. The amounts below are made up for
 * the tests; real ones are entered by HR from the issuance.
 */
class PlantillaTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $hrd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 09:00:00');
        $this->hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD']);
    }

    public function test_pages_need_plantilla_permissions_and_viewing_alone_cannot_change_anything(): void
    {
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get('/admin/hr/plantilla')->assertForbidden();
        $this->actingAs($nobody)->get('/admin/hr/salary-schedules')->assertForbidden();

        $viewer = $this->userWith(['plantilla.view']);
        $this->actingAs($viewer)->get('/admin/hr/plantilla')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
        $this->actingAs($viewer)->post('/admin/hr/plantilla', $this->itemData())->assertForbidden();
        $this->actingAs($viewer)->post('/admin/hr/salary-schedules', $this->scheduleData())->assertForbidden();
    }

    public function test_the_item_form_suggests_position_titles_already_on_accounts_and_items(): void
    {
        $hr = $this->hr();
        $hr->forceFill(['position' => 'Senior Human Resource Specialist'])->save();
        User::factory()->create(['position' => ' senior human resource specialist ']);
        User::factory()->create(['position' => 'Attorney V']);
        $this->item(['position_title' => 'Director II']);

        $this->actingAs($hr)->get('/admin/hr/plantilla')->assertInertia(fn (Assert $page) => $page
            ->where('itemForm.positionTitles', ['Attorney V', 'Director II', 'Senior Human Resource Specialist']));
    }

    public function test_appointing_lists_staff_whose_position_matches_the_item_first(): void
    {
        $hr = $this->hr();
        $item = $this->item();
        $match = $this->employee();
        $match->user->forceFill(['name' => 'Zed Match', 'position' => ' administrative officer iv '])->save();
        $other = $this->employee();
        $other->user->forceFill(['name' => 'Ann Other', 'position' => 'Attorney V'])->save();

        $this->actingAs($hr)->get("/admin/hr/plantilla/{$item->id}")->assertInertia(fn (Assert $page) => $page
            ->where('appointForm.matches', 1)
            ->where('appointForm.employees.0.label', 'Position matches (Administrative Officer IV)')
            ->where('appointForm.employees.0.options', [['value' => $match->id, 'label' => 'Zed Match']])
            ->where('appointForm.employees.1.options', [['value' => $other->id, 'label' => 'Ann Other — Attorney V']]));

        // No match: one flat list, each with their current title.
        $match->user->forceFill(['position' => 'Director II'])->save();
        $this->actingAs($hr)->get("/admin/hr/plantilla/{$item->id}")->assertInertia(fn (Assert $page) => $page
            ->where('appointForm.matches', 0)
            ->where('appointForm.employees', [
                ['value' => $other->id, 'label' => 'Ann Other — Attorney V'],
                ['value' => $match->id, 'label' => 'Zed Match — Director II'],
            ]));
    }

    public function test_hr_adds_an_item_and_it_shows_as_vacant_priced_at_step_one(): void
    {
        $hr = $this->hr();
        $this->schedule();

        $this->actingAs($hr)->post('/admin/hr/plantilla', $this->itemData())->assertRedirect()->assertSessionHasNoErrors();
        $item = PlantillaItem::query()->sole();
        $this->assertDatabaseHas('audit_logs', ['event' => 'plantilla_item.created', 'target_id' => $item->id]);

        // The item number is unique.
        $this->actingAs($hr)->post('/admin/hr/plantilla', $this->itemData())->assertSessionHasErrors('item_number');

        $this->actingAs($hr)->get('/admin/hr/plantilla')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Plantilla/Index')
            ->where('items.0.status', 'vacant')
            ->where('items.0.office', 'HRD')
            ->where('items.0.monthly_salary', 30000)
            ->where('counts.vacant', 1)
            ->where('schedule.reference', 'NBC No. 601'));
    }

    public function test_appointing_fills_the_item_and_the_holder_follows_it(): void
    {
        $item = $this->item();
        $employee = $this->employee();

        $this->actingAs($this->hr())->post("/admin/hr/plantilla/{$item->id}/appointments", $this->appointment($employee))
            ->assertSessionHasNoErrors();

        $appointment = PlantillaAppointment::query()->sole();
        $this->assertTrue($appointment->isOpen());
        $this->assertSame('Administrative Officer IV', $employee->user->fresh()->position);
        $this->assertSame(EmploymentStatus::Permanent, $employee->fresh()->employment_status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'plantilla_appointment.appointed', 'target_id' => $appointment->id]);

        // A filled item can't take another appointment.
        $this->actingAs($this->hr())->post("/admin/hr/plantilla/{$item->id}/appointments", $this->appointment($this->employee()))
            ->assertSessionHasErrors('employee_id');
    }

    public function test_a_promotion_ends_the_previous_item_the_day_before(): void
    {
        $hr = $this->hr();
        $employee = $this->employee();
        $lower = $this->item(['item_number' => 'CSCB-AO2-1', 'position_title' => 'Administrative Officer II', 'salary_grade' => 11]);
        $higher = $this->item(['item_number' => 'CSCB-AO4-1', 'salary_grade' => 15]);

        $this->actingAs($hr)->post("/admin/hr/plantilla/{$lower->id}/appointments", $this->appointment($employee, ['date_from' => '2020-01-02']));
        $this->actingAs($hr)->post("/admin/hr/plantilla/{$higher->id}/appointments", $this->appointment($employee, ['nature' => 'promotion', 'date_from' => '2026-03-01']))
            ->assertSessionHasNoErrors();

        $previous = PlantillaAppointment::query()->where('plantilla_item_id', $lower->id)->sole();
        $this->assertSame('2026-02-28', $previous->date_to->toDateString());
        $this->assertSame('Promotion to CSCB-AO4-1', $previous->end_reason);
        $this->assertNull($lower->fresh()->incumbency);

        // Removing the promotion by mistake reopens the previous period.
        $promotion = PlantillaAppointment::query()->where('plantilla_item_id', $higher->id)->sole();
        $this->actingAs($hr)->delete("/admin/hr/plantilla/{$higher->id}/appointments/{$promotion->id}")->assertSessionHasNoErrors();
        $this->assertNull($previous->fresh()->date_to);
        $this->assertSame('Administrative Officer II', $employee->user->fresh()->position);
    }

    public function test_a_step_increment_opens_the_next_period_and_vacating_frees_the_item(): void
    {
        $hr = $this->hr();
        $this->schedule();
        $item = $this->item();
        $employee = $this->employee();
        $this->actingAs($hr)->post("/admin/hr/plantilla/{$item->id}/appointments", $this->appointment($employee, ['date_from' => '2023-01-02']));
        $first = PlantillaAppointment::query()->sole();

        $this->actingAs($hr)->post("/admin/hr/plantilla/{$item->id}/appointments/{$first->id}/step-increment", ['step' => 1, 'date_from' => '2026-01-02'])
            ->assertSessionHasErrors('step');
        $this->actingAs($hr)->post("/admin/hr/plantilla/{$item->id}/appointments/{$first->id}/step-increment", ['step' => 2, 'date_from' => '2026-01-02'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-01-01', $first->fresh()->date_to->toDateString());
        $second = PlantillaAppointment::query()->whereNull('date_to')->sole();
        $this->assertSame(2, $second->step);
        $this->assertSame(AppointmentNature::StepIncrement, $second->nature);

        $this->actingAs($hr)->get("/admin/hr/plantilla/{$item->id}")->assertInertia(fn (Assert $page) => $page
            ->where('item.monthly_salary', 30500)
            ->has('history', 2)
            ->where('history.0.current', true));

        $this->actingAs($hr)->patch("/admin/hr/plantilla/{$item->id}/appointments/{$second->id}/vacate", ['date_to' => '2026-06-30', 'end_reason' => 'Retirement'])
            ->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->incumbency);
        $this->assertDatabaseHas('audit_logs', ['event' => 'plantilla_appointment.vacated']);
    }

    public function test_only_a_vacant_item_can_be_abolished_and_it_can_be_restored(): void
    {
        $hr = $this->hr();
        $item = $this->item();
        $this->actingAs($hr)->post("/admin/hr/plantilla/{$item->id}/appointments", $this->appointment($this->employee()));

        $this->actingAs($hr)->patch("/admin/hr/plantilla/{$item->id}/abolish", ['abolished_on' => '2026-06-30'])->assertSessionHasErrors('abolished_on');

        $vacant = $this->item(['item_number' => 'CSCB-AO4-2']);
        $this->actingAs($hr)->patch("/admin/hr/plantilla/{$vacant->id}/abolish", ['abolished_on' => '2026-06-30', 'abolition_authority' => 'DBM letter'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($vacant->fresh()->isAbolished());

        $this->actingAs($hr)->post("/admin/hr/plantilla/{$vacant->id}/appointments", $this->appointment($this->employee()))->assertSessionHasErrors('employee_id');

        $this->actingAs($hr)->patch("/admin/hr/plantilla/{$vacant->id}/restore")->assertSessionHasNoErrors();
        $this->assertFalse($vacant->fresh()->isAbolished());
    }

    public function test_hr_never_appoints_themself(): void
    {
        $hr = $this->hr();
        $own = Employee::factory()->for($hr)->create();

        $this->actingAs($hr)->post("/admin/hr/plantilla/{$this->item()->id}/appointments", $this->appointment($own))->assertForbidden();
    }

    public function test_only_plantilla_statuses_are_accepted(): void
    {
        $this->actingAs($this->hr())->post("/admin/hr/plantilla/{$this->item()->id}/appointments", $this->appointment($this->employee(), ['status' => 'job_order']))
            ->assertSessionHasErrors('status');
    }

    public function test_salary_schedules_are_entered_by_hr_and_the_one_in_force_prices_the_plantilla(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post('/admin/hr/salary-schedules', $this->scheduleData())->assertSessionHasNoErrors();
        $old = SalarySchedule::query()->sole();
        $this->actingAs($hr)->put("/admin/hr/salary-schedules/{$old->id}", [
            ...$this->scheduleData(),
            'rates' => [15 => [1 => '30000', 2 => '30500', 3 => null], 99 => [1 => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $old->rates()->count());

        // A later schedule takes over from its effective date, starting as a copy.
        $this->actingAs($hr)->post('/admin/hr/salary-schedules', [...$this->scheduleData(), 'name' => 'Fourth tranche', 'effective_date' => '2027-01-01', 'copy_from' => $old->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, SalarySchedule::query()->where('name', 'Fourth tranche')->sole()->rates()->count());

        $this->actingAs($hr)->get('/admin/hr/salary-schedules')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/SalarySchedules/Index')
            ->has('schedules', 2)
            ->where('schedules.1.in_force', true)
            ->where('selectedId', $old->id)
            ->where('grid.15.2', 30500)
            ->where('grid.15.3', null));

        $this->assertDatabaseHas('audit_logs', ['event' => 'salary_schedule.saved']);
    }

    public function test_the_employee_page_shows_the_items_held_to_plantilla_viewers_and_the_employee(): void
    {
        $item = $this->item();
        $employee = $this->employee();
        $this->actingAs($this->hr())->post("/admin/hr/plantilla/{$item->id}/appointments", $this->appointment($employee));

        $this->actingAs($this->userWith(['employees.view', 'plantilla.view']))->get("/admin/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plantilla.0.item_number', 'CSCB-AO4-1'));

        $this->actingAs($this->userWith(['employees.view']))->get("/admin/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plantilla', null));

        $this->actingAs($employee->user)->get('/my/employee-record')
            ->assertInertia(fn (Assert $page) => $page->where('plantilla.0.current', true));
    }

    private function schedule(): SalarySchedule
    {
        $schedule = SalarySchedule::query()->create(['name' => 'Third tranche, 2026', 'reference' => 'NBC No. 601', 'effective_date' => '2026-01-01']);
        $schedule->rates()->createMany([
            ['salary_grade' => 15, 'step' => 1, 'monthly_salary' => 30000],
            ['salary_grade' => 15, 'step' => 2, 'monthly_salary' => 30500],
        ]);

        return $schedule;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(array $overrides = []): PlantillaItem
    {
        return PlantillaItem::query()->create([...$this->itemData(), ...$overrides]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemData(): array
    {
        return ['item_number' => 'CSCB-AO4-1', 'position_title' => 'Administrative Officer IV', 'salary_grade' => 15, 'level' => 'second', 'organizational_unit_id' => $this->hrd->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleData(): array
    {
        return ['name' => 'Third tranche, 2026', 'reference' => 'NBC No. 601', 'effective_date' => '2026-01-01'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function appointment(Employee $employee, array $overrides = []): array
    {
        return ['employee_id' => $employee->id, 'step' => 1, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2026-02-01', ...$overrides];
    }

    private function employee(): Employee
    {
        return Employee::factory()->create();
    }

    private function hr(): User
    {
        return $this->userWith(['employees.view', 'plantilla.manage']);
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
