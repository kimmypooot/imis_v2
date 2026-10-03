<?php

namespace Tests\Feature\HR;

use App\Enums\AssignmentKind;
use App\Enums\EmployeeDocumentType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-1b: reassignment history. Only a reassignment moves the account's
 * office (details and designations keep the mother unit), once, when it
 * takes effect.
 */
class ReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $hrd;

    private OrganizationalUnit $msd;

    private OrganizationalUnit $samar;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-06-15 09:00:00');

        $this->hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD']);
        $this->msd = OrganizationalUnit::factory()->create(['name' => 'Management Services Division', 'short_name' => 'MSD']);
        $this->samar = OrganizationalUnit::factory()->create(['name' => 'CSC Field Office - Samar', 'short_name' => 'SFO']);

        $this->employee = Employee::factory()->for(User::factory()->state(['organizational_unit_id' => $this->hrd->id]))->create();
    }

    public function test_recording_needs_the_permission_and_never_on_your_own_record(): void
    {
        $this->actingAs($this->userWith(['employees.view']))
            ->post($this->url(), $this->movement())->assertForbidden();

        $hr = $this->userWith(['employees.view', 'employees.reassign']);
        $own = Employee::factory()->for($hr)->create();
        $this->actingAs($hr)->post("/admin/hr/employees/{$own->id}/assignments", $this->movement())->assertForbidden();

        $this->actingAs($hr)->post($this->url(), $this->movement())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, EmployeeAssignment::query()->count());
    }

    public function test_a_reassignment_in_effect_moves_the_office_and_keeps_where_they_were(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post($this->url(), $this->movement(['order_number' => 'SO-2026-014', 'order_date' => '2026-06-10']))
            ->assertSessionHas('status', 'Reassignment recorded. Office changed from HRD to SFO.');

        $this->assertSame($this->samar->id, $this->employee->user->fresh()->organizational_unit_id);

        $entry = EmployeeAssignment::query()->sole();
        $this->assertSame($this->hrd->id, $entry->from_unit_id);
        $this->assertSame($hr->id, $entry->recorded_by);
        $this->assertNotNull($entry->applied_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee_assignment.recorded', 'actor_id' => $hr->id, 'target_id' => $entry->id]);
    }

    public function test_a_future_reassignment_moves_the_office_on_its_date(): void
    {
        $this->actingAs($this->hr())->post($this->url(), $this->movement(['effective_date' => '2026-07-01']));

        $this->assertSame($this->hrd->id, $this->employee->user->fresh()->organizational_unit_id);

        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('Applied 0')->assertSuccessful();

        $this->travelTo('2026-07-01 00:10:00');
        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('Applied 1')->assertSuccessful();
        $this->assertSame($this->samar->id, $this->employee->user->fresh()->organizational_unit_id);

        // Once applied, a later hand edit of the office is left alone.
        $this->employee->user->forceFill(['organizational_unit_id' => $this->msd->id])->save();
        $this->artisan('imis:apply-reassignments')->expectsOutputToContain('Applied 0');
        $this->assertSame($this->msd->id, $this->employee->user->fresh()->organizational_unit_id);
    }

    public function test_details_and_designations_keep_the_mother_unit_and_a_return_ends_the_detail(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post($this->url(), $this->movement(['kind' => 'detail', 'effective_date' => '2026-03-01', 'end_date' => '2026-12-31']));
        $this->actingAs($hr)->post($this->url(), $this->movement(['kind' => 'designation', 'to_unit_id' => $this->msd->id, 'effective_date' => '2026-04-01']));
        $this->assertSame($this->hrd->id, $this->employee->user->fresh()->organizational_unit_id);

        $this->actingAs($this->userWith(['employees.view']))->get("/admin/hr/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignments.current.office', 'HRD')
                ->where('assignments.current.details.0.to_unit', 'SFO')
                ->where('assignments.current.designations.0.to_unit', 'MSD')
                ->where('assignmentForm', null));

        $this->actingAs($hr)->post($this->url(), $this->movement(['kind' => 'return', 'to_unit_id' => $this->hrd->id, 'effective_date' => '2026-06-01']));

        $detail = EmployeeAssignment::query()->where('kind', AssignmentKind::Detail)->sole();
        $this->assertSame('2026-05-31', $detail->end_date->toDateString());
        $this->assertSame($this->hrd->id, $this->employee->user->fresh()->organizational_unit_id);
    }

    public function test_removing_the_reassignment_that_moved_someone_puts_them_back(): void
    {
        $hr = $this->hr();
        $this->actingAs($hr)->post($this->url(), $this->movement());
        $entry = EmployeeAssignment::query()->sole();

        $this->actingAs($hr)->delete("{$this->url()}/{$entry->id}")
            ->assertSessionHas('status', 'Reassignment removed. Office changed from SFO to HRD.');

        $this->assertSoftDeleted($entry);
        $this->assertSame($this->hrd->id, $this->employee->user->fresh()->organizational_unit_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee_assignment.deleted', 'target_id' => $entry->id]);
    }

    public function test_correcting_the_destination_moves_the_office_again(): void
    {
        $hr = $this->hr();
        $this->actingAs($hr)->post($this->url(), $this->movement());
        $entry = EmployeeAssignment::query()->sole();

        $this->actingAs($hr)->post("{$this->url()}/{$entry->id}", [...$this->movement(['to_unit_id' => $this->msd->id, 'from_unit_id' => $this->hrd->id]), '_method' => 'put'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->msd->id, $this->employee->user->fresh()->organizational_unit_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee_assignment.updated', 'target_id' => $entry->id]);
    }

    public function test_a_move_must_go_somewhere_else_and_reassignments_have_no_end_date(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post($this->url(), $this->movement(['to_unit_id' => $this->hrd->id]))->assertSessionHasErrors('to_unit_id');

        // A designation to your own office is fine.
        $this->actingAs($hr)->post($this->url(), $this->movement(['kind' => 'designation', 'to_unit_id' => $this->hrd->id]))->assertSessionHasNoErrors();

        $this->actingAs($hr)->post($this->url(), $this->movement(['end_date' => '2026-12-31']))->assertSessionHasNoErrors();
        $this->assertNull(EmployeeAssignment::query()->where('kind', AssignmentKind::Reassignment)->sole()->end_date);
    }

    public function test_an_entry_only_resolves_under_its_own_employee(): void
    {
        $hr = $this->hr();
        $other = Employee::factory()->create();
        $this->actingAs($hr)->post("/admin/hr/employees/{$other->id}/assignments", $this->movement());
        $entry = EmployeeAssignment::query()->sole();

        $this->actingAs($hr)->delete("{$this->url()}/{$entry->id}")->assertNotFound();
    }

    public function test_the_scanned_order_is_filed_in_the_201_file(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post($this->url(), $this->movement([
            'order_number' => 'SO-14',
            'scan' => UploadedFile::fake()->create('order.pdf', 120, 'application/pdf'),
        ]))->assertSessionHasNoErrors();

        $document = EmployeeDocument::query()->sole();
        $this->assertSame(EmployeeDocumentType::OfficeOrder, $document->document_type);
        $this->assertSame('Reassignment order No. SO-14', $document->title);
        $this->assertSame($document->id, EmployeeAssignment::query()->sole()->document_id);
        Storage::disk('local')->assertExists($document->file_path);

        // HR with 201-file access gets the link; a supervisor sees the entry but not the scan.
        $this->actingAs($this->userWith(['employees.view', 'employee_documents.view']))->get("/admin/hr/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('assignments.entries.0.scan.id', $document->id));

        $supervisor = $this->userWith(['employees.view_unit']);
        $supervisor->forceFill(['organizational_unit_id' => $this->samar->id])->save();
        $this->actingAs($supervisor)->get("/admin/hr/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('assignments.entries.0.scan', null)->where('assignments.entries.0.has_scan', true));
    }

    public function test_the_employee_sees_their_own_history(): void
    {
        $this->actingAs($this->hr())->post($this->url(), $this->movement());

        $this->actingAs($this->employee->user)->get('/my/employee-record')
            ->assertInertia(fn (Assert $page) => $page
                ->has('assignments.entries', 1)
                ->where('assignments.entries.0.kind', 'reassignment')
                ->where('assignments.entries.0.current', true)
                ->where('assignments.current.office', 'SFO'));
    }

    public function test_the_report_shows_stations_on_a_date_and_movements(): void
    {
        $hr = $this->hr();
        $this->actingAs($hr)->post($this->url(), $this->movement(['effective_date' => '2026-02-01']));
        $this->actingAs($hr)->post($this->url(), $this->movement(['kind' => 'detail', 'to_unit_id' => $this->msd->id, 'effective_date' => '2026-05-01', 'end_date' => '2026-05-31']));

        $viewer = $this->userWith(['employees.view']);

        // Before the reassignment: still in HRD.
        $this->actingAs($viewer)->get('/admin/hr/reassignments?as_of=2026-01-15&from=2026-01-01&to=2026-12-31')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/Reassignments/Index')
                ->where('stations.0.office', 'HRD')
                ->where('stations.0.detailed_to', null)
                ->has('movements', 2));

        // During the detail: office SFO, detailed to MSD.
        $this->actingAs($viewer)->get('/admin/hr/reassignments?as_of=2026-05-10&kind=detail')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stations.0.office', 'SFO')
                ->where('stations.0.detailed_to', 'MSD')
                ->has('movements', 1));

        // A supervisor elsewhere sees nobody.
        $supervisor = $this->userWith(['employees.view_unit']);
        $supervisor->forceFill(['organizational_unit_id' => $this->msd->id])->save();
        $this->actingAs($supervisor)->get('/admin/hr/reassignments')
            ->assertInertia(fn (Assert $page) => $page->has('stations', 0)->has('movements', 0));
    }

    private function url(): string
    {
        return "/admin/hr/employees/{$this->employee->id}/assignments";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function movement(array $overrides = []): array
    {
        return [
            'kind' => 'reassignment',
            'to_unit_id' => $this->samar->id,
            'effective_date' => '2026-06-15',
            ...$overrides,
        ];
    }

    private function hr(): User
    {
        return $this->userWith(['employees.view', 'employees.reassign', 'employee_documents.manage']);
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
