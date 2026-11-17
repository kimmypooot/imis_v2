<?php

namespace Tests\Feature\HR;

use App\Enums\DataCorrectionStatus;
use App\Models\DataCorrectionRequest;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\HrUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Staff ask HR to correct their own employee record; HR approves (the
 * record changes) or rejects with a reason.
 */
class DataCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_ask_for_a_correction_and_reviewers_are_told(): void
    {
        Notification::fake();
        $reviewer = $this->reviewer();
        $employee = $this->employee(['last_name' => 'Cruz']);

        $this->actingAs($employee->user)->post('/my/corrections', [
            'field' => 'last_name', 'proposed_value' => 'Dela Cruz', 'reason' => 'Misspelled at set-up.',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertDatabaseHas('data_correction_requests', [
            'employee_id' => $employee->id, 'field' => 'last_name', 'current_value' => 'Cruz',
            'proposed_value' => 'Dela Cruz', 'status' => 'pending',
        ]);
        Notification::assertSentTo($reviewer, HrUpdate::class);
    }

    public function test_only_listed_fields_can_be_corrected_and_the_value_must_change(): void
    {
        $employee = $this->employee(['last_name' => 'Cruz']);

        $this->actingAs($employee->user)->post('/my/corrections', [
            'field' => 'organizational_unit_id', 'proposed_value' => '3', 'reason' => 'Wrong office.',
        ])->assertSessionHasErrors('field');

        $this->actingAs($employee->user)->post('/my/corrections', [
            'field' => 'last_name', 'proposed_value' => 'Cruz', 'reason' => 'Same value.',
        ])->assertSessionHasErrors('proposed_value');

        $this->assertDatabaseCount('data_correction_requests', 0);
    }

    public function test_one_pending_request_per_field(): void
    {
        $employee = $this->employee(['last_name' => 'Cruz']);
        DataCorrectionRequest::factory()->create(['employee_id' => $employee->id, 'field' => 'last_name']);

        $this->actingAs($employee->user)->post('/my/corrections', [
            'field' => 'last_name', 'proposed_value' => 'Reyes', 'reason' => 'Another try.',
        ])->assertSessionHasErrors('field');
    }

    public function test_approving_applies_the_change_to_the_record(): void
    {
        Notification::fake();
        $reviewer = $this->reviewer();
        $employee = $this->employee(['first_name' => 'Juan', 'last_name' => 'Cruz']);
        $request = DataCorrectionRequest::factory()->create(['employee_id' => $employee->id, 'field' => 'last_name', 'current_value' => 'Cruz', 'proposed_value' => 'Dela Cruz']);

        $this->actingAs($reviewer)->post("/admin/hr/corrections/{$request->id}", ['decision' => 'approved'])->assertSessionHasNoErrors();

        $this->assertSame(DataCorrectionStatus::Approved, $request->fresh()->status);
        $this->assertSame('Dela Cruz', $employee->user->fresh()->last_name);
        $this->assertStringContainsString('Dela Cruz', $employee->user->fresh()->name);
        Notification::assertSentTo($employee->user, HrUpdate::class);
    }

    public function test_approving_an_employee_field_updates_the_employee_record(): void
    {
        Notification::fake();
        $employee = $this->employee();
        $request = DataCorrectionRequest::factory()->create(['employee_id' => $employee->id, 'field' => 'original_appointment_date', 'current_value' => null, 'proposed_value' => '2015-06-01']);

        $this->actingAs($this->reviewer())->post("/admin/hr/corrections/{$request->id}", ['decision' => 'approved']);

        $this->assertSame('2015-06-01', $employee->fresh()->original_appointment_date->format('Y-m-d'));
    }

    public function test_rejecting_needs_a_reason_and_leaves_the_record_alone(): void
    {
        Notification::fake();
        $employee = $this->employee(['last_name' => 'Cruz']);
        $request = DataCorrectionRequest::factory()->create(['employee_id' => $employee->id]);
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer)->post("/admin/hr/corrections/{$request->id}", ['decision' => 'rejected'])->assertSessionHasErrors('remarks');
        $this->actingAs($reviewer)->post("/admin/hr/corrections/{$request->id}", ['decision' => 'rejected', 'remarks' => 'Not supported by your documents.'])->assertSessionHasNoErrors();

        $this->assertSame(DataCorrectionStatus::Rejected, $request->fresh()->status);
        $this->assertSame('Cruz', $employee->user->fresh()->last_name);
    }

    public function test_a_reviewer_cannot_decide_their_own_request_and_others_need_the_permission(): void
    {
        $reviewer = $this->reviewer();
        $own = Employee::factory()->create(['user_id' => $reviewer->id]);
        $request = DataCorrectionRequest::factory()->create(['employee_id' => $own->id]);

        $this->actingAs($reviewer)->post("/admin/hr/corrections/{$request->id}", ['decision' => 'approved'])->assertForbidden();

        $other = DataCorrectionRequest::factory()->create(['employee_id' => $this->employee()->id]);
        $this->actingAs(User::factory()->create())->post("/admin/hr/corrections/{$other->id}", ['decision' => 'approved'])->assertForbidden();
        $this->assertSame(DataCorrectionStatus::Pending, $other->fresh()->status);
    }

    public function test_a_pending_request_can_be_cancelled_only_by_its_owner(): void
    {
        $employee = $this->employee();
        $request = DataCorrectionRequest::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($this->employee()->user)->post("/my/corrections/{$request->id}/cancel")->assertForbidden();
        $this->actingAs($employee->user)->post("/my/corrections/{$request->id}/cancel")->assertSessionHasNoErrors();

        $this->assertSame(DataCorrectionStatus::Cancelled, $request->fresh()->status);
    }

    public function test_the_review_queue_lists_requests_for_reviewers_only(): void
    {
        DataCorrectionRequest::factory()->create(['employee_id' => $this->employee()->id]);

        $this->actingAs($this->reviewer())->get('/admin/hr/corrections')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/DataCorrections/Index')->has('requests', 1));
        $this->actingAs(User::factory()->create())->get('/admin/hr/corrections')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function employee(array $user = []): Employee
    {
        return Employee::factory()->create(['user_id' => User::factory()->create($user)->id]);
    }

    private function reviewer(): User
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'data_corrections.review'], ['display_name' => 'Review corrections']));
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }
}
