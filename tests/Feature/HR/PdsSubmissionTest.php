<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Pds\PdsSubmission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-2b: e-signed PDS submission, HR verification, the "changed since"
 * state, the PDS photo, and printing.
 */
class PdsSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // ── Signing ──────────────────────────────────────────────────────

    public function test_signing_needs_the_declaration_and_the_correct_password(): void
    {
        $employee = $this->employee();
        $user = $employee->user;

        $this->actingAs($user)->post('/my/pds/submit', ['password' => 'password'])->assertSessionHasErrors('declaration');
        $this->actingAs($user)->post('/my/pds/submit', ['declaration' => true, 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertSame(0, PdsSubmission::query()->count());

        $this->actingAs($user)->post('/my/pds/submit', ['declaration' => true, 'password' => 'password'])->assertSessionHasNoErrors();

        $submission = PdsSubmission::query()->sole();
        $this->assertSame('submitted', $submission->status->value);
        $this->assertSame($user->id, $submission->signed_by);
        $this->assertSame('CS Form No. 212 (Revised 2026)', $submission->snapshot['form']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'pds.submitted', 'actor_id' => $user->id]);
    }

    public function test_the_signed_copy_is_frozen_and_encrypted(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee->user)->put('/my/pds/personal', ['tin_no' => '111-222-333'])->assertSessionHasNoErrors();
        $this->sign($employee);

        // Later edits don't touch what was signed.
        $this->actingAs($employee->user)->put('/my/pds/personal', ['tin_no' => '999-999-999'])->assertSessionHasNoErrors();

        $submission = PdsSubmission::query()->sole();
        $this->assertSame('111-222-333', $submission->snapshot['sections']['personal']['tin_no']);
        $this->assertStringNotContainsString('111-222-333', DB::table('pds_submissions')->value('snapshot'));
    }

    public function test_the_state_follows_submission_review_and_later_edits(): void
    {
        $employee = $this->employee();
        $user = $employee->user;
        $hr = $this->userWith(['pds.verify']);

        $this->assertState($user, 'draft');

        $this->sign($employee);
        $this->assertState($user, 'submitted');

        // Unchanged since signing: nothing new to sign.
        $this->actingAs($user)->post('/my/pds/submit', ['declaration' => true, 'password' => 'password'])
            ->assertSessionHasErrors('declaration');

        $this->actingAs($user)->put('/my/pds/personal', ['place_of_birth' => 'Ormoc'])->assertSessionHasNoErrors();
        $this->assertState($user, 'changed_since_submitted');

        // Re-signing supersedes the unreviewed one; HR only sees the latest.
        $this->sign($employee);
        $this->assertSame(['superseded', 'submitted'], PdsSubmission::query()->orderBy('id')->get()->map(fn ($s) => $s->status->value)->all());

        $latest = PdsSubmission::query()->latest('id')->first();
        $this->actingAs($hr)->post("/admin/hr/pds-verifications/{$latest->id}/verify")->assertRedirect('/admin/hr/pds-verifications');
        $this->assertState($user, 'verified');

        $this->actingAs($user)->put('/my/pds/children', ['rows' => [['full_name' => 'New Baby']]])->assertSessionHasNoErrors();
        $this->assertState($user, 'changed_since_verified');
    }

    public function test_saving_without_changes_does_not_count_as_a_change(): void
    {
        $employee = $this->employee();
        $user = $employee->user;
        $this->actingAs($user)->put('/my/pds/children', ['rows' => [['full_name' => 'Ana']]])->assertSessionHasNoErrors();
        $this->sign($employee);

        $this->actingAs($user)->put('/my/pds/children', ['rows' => [['id' => DB::table('pds_children')->value('id'), 'full_name' => 'Ana']]])
            ->assertSessionHasNoErrors();

        $this->assertState($user, 'submitted');
    }

    // ── HR review ────────────────────────────────────────────────────

    public function test_hr_returns_with_remarks_that_the_employee_sees(): void
    {
        $employee = $this->employee();
        $submission = $this->sign($employee);
        $hr = $this->userWith(['pds.verify']);

        $this->actingAs($hr)->post("/admin/hr/pds-verifications/{$submission->id}/return", [])->assertSessionHasErrors('remarks');

        $this->actingAs($hr)->post("/admin/hr/pds-verifications/{$submission->id}/return", ['remarks' => 'Please add your eligibility.'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['event' => 'pds.returned', 'actor_id' => $hr->id]);

        $this->actingAs($employee->user)->get('/my/pds')->assertInertia(fn (Assert $page) => $page
            ->where('status.state', 'returned')
            ->where('history.0.remarks', 'Please add your eligibility.')
            ->where('history.0.reviewer', $hr->name));

        // A returned PDS can be signed again.
        $this->sign($employee);
        $this->assertState($employee->user, 'submitted');
    }

    public function test_the_queue_lists_pending_submissions_and_highlights_changes(): void
    {
        $employee = $this->employee();
        $hr = $this->userWith(['pds.verify']);

        $first = $this->sign($employee);
        $this->actingAs($hr)->get('/admin/hr/pds-verifications')
            ->assertInertia(fn (Assert $page) => $page->has('pending', 1)->where('pending.0.kind', 'First submission'));
        $this->actingAs($hr)->post("/admin/hr/pds-verifications/{$first->id}/verify");

        $this->actingAs($employee->user)->put('/my/pds/education', ['rows' => [['level' => 'college', 'school' => 'EVSU']]])->assertSessionHasNoErrors();
        $second = $this->sign($employee);

        $this->actingAs($hr)->get('/admin/hr/pds-verifications')
            ->assertInertia(fn (Assert $page) => $page->has('pending', 1)->where('pending.0.kind', 'Update'));

        $this->actingAs($hr)->get("/admin/hr/pds-verifications/{$second->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('changedSections', ['education'])
                ->where('canReview', true));

        $this->assertDatabaseHas('audit_logs', ['event' => 'pds.viewed', 'actor_id' => $hr->id]);
    }

    public function test_hr_cannot_verify_their_own_pds_or_a_settled_one(): void
    {
        $hr = $this->userWith(['pds.verify']);
        $own = Employee::factory()->for($hr)->create();
        $mine = $this->sign($own);

        $this->actingAs($hr)->post("/admin/hr/pds-verifications/{$mine->id}/verify")->assertForbidden();

        $other = $this->employee();
        $old = $this->sign($other);
        $this->actingAs($other->user)->put('/my/pds/personal', ['place_of_birth' => 'X'])->assertSessionHasNoErrors();
        $this->sign($other);

        // Superseded: no longer reviewable.
        $this->actingAs($hr)->post("/admin/hr/pds-verifications/{$old->id}/verify")->assertForbidden();
    }

    public function test_only_verifiers_reach_the_queue(): void
    {
        $office = OrganizationalUnit::factory()->create();
        $employee = $this->employee(['organizational_unit_id' => $office->id]);
        $submission = $this->sign($employee);

        foreach ([User::factory()->create(), $this->userWith(['employees.view_unit'], ['organizational_unit_id' => $office->id])] as $outsider) {
            $this->actingAs($outsider)->get('/admin/hr/pds-verifications')->assertForbidden();
            $this->actingAs($outsider)->get("/admin/hr/pds-verifications/{$submission->id}")->assertForbidden();
            $this->actingAs($outsider)->get("/pds/submissions/{$submission->id}/print")->assertForbidden();
        }
    }

    // ── Viewing and printing ─────────────────────────────────────────

    public function test_employees_view_and_print_only_their_own_submissions(): void
    {
        $employee = $this->employee();
        $submission = $this->sign($employee);

        $this->actingAs($employee->user)->get("/my/pds/submissions/{$submission->id}")
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyPdsSubmission')->where('submission.status', 'submitted'));
        $this->actingAs($employee->user)->get("/pds/submissions/{$submission->id}/print")
            ->assertInertia(fn (Assert $page) => $page->component('HR/PdsPrint')->where('signature.name', $employee->user->name));

        $stranger = $this->employee();
        $this->actingAs($stranger->user)->get("/my/pds/submissions/{$submission->id}")->assertNotFound();

        // Printing your own isn't audited; HR printing someone's is.
        $this->assertDatabaseMissing('audit_logs', ['event' => 'pds.printed']);
        $hr = $this->userWith(['pds.view']);
        $this->actingAs($hr)->get("/pds/submissions/{$submission->id}/print")->assertOk();
        $this->actingAs($hr)->get("/admin/hr/employees/{$employee->id}/pds/print")->assertOk();
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'pds.printed')->where('actor_id', $hr->id)->count());
    }

    // ── Photo ────────────────────────────────────────────────────────

    public function test_the_photo_is_private_and_frozen_with_each_submission(): void
    {
        $employee = $this->employee();
        $user = $employee->user;

        $this->actingAs($user)->post('/my/pds/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 600, 800)])->assertSessionHasNoErrors();
        $livePath = $employee->pdsProfile()->value('photo_path');
        Storage::disk('local')->assertExists($livePath);
        $this->assertStringStartsWith('pds/photos/', $livePath);

        $this->actingAs($user)->get('/my/pds/photo')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->userWith(['pds.view']))->get("/admin/hr/employees/{$employee->id}/pds/photo")->assertOk();
        $this->actingAs(User::factory()->create())->get("/admin/hr/employees/{$employee->id}/pds/photo")->assertForbidden();

        $submission = $this->sign($employee);

        // Replacing the live photo leaves the signed copy alone, and
        // counts as a change.
        $this->actingAs($user)->post('/my/pds/photo', ['photo' => UploadedFile::fake()->image('new.jpg', 600, 800)])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($livePath);
        Storage::disk('local')->assertExists($submission->photo_path);
        $this->actingAs($user)->get("/pds/submissions/{$submission->id}/photo")->assertOk();
        $this->assertState($user, 'changed_since_submitted');
    }

    public function test_photos_must_be_real_images(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee->user)->post('/my/pds/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('photo');
        $this->actingAs($employee->user)->post('/my/pds/photo', ['photo' => UploadedFile::fake()->image('tiny.jpg', 50, 50)])
            ->assertSessionHasErrors('photo');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function sign(Employee $employee): PdsSubmission
    {
        $this->actingAs($employee->user)->post('/my/pds/submit', ['declaration' => true, 'password' => 'password'])->assertSessionHasNoErrors();

        return PdsSubmission::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    }

    private function assertState(User $user, string $state): void
    {
        $this->actingAs($user)->get('/my/pds')->assertInertia(fn (Assert $page) => $page->where('status.state', $state));
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function employee(array $user = []): Employee
    {
        return Employee::factory()->for(User::factory()->state($user))->create();
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
