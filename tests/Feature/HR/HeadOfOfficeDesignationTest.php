<?php

namespace Tests\Feature\HR;

use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupervisorHandover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A designation as head of office, in a concurrent capacity: a Field
 * Director who heads a second field office for want of a Director II item
 * there, and stays in their own. It opens that office's staff records to
 * them while it runs, can hand its staff over to them as immediate
 * supervisor and back, and shows on the Staffing page.
 */
class HeadOfOfficeDesignationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $leyte;

    private OrganizationalUnit $samar;

    private User $director;

    private Employee $directorRecord;

    private User $previousSupervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-04 09:00:00');

        $this->leyte = OrganizationalUnit::factory()->create(['name' => 'CSC Field Office - Leyte', 'short_name' => 'LFO']);
        $this->samar = OrganizationalUnit::factory()->create(['name' => 'CSC Field Office - Samar', 'short_name' => 'SFO']);

        // The Field Director: Supervisor role (employees.view_unit), in Leyte.
        $this->director = $this->userWith(['employees.view_unit'], ['organizational_unit_id' => $this->leyte->id, 'name' => 'Dir. Reyes']);
        $this->directorRecord = Employee::factory()->for($this->director)->create();
        $this->previousSupervisor = User::factory()->create(['organizational_unit_id' => $this->samar->id]);
    }

    public function test_heading_an_office_opens_its_staff_records_only_while_it_runs(): void
    {
        $samarStaff = $this->staffOf($this->samar);
        $leyteStaff = $this->staffOf($this->leyte);

        $this->actingAs($this->director)->get("/admin/hr/employees/{$samarStaff->id}")->assertForbidden();

        $this->designate(['end_date' => '2026-12-31']);

        $this->actingAs($this->director)->get("/admin/hr/employees/{$samarStaff->id}")->assertOk();
        $this->actingAs($this->director)->get("/admin/hr/employees/{$leyteStaff->id}")->assertOk();
        $this->assertEqualsCanonicalizing(
            [$this->directorRecord->id, $samarStaff->id, $leyteStaff->id],
            Employee::query()->visibleTo($this->director)->pluck('id')->all(),
        );

        $this->travelTo('2027-01-01 09:00:00');
        $this->flushSession(); // a fresh sign-in, months later
        $this->actingAs($this->director)->get("/admin/hr/employees/{$samarStaff->id}")->assertForbidden();
        $this->actingAs($this->director)->get("/admin/hr/employees/{$leyteStaff->id}")->assertOk();
    }

    public function test_a_plain_designation_or_one_without_the_supervisor_role_opens_nothing(): void
    {
        $samarStaff = $this->staffOf($this->samar);

        $this->designate(['as_head' => false]);
        $this->actingAs($this->director)->get("/admin/hr/employees/{$samarStaff->id}")->assertForbidden();

        $officer = $this->userWith([], ['organizational_unit_id' => $this->leyte->id]);
        $record = Employee::factory()->for($officer)->create();
        $this->actingAs($this->hr())->post("/admin/hr/employees/{$record->id}/assignments", $this->designation());
        $this->actingAs($officer)->get("/admin/hr/employees/{$samarStaff->id}")->assertForbidden();
    }

    public function test_only_a_designation_can_be_as_head_of_office(): void
    {
        $this->designate(['kind' => 'detail', 'from_unit_id' => $this->leyte->id]);

        $entry = EmployeeAssignment::query()->sole();
        $this->assertFalse($entry->as_head);
        $this->assertFalse($entry->hand_over_supervision);
        $this->assertSame(0, SupervisorHandover::query()->count());
    }

    public function test_the_office_staff_are_handed_over_when_it_takes_effect(): void
    {
        $staff = $this->staffOf($this->samar);
        $other = $this->staffOf($this->samar, supervisor: null);
        $separated = Employee::factory()->separated()->for(User::factory()->state(['organizational_unit_id' => $this->samar->id]))
            ->create(['immediate_supervisor_id' => $this->previousSupervisor->id]);
        $inactive = $this->staffOf($this->samar);
        $inactive->user->forceFill(['status' => UserStatus::Inactive])->save();
        $leyte = $this->staffOf($this->leyte);

        $this->designate()->assertSessionHas('status', 'Designation recorded. They are now the immediate supervisor of 2 staff of SFO.');

        $this->assertSame($this->director->id, $staff->fresh()->immediate_supervisor_id);
        $this->assertSame($this->director->id, $other->fresh()->immediate_supervisor_id);
        $this->assertSame($this->previousSupervisor->id, $separated->fresh()->immediate_supervisor_id);
        $this->assertSame($this->previousSupervisor->id, $inactive->fresh()->immediate_supervisor_id);
        $this->assertSame($this->previousSupervisor->id, $leyte->fresh()->immediate_supervisor_id);
        $this->assertNull($this->directorRecord->fresh()->immediate_supervisor_id);

        $this->assertSame($this->previousSupervisor->id, SupervisorHandover::query()->where('employee_id', $staff->id)->value('previous_supervisor_id'));
        $this->assertNull(SupervisorHandover::query()->where('employee_id', $other->id)->value('previous_supervisor_id'));
        $this->assertNotNull(EmployeeAssignment::query()->sole()->supervision_handed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee_assignment.recorded']);
    }

    public function test_no_handover_unless_asked(): void
    {
        $staff = $this->staffOf($this->samar);

        $this->designate(['hand_over_supervision' => false])->assertSessionHas('status', 'Designation recorded.');

        $this->assertSame($this->previousSupervisor->id, $staff->fresh()->immediate_supervisor_id);
    }

    public function test_a_future_designation_hands_over_on_its_date(): void
    {
        $staff = $this->staffOf($this->samar);

        $this->designate(['effective_date' => '2026-10-10']);
        $this->assertSame($this->previousSupervisor->id, $staff->fresh()->immediate_supervisor_id);

        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('Handed 0')->assertSuccessful();

        $this->travelTo('2026-10-10 00:10:00');
        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('Handed 1')->assertSuccessful();
        $this->assertSame($this->director->id, $staff->fresh()->immediate_supervisor_id);

        // Once.
        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('Handed 0');
    }

    public function test_ending_it_gives_back_the_previous_supervisors_but_keeps_later_changes(): void
    {
        $staff = $this->staffOf($this->samar);
        $changed = $this->staffOf($this->samar);
        $this->designate();

        // HR moved one of them to someone else in the meantime.
        $newSupervisor = User::factory()->create();
        $changed->forceFill(['immediate_supervisor_id' => $newSupervisor->id])->save();

        $this->travelTo('2027-03-01 09:00:00');
        $this->flushSession();
        $entry = EmployeeAssignment::query()->sole();
        $this->actingAs($this->hr())->put($this->url()."/{$entry->id}", $this->designation(['end_date' => '2027-02-28']))
            ->assertSessionHas('status', 'Designation saved. Previous immediate supervisors given back to 1 staff member of SFO. 1 staff member kept the supervisor HR set since.');

        $this->assertSame($this->previousSupervisor->id, $staff->fresh()->immediate_supervisor_id);
        $this->assertSame($newSupervisor->id, $changed->fresh()->immediate_supervisor_id);
        $this->assertNotNull($entry->fresh()->supervision_restored_at);
    }

    public function test_an_end_date_ahead_gives_back_the_day_after(): void
    {
        $staff = $this->staffOf($this->samar);
        $this->designate(['end_date' => '2026-12-31']);

        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('gave 0 back');
        $this->assertSame($this->director->id, $staff->fresh()->immediate_supervisor_id);

        $this->travelTo('2027-01-01 00:10:00');
        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('gave 1 back');
        $this->assertSame($this->previousSupervisor->id, $staff->fresh()->immediate_supervisor_id);
    }

    public function test_ending_it_without_give_back_leaves_the_director_as_supervisor(): void
    {
        $staff = $this->staffOf($this->samar);
        $this->designate(['restore_supervision' => false, 'end_date' => '2026-10-31']);

        $this->travelTo('2026-11-01 00:10:00');
        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('gave 0 back');
        $this->assertSame($this->director->id, $staff->fresh()->immediate_supervisor_id);
    }

    public function test_removing_it_gives_the_staff_back_at_once(): void
    {
        $staff = $this->staffOf($this->samar);
        $this->designate();

        $entry = EmployeeAssignment::query()->sole();
        $this->actingAs($this->hr())->delete($this->url()."/{$entry->id}")
            ->assertSessionHas('status', 'Designation removed. Previous immediate supervisors given back to 1 staff member of SFO.');

        $this->assertSame($this->previousSupervisor->id, $staff->fresh()->immediate_supervisor_id);
    }

    public function test_correcting_the_office_hands_over_the_right_one(): void
    {
        $samarStaff = $this->staffOf($this->samar);
        $biliran = OrganizationalUnit::factory()->create(['short_name' => 'BFO']);
        $biliranStaff = $this->staffOf($biliran);
        $this->designate();

        $entry = EmployeeAssignment::query()->sole();
        $this->actingAs($this->hr())->put($this->url()."/{$entry->id}", $this->designation(['to_unit_id' => $biliran->id]))->assertSessionHasNoErrors();

        $this->assertSame($this->previousSupervisor->id, $samarStaff->fresh()->immediate_supervisor_id);
        $this->assertSame($this->director->id, $biliranStaff->fresh()->immediate_supervisor_id);
        $this->assertSame([$biliranStaff->id], SupervisorHandover::query()->pluck('employee_id')->all());
    }

    public function test_the_concurrent_office_shows_wherever_their_office_does(): void
    {
        $this->designate(['end_date' => '2026-12-31']);
        $hr = $this->hr();

        $this->actingAs($hr)->get("/admin/hr/employees/{$this->directorRecord->id}")->assertInertia(fn (Assert $page) => $page
            ->where('employee.user.unit', 'LFO')
            ->where('employee.user.concurrent_units', [['office' => 'SFO', 'capacity' => 'OIC']]));
        $this->actingAs($hr)->get('/admin/hr/employees')->assertInertia(fn (Assert $page) => $page
            ->where('employees', fn ($employees) => collect($employees)->firstWhere('id', $this->directorRecord->id)['user']['concurrent_units'] === [['office' => 'SFO', 'capacity' => 'OIC']]));
        $this->actingAs($this->director)->get('/my/employee-record')->assertInertia(fn (Assert $page) => $page
            ->where('employee.user.concurrent_units', [['office' => 'SFO', 'capacity' => 'OIC']]));
        $this->actingAs($this->userWith(['users.view']))->get('/users')->assertInertia(fn (Assert $page) => $page
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $this->director->id)['concurrent_offices'] === [['office' => 'SFO', 'capacity' => 'OIC']]));

        // Not once it has ended.
        $this->travelTo('2027-01-01 09:00:00');
        $this->flushSession();
        $this->actingAs($hr)->get("/admin/hr/employees/{$this->directorRecord->id}")->assertInertia(fn (Assert $page) => $page
            ->where('employee.user.concurrent_units', []));
    }

    public function test_a_caretaker_heads_the_office_like_an_officer_in_charge(): void
    {
        $staff = $this->staffOf($this->samar);

        $this->designate(['head_capacity' => 'caretaker']);

        $entry = EmployeeAssignment::query()->sole();
        $this->assertSame('caretaker', $entry->head_capacity->value);
        $this->assertSame("Designation as Caretaker of {$this->director->name}", $entry->label);
        $this->assertSame($this->director->id, $staff->fresh()->immediate_supervisor_id);
        $this->actingAs($this->director)->get("/admin/hr/employees/{$staff->id}")->assertOk();

        $hr = $this->hr();
        $this->actingAs($hr)->get("/admin/hr/employees/{$this->directorRecord->id}")->assertInertia(fn (Assert $page) => $page
            ->where('employee.user.concurrent_units', [['office' => 'SFO', 'capacity' => 'Caretaker']])
            ->where('assignments.entries.0.head_capacity', 'caretaker')
            ->where('assignments.entries.0.head_capacity_label', 'Caretaker')
            ->has('assignmentForm.capacityOptions', 2));
        $this->actingAs($this->userWith(['plantilla.view']))->get('/admin/hr/staffing')->assertInertia(fn (Assert $page) => $page
            ->where('designations.0.capacity', 'Caretaker')
            ->where('offices', fn ($offices) => collect($offices)->firstWhere('short_name', 'SFO')['headed_by'] === ['Dir. Reyes (Caretaker)']));
    }

    public function test_the_capacity_is_officer_in_charge_unless_given_and_none_when_not_heading(): void
    {
        $this->designate(['head_capacity' => '']);
        $this->assertSame('oic', EmployeeAssignment::query()->sole()->head_capacity->value);

        $this->actingAs($this->hr())->post($this->url(), $this->designation(['as_head' => false, 'head_capacity' => 'caretaker']))->assertSessionHasNoErrors();
        $this->assertNull(EmployeeAssignment::query()->latest('id')->first()->head_capacity);

        $this->actingAs($this->hr())->post($this->url(), $this->designation(['head_capacity' => 'acting']))->assertSessionHasErrors('head_capacity');
    }

    public function test_the_staffing_page_lists_offices_headed_by_designation(): void
    {
        $this->staffOf($this->samar);
        $this->designate(['order_number' => 'SO-2026-101']);

        $viewer = $this->userWith(['plantilla.view']);
        $this->actingAs($viewer)->get('/admin/hr/staffing')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.headed_by_designation', 1)
            ->has('designations', 1)
            ->where('designations.0.office', 'SFO')
            ->where('designations.0.name', 'Dir. Reyes')
            ->where('designations.0.capacity', 'OIC')
            ->where('designations.0.home_office', 'LFO')
            ->where('designations.0.until', null)
            ->where('designations.0.order_number', 'SO-2026-101')
            ->where('offices', fn ($offices) => collect($offices)->firstWhere('short_name', 'SFO')['headed_by'] === ['Dir. Reyes (OIC)']
                && collect($offices)->firstWhere('short_name', 'LFO')['also_heads'] === ['SFO']));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function designate(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->hr())->post($this->url(), $this->designation($overrides))->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function designation(array $overrides = []): array
    {
        return [
            'kind' => 'designation',
            'as_head' => true,
            'hand_over_supervision' => true,
            'restore_supervision' => true,
            'to_unit_id' => $this->samar->id,
            'effective_date' => '2026-10-01',
            ...$overrides,
        ];
    }

    private function url(): string
    {
        return "/admin/hr/employees/{$this->directorRecord->id}/assignments";
    }

    private function staffOf(OrganizationalUnit $unit, ?bool $supervisor = true): Employee
    {
        return Employee::factory()->for(User::factory()->state(['organizational_unit_id' => $unit->id]))
            ->create(['immediate_supervisor_id' => $supervisor ? $this->previousSupervisor->id : null]);
    }

    private function hr(): User
    {
        return $this->hrUser ??= $this->userWith(['employees.view', 'employees.reassign']);
    }

    private ?User $hrUser = null;

    /**
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    private function userWith(array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
