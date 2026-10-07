<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\NonPlantillaContract;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Non-plantilla staff (casual, contractual, Job Order, Contract of
 * Service): off the plantilla, on their own page with their contracts.
 */
class NonPlantillaTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 09:00:00');
        $this->office = OrganizationalUnit::factory()->create(['name' => 'Office of the Regional Director', 'short_name' => 'ORD']);
    }

    public function test_the_page_lists_non_plantilla_staff_with_their_contract_state(): void
    {
        $cos = $this->staff('Computer Technician', EmploymentStatus::ContractOfService);
        $jo = $this->staff('Driver', EmploymentStatus::JobOrder);
        $this->staff('Administrative Officer IV', EmploymentStatus::Permanent);
        $this->contract($cos, '2026-01-01', '2026-06-30');
        $this->contract($jo, '2026-01-01', '2026-03-31');

        $this->actingAs($this->userWith(['plantilla.view']))->get('/admin/hr/non-plantilla')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/NonPlantilla/Index')
            ->where('canManage', false)
            ->where('contractForm', null)
            ->has('rows', 2)
            ->where('rows', function ($rows) use ($cos, $jo) {
                $rows = collect($rows)->keyBy('employee_id');

                return $rows[$cos->id]['state'] === 'expiring' && $rows[$cos->id]['days_left'] === 15
                    && $rows[$cos->id]['designation'] === 'Computer Technician'
                    && $rows[$jo->id]['state'] === 'ended';
            })
            ->has('statusOptions', 4));

        $this->actingAs(User::factory()->create())->get('/admin/hr/non-plantilla')->assertForbidden();
    }

    public function test_hr_records_renews_edits_and_removes_contracts(): void
    {
        $hr = $this->userWith(['plantilla.manage']);
        $worker = $this->staff('Computer Technician', null);

        $this->actingAs($hr)->post('/admin/hr/non-plantilla/contracts', $this->data($worker, ['date_from' => '2026-01-01', 'date_to' => '2026-06-30']))
            ->assertSessionHasNoErrors();
        // The employee record follows the contract.
        $this->assertSame(EmploymentStatus::ContractOfService, $worker->fresh()->employment_status);

        // Overlapping periods are refused; the next one is fine.
        $this->actingAs($hr)->post('/admin/hr/non-plantilla/contracts', $this->data($worker, ['date_from' => '2026-06-01', 'date_to' => '2026-12-31']))
            ->assertSessionHasErrors('date_from');
        $this->actingAs($hr)->post('/admin/hr/non-plantilla/contracts', $this->data($worker, ['date_from' => '2026-07-01', 'date_to' => '2026-12-31', 'employment_status' => 'job_order']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $worker->nonPlantillaContracts()->count());

        $contract = $worker->nonPlantillaContracts()->orderBy('date_from')->first();
        $this->actingAs($hr)->put("/admin/hr/non-plantilla/contracts/{$contract->id}", collect($this->data($worker, ['date_from' => '2026-01-01', 'date_to' => '2026-06-30', 'rate' => 650]))->except('employee_id')->all())
            ->assertSessionHasNoErrors();
        $this->assertSame('650.00', $contract->fresh()->rate);

        $this->actingAs($hr)->delete("/admin/hr/non-plantilla/contracts/{$contract->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($contract);
        // Only the July contract is left, so they're Job Order now.
        $this->assertSame(EmploymentStatus::JobOrder, $worker->fresh()->employment_status);

        foreach (['recorded', 'updated', 'removed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['event' => "non_plantilla_contract.{$action}"]);
        }
    }

    public function test_guards(): void
    {
        $hr = $this->userWith(['plantilla.manage']);
        $worker = $this->staff('Driver', EmploymentStatus::JobOrder);

        // Not plantilla statuses, and not someone holding an item.
        $this->actingAs($hr)->post('/admin/hr/non-plantilla/contracts', $this->data($worker, ['employment_status' => 'permanent']))->assertSessionHasErrors('employment_status');
        $holder = $this->staff('Administrative Officer IV', EmploymentStatus::Permanent);
        $this->appoint($holder, 'Administrative Officer IV', 'AO4-1');
        $this->actingAs($hr)->post('/admin/hr/non-plantilla/contracts', $this->data($holder))->assertSessionHasErrors('employee_id');

        // A rate needs its basis.
        $this->actingAs($hr)->post('/admin/hr/non-plantilla/contracts', $this->data($worker, ['rate' => 500, 'rate_basis' => null]))->assertSessionHasErrors('rate_basis');

        // Never your own.
        $hrEmployee = Employee::factory()->create(['user_id' => $hr->id, 'employment_status' => EmploymentStatus::ContractOfService]);
        $this->actingAs($hr)->post('/admin/hr/non-plantilla/contracts', $this->data($hrEmployee))->assertForbidden();

        // plantilla.view can't write.
        $this->actingAs($this->userWith(['plantilla.view']))->post('/admin/hr/non-plantilla/contracts', $this->data($worker))->assertForbidden();
    }

    public function test_items_made_for_non_plantilla_staff_come_off_the_plantilla(): void
    {
        $cos = $this->staff('Computer Technician', EmploymentStatus::ContractOfService);
        $position = Position::query()->create(['title' => 'Computer Technician', 'salary_grade' => 11]);
        $linked = $this->appoint($cos, 'Computer Technician', null, $position);
        $permanent = $this->staff('Administrative Officer IV', EmploymentStatus::Permanent);
        $kept = $this->appoint($permanent, 'Administrative Officer IV', null);
        $numbered = $this->appoint($this->staff('Driver', EmploymentStatus::JobOrder), 'Driver', 'DRV-1');

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_11_08_090001_remove_non_plantilla_staff_from_plantilla.php'])->assertSuccessful();

        $this->assertModelMissing($linked);
        $this->assertSame(0, PlantillaAppointment::withTrashed()->where('plantilla_item_id', $linked->id)->count());
        $this->assertModelMissing($position);
        $this->assertModelExists($kept);
        // An item with a real item number is HR's to sort out.
        $this->assertModelExists($numbered);
        $this->assertDatabaseHas('audit_logs', ['event' => 'plantilla_item.non_plantilla_removed']);

        // And `imis:link-plantilla` no longer makes them.
        $this->artisan('imis:link-plantilla --apply')->assertSuccessful();
        $this->assertSame(0, $cos->plantillaAppointments()->count());
    }

    private function staff(string $position, ?EmploymentStatus $status): Employee
    {
        $user = User::factory()->create(['position' => $position, 'organizational_unit_id' => $this->office->id]);

        return Employee::factory()->create(['user_id' => $user->id, 'employment_status' => $status])->load('user');
    }

    private function contract(Employee $employee, string $from, ?string $to): NonPlantillaContract
    {
        $contract = $employee->nonPlantillaContracts()->make([
            'employment_status' => $employee->employment_status, 'designation' => $employee->user->position, 'date_from' => $from, 'date_to' => $to,
        ]);
        $contract->save();

        return $contract;
    }

    private function appoint(Employee $employee, string $title, ?string $itemNumber, ?Position $position = null): PlantillaItem
    {
        $item = PlantillaItem::query()->create(['item_number' => $itemNumber, 'position_title' => $title, 'organizational_unit_id' => $this->office->id]);
        $item->forceFill(['position_id' => $position?->id])->save();
        $appointment = new PlantillaAppointment(['status' => null]);
        $appointment->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

        return $item;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(Employee $employee, array $overrides = []): array
    {
        return [
            'employee_id' => $employee->id,
            'employment_status' => 'contract_of_service',
            'designation' => 'Computer Technician',
            'organizational_unit_id' => $this->office->id,
            'date_from' => '2026-01-01',
            'date_to' => '2026-06-30',
            'rate' => 700,
            'rate_basis' => 'daily',
            'funding_source' => 'MOOE',
            'reference' => 'COS-2026-001',
            ...$overrides,
        ];
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
