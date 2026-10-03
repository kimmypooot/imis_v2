<?php

namespace Tests\Feature\HR;

use App\Enums\LdApprovalStatus;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-3a: the employee's Learning & Development log (ldms), its rules,
 * approval locking, certificates, and how it feeds PDS Section VI.
 */
class LearningDevelopmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_log_needs_an_employee_record(): void
    {
        $this->actingAs(User::factory()->create())->get('/my/learning-development')
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyLearningDevelopment')->where('employee', null));
    }

    public function test_an_employee_adds_a_training_that_waits_for_hrd_and_shows_on_the_pds(): void
    {
        $employee = Employee::factory()->create();
        $user = $employee->user;

        $this->actingAs($user)->post('/my/learning-development', $this->training())->assertSessionHasNoErrors();

        $record = PdsLearningDevelopment::query()->sole();
        $this->assertSame(LdApprovalStatus::Pending, $record->approval_status);
        $this->assertSame($user->id, $record->created_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.created', 'actor_id' => $user->id, 'target_id' => $record->id]);

        // Section VI of the PDS, as it prints (type as its label).
        $this->actingAs($user)->get('/my/pds')->assertInertia(fn (Assert $page) => $page
            ->has('pds.sections.learning_development', 1)
            ->where('pds.sections.learning_development.0.ld_type', 'Technical')
            ->where('schema.sections.learning_development.managed_by.url', '/my/learning-development'));
    }

    public function test_the_pds_no_longer_edits_section_vi(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($employee->user)->put('/my/pds/learning_development', ['rows' => []])->assertNotFound();
    }

    public function test_ldms_rules_are_enforced(): void
    {
        $employee = Employee::factory()->create();
        $user = $employee->user;

        $this->actingAs($user)->post('/my/learning-development', $this->training(['hours' => 0]))->assertSessionHasErrors('hours');
        $this->actingAs($user)->post('/my/learning-development', $this->training(['date_to' => '2025-01-01']))->assertSessionHasErrors('date_to');
        $this->actingAs($user)->post('/my/learning-development', $this->training(['modality' => null]))->assertSessionHasErrors('modality');
        $this->actingAs($user)->post('/my/learning-development', $this->training(['ld_type' => 'other']))->assertSessionHasErrors('ld_type_other');

        $this->actingAs($user)->post('/my/learning-development', $this->training())->assertSessionHasNoErrors();
        // Same title (any case) and start date: a duplicate, as in ldms.
        $this->actingAs($user)->post('/my/learning-development', $this->training(['title' => 'DATA PRIVACY ACT ORIENTATION']))
            ->assertSessionHasErrors('title');
        // Someone else can have the same training.
        $other = Employee::factory()->create();
        $this->actingAs($other->user)->post('/my/learning-development', $this->training())->assertSessionHasNoErrors();
    }

    public function test_approved_trainings_are_locked_for_the_owner(): void
    {
        $record = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Approved);
        $user = $record->employee->user;

        $this->actingAs($user)->post("/my/learning-development/{$record->id}", [...$this->training(['title' => 'Changed']), '_method' => 'put'])
            ->assertForbidden();
        $this->actingAs($user)->delete("/my/learning-development/{$record->id}")->assertForbidden();

        $this->actingAs($user)->get('/my/learning-development?year=2025')
            ->assertInertia(fn (Assert $page) => $page->where('records.0.can.update', false));
    }

    public function test_fixing_a_rejected_training_sends_it_back_for_review(): void
    {
        $record = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Rejected);
        $record->forceFill(['rejection_reason' => 'No certificate'])->save();

        $this->actingAs($record->employee->user)
            ->post("/my/learning-development/{$record->id}", [...$this->training(['hours' => 16]), '_method' => 'put'])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(LdApprovalStatus::Pending, $record->approval_status);
        $this->assertNull($record->rejection_reason);
        $this->assertSame('16.00', $record->hours);
    }

    public function test_others_cannot_edit_my_trainings(): void
    {
        $record = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Pending);
        $stranger = Employee::factory()->create()->user;

        $this->actingAs($stranger)->post("/my/learning-development/{$record->id}", [...$this->training(), '_method' => 'put'])->assertForbidden();
        $this->actingAs($stranger)->delete("/my/learning-development/{$record->id}")->assertForbidden();
    }

    public function test_removing_soft_deletes_and_rejected_ones_leave_the_pds(): void
    {
        $employee = Employee::factory()->create();
        $pending = $this->recordFor($employee, LdApprovalStatus::Pending);
        $this->recordFor($employee, LdApprovalStatus::Rejected, ['title' => 'Rejected one']);
        $this->recordFor($employee, LdApprovalStatus::Approved, ['title' => 'Approved one']);

        $this->actingAs($employee->user)->delete("/my/learning-development/{$pending->id}")->assertRedirect();
        $this->assertSoftDeleted($pending);

        $this->actingAs($employee->user)->get('/my/pds')->assertInertia(fn (Assert $page) => $page
            ->has('pds.sections.learning_development', 1)
            ->where('pds.sections.learning_development.0.title', 'Approved one'));

        // Cards count the year's trainings except rejected ones.
        $this->actingAs($employee->user)->get('/my/learning-development?year=2025')->assertInertia(fn (Assert $page) => $page
            ->has('records', 2)
            ->where('stats.total', 1)
            ->where('stats.by_type.technical', 1));
    }

    public function test_certificates_are_private_and_hrd_access_is_logged(): void
    {
        $employee = Employee::factory()->create();
        $user = $employee->user;

        $this->actingAs($user)->post('/my/learning-development', $this->training([
            'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasNoErrors();

        $record = PdsLearningDevelopment::query()->sole();
        Storage::disk('local')->assertExists($record->certificate_path);
        $this->assertStringStartsWith("hr/ld/{$employee->id}/", $record->certificate_path);

        $this->actingAs($user)->get("/hr/trainings/{$record->id}/certificate")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create())->get("/hr/trainings/{$record->id}/certificate")->assertForbidden();

        $hrd = $this->userWith(['ld.view']);
        $this->actingAs($hrd)->get("/hr/trainings/{$record->id}/certificate")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.certificate_viewed', 'actor_id' => $hrd->id]);

        // Replacing removes the old file.
        $old = $record->certificate_path;
        $this->actingAs($user)->post("/my/learning-development/{$record->id}", [
            ...$this->training(['certificate' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf')]),
            '_method' => 'put',
        ])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($record->fresh()->certificate_path);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function training(array $overrides = []): array
    {
        return [
            'title' => 'Data Privacy Act Orientation',
            'date_from' => '2025-03-10',
            'date_to' => '2025-03-11',
            'hours' => 8,
            'ld_type' => 'technical',
            'modality' => 'online',
            'sponsor' => 'National Privacy Commission',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordFor(Employee $employee, LdApprovalStatus $status, array $attributes = []): PdsLearningDevelopment
    {
        $record = new PdsLearningDevelopment;
        $record->forceFill([
            'employee_id' => $employee->id,
            'title' => 'Records Management',
            'date_from' => '2025-05-05',
            'date_to' => '2025-05-05',
            'hours' => 4,
            'ld_type' => 'technical',
            'modality' => 'face_to_face',
            'sponsor' => 'CSC RO VIII',
            'approval_status' => $status,
            ...$attributes,
        ])->save();

        return $record;
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
