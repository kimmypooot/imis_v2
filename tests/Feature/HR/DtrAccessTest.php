<?php

namespace Tests\Feature\HR;

use App\Enums\AuditEvent;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Who may open and correct an employee's DTR.
 */
class DtrAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_everyone_may_view_their_own_dtr(): void
    {
        $employee = Employee::factory()->create();

        $this->assertTrue(Gate::forUser($employee->user)->allows('viewDtr', $employee));
    }

    public function test_dtr_view_and_dtr_manage_may_view_anyones(): void
    {
        $employee = Employee::factory()->create();

        $this->assertTrue(Gate::forUser($this->userWith(['dtr.view']))->allows('viewDtr', $employee));
        $this->assertTrue(Gate::forUser($this->userWith(['dtr.manage']))->allows('viewDtr', $employee));
        $this->assertFalse(Gate::forUser($this->userWith([]))->allows('viewDtr', $employee));
    }

    public function test_only_dtr_manage_may_adjust_and_never_on_their_own_record(): void
    {
        $employee = Employee::factory()->create();
        $hrd = $this->userWith(['dtr.manage']);
        $own = Employee::factory()->create(['user_id' => $hrd->id]);

        $this->assertTrue(Gate::forUser($hrd)->allows('manageDtr', $employee));
        $this->assertFalse(Gate::forUser($hrd)->allows('manageDtr', $own));
        $this->assertFalse(Gate::forUser($this->userWith(['dtr.view']))->allows('manageDtr', $employee));
        $this->assertFalse(Gate::forUser($employee->user)->allows('manageDtr', $employee));
    }

    public function test_opening_or_printing_a_dtr_counts_as_personal_data_access(): void
    {
        $this->assertContains(AuditEvent::DtrViewed, AuditEvent::personalDataAccess());
        $this->assertContains(AuditEvent::DtrPrinted, AuditEvent::personalDataAccess());
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
