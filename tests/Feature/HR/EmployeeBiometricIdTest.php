<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The biometric ID is a field on the employee's record, like the employee
 * number: the number their fingerprint or face is enrolled under on the
 * biometric device, which is how their punches find them.
 */
class EmployeeBiometricIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_sets_the_biometric_id_when_setting_up_a_record(): void
    {
        $person = User::factory()->create();

        $this->actingAs($this->userWith('employees.create'))->post('/admin/hr/employees', [
            'user_id' => $person->id,
            'employee_number' => 'CSC-00123',
            'biometric_id' => '906',
            'employment_status' => 'permanent',
        ])->assertRedirect();

        $this->assertSame('906', Employee::query()->where('user_id', $person->id)->firstOrFail()->biometric_id);
    }

    public function test_hr_can_set_change_and_clear_it_on_an_existing_record(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->userWith('employees.update');

        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}", ['biometric_id' => '906'])->assertRedirect();
        $this->assertSame('906', $employee->fresh()->biometric_id);

        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}", ['biometric_id' => '1744'])->assertRedirect();
        $this->assertSame('1744', $employee->fresh()->biometric_id);

        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}", ['biometric_id' => ''])->assertRedirect();
        $this->assertNull($employee->fresh()->biometric_id);
    }

    public function test_the_change_is_audited_with_the_old_and_new_value(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '906']);

        $this->actingAs($this->userWith('employees.update'))->put("/admin/hr/employees/{$employee->id}", ['biometric_id' => '1744'])->assertRedirect();

        $audit = AuditLog::query()->where('event', 'employee.updated')->latest('id')->firstOrFail();
        $this->assertSame(['from' => '906', 'to' => '1744'], $audit->metadata['changes']['biometric_id'] ?? null);
    }

    public function test_two_employees_cannot_share_a_biometric_id(): void
    {
        Employee::factory()->create(['biometric_id' => '906']);
        $other = Employee::factory()->create();
        $hr = $this->userWith('employees.update');

        $this->actingAs($hr)->put("/admin/hr/employees/{$other->id}", ['biometric_id' => '906'])->assertSessionHasErrors('biometric_id');
        $this->assertNull($other->fresh()->biometric_id);

        $this->actingAs($this->userWith('employees.create'))->post('/admin/hr/employees', [
            'user_id' => User::factory()->create()->id,
            'biometric_id' => '906',
            'employment_status' => 'permanent',
        ])->assertSessionHasErrors('biometric_id');
    }

    public function test_keeping_your_own_id_when_saving_other_changes_is_not_a_duplicate(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '906']);

        $this->actingAs($this->userWith('employees.update'))
            ->put("/admin/hr/employees/{$employee->id}", ['biometric_id' => '906', 'remarks' => 'Updated'])
            ->assertSessionHasNoErrors();
    }

    public function test_only_the_digits_a_device_uses_are_accepted(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->userWith('employees.update');

        foreach (['abc', '12 34', '1234567', '-5', '9.5'] as $bad) {
            $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}", ['biometric_id' => $bad])->assertSessionHasErrors('biometric_id');
        }
        $this->assertNull($employee->fresh()->biometric_id);
    }

    public function test_the_profile_shows_it_to_those_who_can_see_the_record(): void
    {
        $employee = Employee::factory()->create(['biometric_id' => '906']);

        $this->actingAs($this->userWith('employees.view'))->get("/admin/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('employee.biometric_id', '906'));
    }

    public function test_the_employee_list_carries_each_ones_biometric_id_or_null(): void
    {
        Employee::factory()->create(['biometric_id' => '906']);
        Employee::factory()->create(['biometric_id' => null]);

        $this->actingAs($this->userWith('employees.view'))->get('/admin/hr/employees')
            ->assertInertia(fn (Assert $page) => $page->has('employees', 2, fn (Assert $row) => $row->has('biometric_id')->etc()));
    }

    /**
     * @param  string|array<int, string>  $permissions
     */
    private function userWith(string|array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ((array) $permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }
        $user->roles()->attach($role);

        return $user;
    }
}
