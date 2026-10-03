<?php

namespace Tests\Feature\HR;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-1 employee records: who can see and change them, the Data Privacy
 * Act access trail, and the self-service page.
 */
class EmployeeRecordTest extends TestCase
{
    use RefreshDatabase;

    // ── Who can see what ─────────────────────────────────────────────

    public function test_guests_and_users_without_permission_are_kept_out(): void
    {
        $employee = Employee::factory()->create();

        $this->get('/admin/hr/employees')->assertRedirect('/login');

        $plain = User::factory()->create();
        $this->actingAs($plain)->get('/admin/hr/employees')->assertForbidden();
        $this->actingAs($plain)->get("/admin/hr/employees/{$employee->id}")->assertForbidden();
    }

    public function test_hr_sees_every_record(): void
    {
        Employee::factory()->count(3)->create();

        $this->actingAs($this->userWith('employees.view'))
            ->get('/admin/hr/employees')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Employees/Index')->has('employees', 3));
    }

    public function test_a_supervisor_sees_only_their_own_office(): void
    {
        [$office, $elsewhere] = OrganizationalUnit::factory()->count(2)->create();
        $mine = Employee::factory()->for(User::factory()->state(['organizational_unit_id' => $office->id]))->create();
        $theirs = Employee::factory()->for(User::factory()->state(['organizational_unit_id' => $elsewhere->id]))->create();

        $supervisor = $this->userWith('employees.view_unit', ['organizational_unit_id' => $office->id]);

        $this->actingAs($supervisor)
            ->get('/admin/hr/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees', 1)
                ->where('employees.0.id', $mine->id));

        $this->actingAs($supervisor)->get("/admin/hr/employees/{$mine->id}")->assertOk();
        $this->actingAs($supervisor)->get("/admin/hr/employees/{$theirs->id}")->assertForbidden();
    }

    public function test_a_supervisor_without_an_office_sees_no_one_else(): void
    {
        Employee::factory()->create();

        $this->actingAs($this->userWith('employees.view_unit'))
            ->get('/admin/hr/employees')
            ->assertInertia(fn (Assert $page) => $page->has('employees', 0));
    }

    // ── Data Privacy Act access trail ────────────────────────────────

    public function test_opening_someone_elses_record_is_logged(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->userWith('employees.view');

        $this->actingAs($hr)->get("/admin/hr/employees/{$employee->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'employee.viewed',
            'actor_id' => $hr->id,
            'target_id' => $employee->id,
            'target_label' => "Employee record of {$employee->user->name}",
        ]);
    }

    public function test_reading_your_own_record_is_not_logged(): void
    {
        $hr = $this->userWith('employees.view');
        $own = Employee::factory()->for($hr)->create();

        $this->actingAs($hr)->get("/admin/hr/employees/{$own->id}")->assertOk();
        $this->actingAs($hr)->get('/my/employee-record')->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'employee.viewed']);
    }

    // ── Creating and editing ─────────────────────────────────────────

    public function test_hr_sets_up_a_record_and_it_is_audited(): void
    {
        $hr = $this->userWith('employees.create');
        $person = User::factory()->create();

        $this->actingAs($hr)->post('/admin/hr/employees', [
            'user_id' => $person->id,
            'employee_number' => 'CSC-00123',
            'employment_status' => 'permanent',
            'original_appointment_date' => '2015-06-01',
        ])->assertRedirect();

        $employee = Employee::query()->where('user_id', $person->id)->firstOrFail();
        $this->assertSame('CSC-00123', $employee->employee_number);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.created', 'target_id' => $employee->id]);
    }

    public function test_one_record_per_person_and_unique_employee_numbers(): void
    {
        $hr = $this->userWith('employees.create');
        $existing = Employee::factory()->create(['employee_number' => 'CSC-00001']);

        $this->actingAs($hr)->post('/admin/hr/employees', [
            'user_id' => $existing->user_id,
            'employment_status' => 'permanent',
        ])->assertSessionHasErrors('user_id');

        $this->actingAs($hr)->post('/admin/hr/employees', [
            'user_id' => User::factory()->create()->id,
            'employee_number' => 'CSC-00001',
            'employment_status' => 'permanent',
        ])->assertSessionHasErrors('employee_number');
    }

    public function test_hr_cannot_set_up_or_edit_their_own_record(): void
    {
        $hr = $this->userWith(['employees.create', 'employees.update']);

        $this->actingAs($hr)->post('/admin/hr/employees', [
            'user_id' => $hr->id,
            'employment_status' => 'permanent',
        ])->assertSessionHasErrors('user_id');

        $own = Employee::factory()->for($hr)->create();
        $this->actingAs($hr)->put("/admin/hr/employees/{$own->id}", [
            'employment_status' => 'casual',
        ])->assertForbidden();
    }

    public function test_a_super_administrator_may_edit_their_own_record(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::factory()->create(['name' => RoleName::SuperAdmin->value]));
        $own = Employee::factory()->for($admin)->create();

        $this->actingAs($admin)->put("/admin/hr/employees/{$own->id}", [
            'employment_status' => 'casual',
        ])->assertRedirect();

        $this->assertSame('casual', $own->fresh()->employment_status->value);
    }

    public function test_editing_records_the_changes_and_never_moves_the_record(): void
    {
        $employee = Employee::factory()->create(['employment_status' => 'temporary']);
        $ownerId = $employee->user_id;
        $hr = $this->userWith('employees.update');

        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}", [
            'user_id' => User::factory()->create()->id, // ignored
            'employment_status' => 'permanent',
        ])->assertRedirect();

        $employee->refresh();
        $this->assertSame('permanent', $employee->employment_status->value);
        $this->assertSame($ownerId, $employee->user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.updated', 'target_id' => $employee->id]);
    }

    public function test_separation_needs_a_reason_and_a_valid_date(): void
    {
        $employee = Employee::factory()->create(['original_appointment_date' => '2015-06-01']);
        $hr = $this->userWith('employees.update');

        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}", [
            'employment_status' => 'permanent',
            'original_appointment_date' => '2015-06-01',
            'separation_date' => '2014-01-01',
        ])->assertSessionHasErrors(['separation_date', 'separation_reason']);

        // A reason without a date is dropped rather than stored.
        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}", [
            'employment_status' => 'permanent',
            'separation_reason' => 'Resignation',
        ])->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->separation_reason);
    }

    public function test_a_user_with_an_employee_record_cannot_be_hard_deleted(): void
    {
        $employee = Employee::factory()->create();

        $this->expectException(QueryException::class);
        $employee->user->delete();
    }

    // ── Setting up records in bulk, and for new accounts ─────────────

    public function test_hr_sets_up_records_for_many_accounts_at_once(): void
    {
        $hr = $this->userWith(['employees.view', 'employees.create']);
        [$ana, $ben] = User::factory()->count(2)->create();
        Employee::factory()->create(); // already has one
        User::factory()->create(['status' => 'inactive']); // not a candidate

        $this->actingAs($hr)->get('/admin/hr/employees')
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 2)); // Ana and Ben; never HR themself

        $this->actingAs($hr)->post('/admin/hr/employees/bulk', ['user_ids' => [$ana->id, $ben->id]])->assertSessionHasNoErrors();

        foreach ([$ana, $ben] as $user) {
            $employee = $user->fresh()->employee;
            $this->assertNotNull($employee);
            $this->assertNull($employee->employment_status);
            $this->assertDatabaseHas('audit_logs', ['event' => 'employee.created', 'target_id' => $employee->id]);
        }

        $this->actingAs($hr)->get("/admin/hr/employees/{$ana->employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('employee.employment_status_label', 'Not set'));
    }

    public function test_bulk_setup_only_accepts_current_candidates(): void
    {
        $hr = $this->userWith('employees.create');
        $hasOne = Employee::factory()->create()->user;
        $inactive = User::factory()->create(['status' => 'inactive']);

        foreach ([$hasOne, $inactive, $hr] as $user) {
            $this->actingAs($hr)->post('/admin/hr/employees/bulk', ['user_ids' => [$user->id]])->assertSessionHasErrors('user_ids.0');
        }

        $this->actingAs($hr)->post('/admin/hr/employees/bulk', ['user_ids' => []])->assertSessionHasErrors('user_ids');
        $this->actingAs($this->userWith('employees.view'))->post('/admin/hr/employees/bulk', ['user_ids' => [$inactive->id]])->assertForbidden();
    }

    public function test_a_new_account_gets_its_employee_record_automatically(): void
    {
        $admin = $this->userWith(['users.create']);

        $this->actingAs($admin)->post('/users', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@example.test',
        ])->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'maria.santos@example.test')->sole();
        $this->assertNotNull($user->employee);
        $this->assertNull($user->employee->employment_status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.created', 'target_id' => $user->employee->id]);

        // So they can open their PDS right away.
        $this->actingAs($user)->get('/my/pds')->assertInertia(fn (Assert $page) => $page->whereNot('pds', null));
    }

    // ── Self-service ─────────────────────────────────────────────────

    public function test_everyone_can_read_their_own_record(): void
    {
        $person = User::factory()->create();

        $this->actingAs($person)->get('/my/employee-record')
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyEmployeeRecord')->where('employee', null));

        $employee = Employee::factory()->for($person)->create();

        $this->actingAs($person)->get('/my/employee-record')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employee.id', $employee->id)
                ->where('employee.can.update', false));
    }

    /**
     * @param  string|array<int, string>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    private function userWith(string|array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $role = Role::factory()->create();

        foreach ((array) $permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
