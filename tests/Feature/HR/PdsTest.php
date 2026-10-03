<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Pds\PdsChild;
use App\Models\Pds\PdsReference;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-2a: the Personal Data Sheet (CS Form No. 212, Revised 2026) —
 * self-service editing, encryption at rest, conditional fields, list
 * syncing, HR access, and an audit trail that never holds values.
 */
class PdsTest extends TestCase
{
    use RefreshDatabase;

    // ── Self-service ─────────────────────────────────────────────────

    public function test_the_pds_needs_an_employee_record_first(): void
    {
        $person = User::factory()->create();

        $this->actingAs($person)->get('/my/pds')
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyPds')->where('pds', null)->where('schema', null));

        $this->actingAs($person)->put('/my/pds/personal', [])->assertNotFound();
    }

    public function test_the_editor_gets_the_schema_and_the_identity_from_the_account(): void
    {
        $employee = $this->employee(['first_name' => 'Juan', 'last_name' => 'Cruz', 'sex' => 'male'], ['employee_number' => 'CSC-001']);

        $this->actingAs($employee->user)->get('/my/pds')
            ->assertInertia(fn (Assert $page) => $page
                ->where('pds.identity.surname', 'Cruz')
                ->where('pds.identity.sex_at_birth', 'male')
                ->where('pds.identity.agency_employee_no', 'CSC-001')
                ->has('schema.tabs', 10)
                ->where('schema.sections.personal.kind', 'profile')
                ->where('schema.sections.references.max_rows', 3)
                // Server-only details never reach the browser.
                ->missing('schema.sections.work_experience.fields.5.pattern'));
    }

    public function test_saving_personal_information_encrypts_identity_numbers(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee->user)->put('/my/pds/personal', [
            'place_of_birth' => 'Tacloban City',
            'civil_status' => 'single',
            'height_m' => '1.65',
            'tin_no' => '123-456-789-000',
            'philsys_no' => '1234-5678-9012-3456',
            'citizenship' => 'filipino',
        ])->assertSessionHasNoErrors();

        $profile = $employee->pdsProfile()->firstOrFail();
        $this->assertSame('123-456-789-000', $profile->tin_no);
        $this->assertSame('Tacloban City', $profile->place_of_birth);

        // At rest the numbers are ciphertext, not the plain value.
        $raw = DB::table('pds_profiles')->where('id', $profile->id)->first();
        $this->assertNotSame('123-456-789-000', $raw->tin_no);
        $this->assertStringNotContainsString('1234-5678', $raw->philsys_no);
    }

    public function test_the_name_extension_lives_on_the_account_and_in_the_display_name(): void
    {
        $employee = $this->employee(['first_name' => 'Juan', 'middle_name' => 'Dela', 'last_name' => 'Cruz']);

        $this->actingAs($employee->user)->put('/my/pds/personal', ['name_extension' => 'Jr.'])->assertSessionHasNoErrors();

        $this->assertSame('Juan D. Cruz Jr.', $employee->user->fresh()->name);
    }

    public function test_answers_that_no_longer_apply_are_dropped(): void
    {
        $employee = $this->employee();
        $user = $employee->user;

        $this->actingAs($user)->put('/my/pds/personal', [
            'citizenship' => 'dual',
            'dual_citizenship_by' => 'birth',
            'dual_citizenship_country' => 'Japan',
            'civil_status' => 'other',
            'civil_status_other' => 'Annulled',
        ])->assertSessionHasNoErrors();

        // Switching back blanks the now-hidden details.
        $this->actingAs($user)->put('/my/pds/personal', [
            'citizenship' => 'filipino',
            'dual_citizenship_country' => 'Japan',
            'civil_status' => 'married',
            'civil_status_other' => 'Annulled',
        ])->assertSessionHasNoErrors();

        $profile = $employee->pdsProfile()->firstOrFail();
        $this->assertNull($profile->dual_citizenship_country);
        $this->assertNull($profile->dual_citizenship_by);
        $this->assertNull($profile->civil_status_other);
    }

    public function test_conditional_fields_are_required_when_they_apply(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee->user)->put('/my/pds/personal', [
            'citizenship' => 'dual',
            'civil_status' => 'other',
            'dual_citizenship_country' => 'Atlantis', // not on the form's list
        ])->assertSessionHasErrors(['dual_citizenship_by', 'dual_citizenship_country', 'civil_status_other']);
    }

    public function test_questions_need_details_when_yes_and_are_encrypted(): void
    {
        $employee = $this->employee();
        $user = $employee->user;

        $this->actingAs($user)->put('/my/pds/questions', ['q35b' => true])
            ->assertSessionHasErrors('q35b_details');

        $this->actingAs($user)->put('/my/pds/questions', [
            'q34a' => false,
            'q35b' => true,
            'q35b_details' => 'Case no. 12345 for estafa',
            'q35b_date_filed' => '2020-01-15',
            'q35b_case_status' => 'Dismissed',
            'q40b' => false,
            'q40b_id' => 'PWD-999', // dropped: the answer is No
        ])->assertSessionHasNoErrors();

        $answers = $employee->pdsProfile()->firstOrFail()->questions;
        $this->assertTrue($answers['q35b']);
        $this->assertSame('Case no. 12345 for estafa', $answers['q35b_details']);
        $this->assertNull($answers['q40b_id']);

        $raw = DB::table('pds_profiles')->where('employee_id', $employee->id)->value('questions');
        $this->assertStringNotContainsString('estafa', $raw);
    }

    public function test_the_audit_log_records_what_changed_but_never_the_values(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee->user)->put('/my/pds/personal', ['tin_no' => '123-456-789'])->assertSessionHasNoErrors();

        $log = AuditLog::query()->where('event', 'pds.updated')->firstOrFail();
        $this->assertSame('personal', $log->metadata['section']);
        $this->assertContains('tin_no', $log->metadata['changed']);
        $this->assertStringNotContainsString('123-456-789', json_encode($log->metadata));
        $this->assertSame($employee->user->id, $log->actor_id);
    }

    // ── Repeating sections ───────────────────────────────────────────

    public function test_a_list_section_adds_updates_reorders_and_removes_rows(): void
    {
        $employee = $this->employee();
        $user = $employee->user;

        $this->actingAs($user)->put('/my/pds/children', ['rows' => [
            ['full_name' => 'Ana Cruz', 'date_of_birth' => '2015-03-01'],
            ['full_name' => 'Ben Cruz'],
        ]])->assertSessionHasNoErrors();

        [$ana, $ben] = PdsChild::query()->where('employee_id', $employee->id)->orderBy('sort_order')->get();

        // Ben first, Ana renamed, and a new child; nothing else kept.
        $this->actingAs($user)->put('/my/pds/children', ['rows' => [
            ['id' => $ben->id, 'full_name' => 'Ben Cruz'],
            ['id' => $ana->id, 'full_name' => 'Ana Marie Cruz', 'date_of_birth' => '2015-03-01'],
            ['full_name' => 'Cara Cruz'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(
            ['Ben Cruz', 'Ana Marie Cruz', 'Cara Cruz'],
            PdsChild::query()->where('employee_id', $employee->id)->orderBy('sort_order')->pluck('full_name')->all(),
        );

        $log = AuditLog::query()->where('event', 'pds.updated')->latest('id')->firstOrFail();
        $this->assertSame(['section' => 'children', 'added' => 1, 'updated' => 1, 'removed' => 0], $log->metadata);

        $this->actingAs($user)->put('/my/pds/children', ['rows' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, PdsChild::query()->where('employee_id', $employee->id)->count());
    }

    public function test_rows_of_another_employee_cannot_be_touched(): void
    {
        $other = $this->employee();
        $theirs = new PdsChild(['full_name' => 'Not yours']);
        $theirs->employee()->associate($other)->save();

        $employee = $this->employee();

        $this->actingAs($employee->user)->put('/my/pds/children', ['rows' => [
            ['id' => $theirs->id, 'full_name' => 'Hijacked'],
        ]])->assertSessionHasErrors('rows.0.id');

        $this->assertSame('Not yours', $theirs->fresh()->full_name);
    }

    public function test_list_rows_are_validated(): void
    {
        $employee = $this->employee();
        $user = $employee->user;

        $this->actingAs($user)->put('/my/pds/work_experience', ['rows' => [[
            'date_from' => '2020-01-01',
            'date_to' => '2019-01-01',
            'position_title' => 'Clerk',
            'agency' => 'CSC RO VIII',
            'salary_grade' => 'eleven',
        ]]])->assertSessionHasErrors(['rows.0.date_to', 'rows.0.salary_grade']);

        $this->actingAs($user)->put('/my/pds/references', ['rows' => array_fill(0, 4, ['name' => 'Someone'])])
            ->assertSessionHasErrors('rows');

        $this->actingAs($user)->put('/my/pds/references', ['rows' => array_fill(0, 3, ['name' => 'Someone'])])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, PdsReference::query()->where('employee_id', $employee->id)->count());
    }

    public function test_items_31_to_33_share_a_table_but_not_their_rows(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee->user)->put('/my/pds/skills', ['rows' => [['detail' => 'Programming']]])->assertSessionHasNoErrors();
        $this->actingAs($employee->user)->put('/my/pds/memberships', ['rows' => [['detail' => 'PICPA']]])->assertSessionHasNoErrors();
        // Clearing memberships leaves skills alone.
        $this->actingAs($employee->user)->put('/my/pds/memberships', ['rows' => []])->assertSessionHasNoErrors();

        $this->actingAs($employee->user)->get('/my/pds')
            ->assertInertia(fn (Assert $page) => $page
                ->has('pds.sections.skills', 1)
                ->where('pds.sections.skills.0.detail', 'Programming')
                ->has('pds.sections.memberships', 0));
    }

    public function test_unknown_sections_do_not_exist(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee->user)->put('/my/pds/salary', [])->assertNotFound();
    }

    // ── HR access ────────────────────────────────────────────────────

    public function test_hr_reads_a_pds_and_the_access_is_logged(): void
    {
        $employee = $this->employee();
        $hr = $this->userWith(['pds.view']);

        $this->actingAs($hr)->get("/admin/hr/employees/{$employee->id}/pds")
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Employees/Pds')->where('canEdit', false));

        $this->assertDatabaseHas('audit_logs', ['event' => 'pds.viewed', 'actor_id' => $hr->id, 'target_id' => $employee->id]);

        // Reading isn't editing.
        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}/pds/personal", ['place_of_birth' => 'X'])->assertForbidden();
    }

    public function test_supervisors_and_others_never_reach_a_pds(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $employee = $this->employee(['organizational_unit_id' => $office->id]);
        $supervisor = $this->userWith(['employees.view_unit'], ['organizational_unit_id' => $office->id]);

        // The supervisor can open the HR-1 summary…
        $this->actingAs($supervisor)->get("/admin/hr/employees/{$employee->id}")->assertOk();
        // …but not the PDS.
        $this->actingAs($supervisor)->get("/admin/hr/employees/{$employee->id}/pds")->assertForbidden();
        $this->actingAs(User::factory()->create())->get("/admin/hr/employees/{$employee->id}/pds")->assertForbidden();
    }

    public function test_hr_can_edit_on_someones_behalf_and_it_is_logged_as_hr(): void
    {
        $employee = $this->employee();
        $hr = $this->userWith(['pds.view', 'pds.update']);

        $this->actingAs($hr)->put("/admin/hr/employees/{$employee->id}/pds/personal", ['place_of_birth' => 'Palo, Leyte'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Palo, Leyte', $employee->pdsProfile()->firstOrFail()->place_of_birth);
        $this->assertDatabaseHas('audit_logs', ['event' => 'pds.updated', 'actor_id' => $hr->id, 'target_id' => $employee->id]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $employee
     */
    private function employee(array $user = [], array $employee = []): Employee
    {
        return Employee::factory()->for(User::factory()->state($user))->create($employee);
    }

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
