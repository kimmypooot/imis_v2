<?php

namespace Tests\Feature\HR;

use App\Enums\LdApprovalStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-3b: HRD's side of Learning & Development — the review queue, each
 * employee's log managed on their behalf, bulk add, and separation of
 * duties (never your own records).
 */
class LearningDevelopmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_hrd_pages_need_their_permissions(): void
    {
        $employee = Employee::factory()->create();
        $nobody = Employee::factory()->create()->user;

        $this->actingAs($nobody)->get('/admin/hr/learning-development')->assertForbidden();
        $this->actingAs($nobody)->get('/admin/hr/learning-development/reviews')->assertForbidden();
        $this->actingAs($nobody)->get("/admin/hr/employees/{$employee->id}/learning-development")->assertForbidden();
        $this->actingAs($nobody)->post("/admin/hr/employees/{$employee->id}/learning-development", $this->training())->assertForbidden();
        $this->actingAs($nobody)->post('/admin/hr/learning-development/bulk', [...$this->training(), 'employee_ids' => [$employee->id]])->assertForbidden();

        // Viewing alone doesn't let you add or review.
        $viewer = $this->userWith(['ld.view']);
        $this->actingAs($viewer)->get('/admin/hr/learning-development')->assertOk();
        $this->actingAs($viewer)->get('/admin/hr/learning-development/reviews')->assertForbidden();
        $this->actingAs($viewer)->post("/admin/hr/employees/{$employee->id}/learning-development", $this->training())->assertForbidden();
    }

    public function test_the_queue_lists_pending_entries_oldest_first(): void
    {
        $older = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Pending, ['created_at' => now()->subDays(3)]);
        $newer = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Pending, ['created_at' => now()->subDay()]);
        $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Approved);
        $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Rejected);

        $this->actingAs($this->userWith(['ld.approve']))->get('/admin/hr/learning-development/reviews')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/LearningDevelopment/Reviews')
                ->has('pending', 2)
                ->where('pending.0.id', $older->id)
                ->where('pending.1.id', $newer->id)
                ->where('pending.0.can_review', true)
                ->where('canViewLogs', false));
    }

    public function test_hrd_approves_a_pending_entry_which_then_locks_for_the_owner(): void
    {
        $record = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Pending);
        $hrd = $this->userWith(['ld.approve']);

        $this->actingAs($hrd)->post("/admin/hr/learning-development/reviews/{$record->id}/approve")->assertRedirect();

        $record->refresh();
        $this->assertSame(LdApprovalStatus::Approved, $record->approval_status);
        $this->assertSame($hrd->id, $record->reviewed_by);
        $this->assertNotNull($record->reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.approved', 'actor_id' => $hrd->id, 'target_id' => $record->id]);

        // Approved entries can't be reviewed again, and the owner can't edit them.
        $this->actingAs($hrd)->post("/admin/hr/learning-development/reviews/{$record->id}/approve")->assertForbidden();
        $this->actingAs($record->employee->user)->delete("/my/learning-development/{$record->id}")->assertForbidden();
    }

    public function test_rejecting_needs_a_reason_and_the_owner_can_resubmit(): void
    {
        $record = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Pending);
        $hrd = $this->userWith(['ld.approve']);

        $this->actingAs($hrd)->post("/admin/hr/learning-development/reviews/{$record->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(LdApprovalStatus::Pending, $record->fresh()->approval_status);

        $this->actingAs($hrd)->post("/admin/hr/learning-development/reviews/{$record->id}/reject", ['reason' => 'Attach the certificate'])
            ->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(LdApprovalStatus::Rejected, $record->approval_status);
        $this->assertSame('Attach the certificate', $record->rejection_reason);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.rejected', 'actor_id' => $hrd->id]);

        // The owner sees the reason, fixes the entry, and it's pending again.
        $owner = $record->employee->user;
        $this->actingAs($owner)->get('/my/learning-development?year=2025')
            ->assertInertia(fn (Assert $page) => $page->where('records.0.rejection_reason', 'Attach the certificate'));
        $this->actingAs($owner)->post("/my/learning-development/{$record->id}", [...$this->training(), '_method' => 'put'])->assertSessionHasNoErrors();
        $this->assertSame(LdApprovalStatus::Pending, $record->fresh()->approval_status);
    }

    public function test_hrd_never_reviews_or_manages_their_own_entries(): void
    {
        $hrd = $this->userWith(['ld.approve', 'ld.manage', 'ld.view']);
        $own = Employee::factory()->create(['user_id' => $hrd->id]);
        $record = $this->recordFor($own, LdApprovalStatus::Pending);

        $this->actingAs($hrd)->get('/admin/hr/learning-development/reviews')
            ->assertInertia(fn (Assert $page) => $page->where('pending.0.can_review', false));
        $this->actingAs($hrd)->post("/admin/hr/learning-development/reviews/{$record->id}/approve")->assertForbidden();
        $this->actingAs($hrd)->post("/admin/hr/employees/{$own->id}/learning-development", $this->training())->assertForbidden();
        $this->actingAs($hrd)->delete("/admin/hr/employees/{$own->id}/learning-development/{$record->id}")->assertForbidden();
        $this->actingAs($hrd)->post('/admin/hr/learning-development/bulk', [...$this->training(), 'employee_ids' => [$own->id]])
            ->assertSessionHasErrors('employee_ids');

        $this->assertSame(1, PdsLearningDevelopment::query()->count());
    }

    public function test_hrd_adds_edits_and_removes_on_someones_behalf(): void
    {
        $employee = Employee::factory()->create();
        $hrd = $this->userWith(['ld.manage']);
        $base = "/admin/hr/employees/{$employee->id}/learning-development";

        $this->actingAs($hrd)->get("{$base}?year=2025")->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Employees/LearningDevelopment')
            ->where('canAdd', true));
        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.log_viewed', 'actor_id' => $hrd->id, 'target_id' => $employee->id]);

        // HRD's own entries are approved at once.
        $this->actingAs($hrd)->post($base, $this->training())->assertSessionHasNoErrors();
        $record = PdsLearningDevelopment::query()->sole();
        $this->assertSame(LdApprovalStatus::Approved, $record->approval_status);
        $this->assertSame($hrd->id, $record->created_by);
        $this->assertSame($employee->id, $record->employee_id);

        // The same duplicate rule applies.
        $this->actingAs($hrd)->post($base, $this->training())->assertSessionHasErrors('title');

        // Approved records stay editable for HRD, and stay approved.
        $this->actingAs($hrd)->post("{$base}/{$record->id}", [...$this->training(['hours' => 12]), '_method' => 'put'])->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertSame('12.00', $record->hours);
        $this->assertSame(LdApprovalStatus::Approved, $record->approval_status);

        $this->actingAs($hrd)->delete("{$base}/{$record->id}")->assertRedirect();
        $this->assertSoftDeleted($record);
    }

    public function test_a_training_only_resolves_under_its_own_employee(): void
    {
        $record = $this->recordFor(Employee::factory()->create(), LdApprovalStatus::Approved);
        $other = Employee::factory()->create();

        $this->actingAs($this->userWith(['ld.manage']))
            ->delete("/admin/hr/employees/{$other->id}/learning-development/{$record->id}")
            ->assertNotFound();
        $this->assertNotSoftDeleted($record);
    }

    public function test_bulk_add_approves_for_everyone_and_skips_duplicates(): void
    {
        $hrd = $this->userWith(['ld.manage']);
        [$first, $second, $already] = Employee::factory()->count(3)->create()->all();
        // Same title (any case) and start date: already has it.
        $this->recordFor($already, LdApprovalStatus::Pending, ['title' => 'data privacy act orientation', 'date_from' => '2025-03-10']);

        $this->actingAs($hrd)->post('/admin/hr/learning-development/bulk', [
            ...$this->training(),
            'employee_ids' => [$first->id, $second->id, $already->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $message) => str_contains($message, 'Added the training to 2 employees')
            && str_contains($message, $already->user->name));

        $added = PdsLearningDevelopment::query()->where('title', 'Data Privacy Act Orientation')->get();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $added->pluck('employee_id')->all());
        $this->assertTrue($added->every(fn ($record) => $record->approval_status === LdApprovalStatus::Approved));
        $this->assertSame(2, AuditLog::query()->where('event', 'ld_record.created')->count());

        // Everyone already has it now.
        $this->actingAs($hrd)->post('/admin/hr/learning-development/bulk', [...$this->training(), 'employee_ids' => [$first->id]])
            ->assertSessionHas('error');
        $this->assertSame(3, PdsLearningDevelopment::query()->count());
    }

    public function test_bulk_add_validates_the_training_and_people(): void
    {
        $hrd = $this->userWith(['ld.manage']);
        $employee = Employee::factory()->create();

        $this->actingAs($hrd)->post('/admin/hr/learning-development/bulk', [...$this->training(), 'employee_ids' => []])
            ->assertSessionHasErrors('employee_ids');
        $this->actingAs($hrd)->post('/admin/hr/learning-development/bulk', [...$this->training(['hours' => 0]), 'employee_ids' => [$employee->id]])
            ->assertSessionHasErrors('hours');
        $this->actingAs($hrd)->post('/admin/hr/learning-development/bulk', [...$this->training(), 'employee_ids' => [$employee->id, 999999]])
            ->assertSessionHasErrors('employee_ids');

        $this->assertSame(0, PdsLearningDevelopment::query()->count());
    }

    public function test_the_overview_totals_approved_trainings_per_employee(): void
    {
        $employee = Employee::factory()->create();
        $this->recordFor($employee, LdApprovalStatus::Approved, ['hours' => 8]);
        $this->recordFor($employee, LdApprovalStatus::Approved, ['title' => 'Second', 'hours' => 4.5]);
        $this->recordFor($employee, LdApprovalStatus::Pending, ['title' => 'Third']);
        $this->recordFor($employee, LdApprovalStatus::Rejected, ['title' => 'Fourth']);
        // Another year doesn't count.
        $this->recordFor($employee, LdApprovalStatus::Approved, ['title' => 'Old', 'date_from' => '2024-02-02', 'date_to' => '2024-02-02']);

        $this->actingAs($this->userWith(['ld.view']))->get('/admin/hr/learning-development?year=2025')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HR/LearningDevelopment/Index')
                ->where('employees.0.trainings', 2)
                ->where('employees.0.hours', 12.5)
                ->where('employees.0.pending', 1)
                ->where('employees.0.can_manage', false)
                ->where('canBulkAdd', false)
                ->where('pendingTotal', null));
    }

    public function test_the_employee_page_links_to_the_log_and_reviewers_open_certificates(): void
    {
        $employee = Employee::factory()->create();
        $hrd = $this->userWith(['employees.view', 'ld.view']);

        $this->actingAs($hrd)->get("/admin/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('employee.can.viewLd', true));
        $this->actingAs($this->userWith(['employees.view']))->get("/admin/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('employee.can.viewLd', false));

        // A reviewer checks the certificate before approving (and it's logged).
        Storage::disk('local')->put('hr/ld/cert.pdf', '%PDF-1.4');
        $record = $this->recordFor($employee, LdApprovalStatus::Pending, [
            'certificate_path' => 'hr/ld/cert.pdf', 'certificate_name' => 'cert.pdf', 'certificate_mime' => 'application/pdf', 'certificate_size' => 8,
        ]);
        $reviewer = $this->userWith(['ld.approve']);
        $this->actingAs($reviewer)->get("/hr/trainings/{$record->id}/certificate")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.certificate_viewed', 'actor_id' => $reviewer->id]);
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
