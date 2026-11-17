<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Models\ApprovalDelegation;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdate;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Out-of-office delegation of the supervisor step on leave applications.
 */
class LeaveDelegationTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $colleague;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 09:00:00');
        $this->seed(LeaveTypeSeeder::class);

        $this->supervisor = User::factory()->create();
        $this->colleague = User::factory()->create();
        $this->employee = Employee::factory()->for(User::factory()->create())->create([
            'employment_status' => EmploymentStatus::Permanent,
            'original_appointment_date' => '2020-01-02',
            'immediate_supervisor_id' => $this->supervisor->id,
        ]);

        app(LeaveCredits::class)->postManual($this->employee, [
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'), 'kind' => 'opening',
            'entry_date' => '2026-01-01', 'amount' => 10, 'particulars' => 'Opening balance',
        ], User::factory()->create());
    }

    public function test_a_supervisor_can_delegate_and_the_delegate_acts_for_them(): void
    {
        $this->actingAs($this->supervisor)->post('/leave/delegations', [
            'delegate_id' => $this->colleague->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-10', 'reason' => 'Conference',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'approval_delegation.created']);

        $application = $this->file();
        $step = $application->pendingStep();

        $this->actingAs($this->colleague)->get('/leave/approvals')->assertOk();
        $this->actingAs($this->colleague)->post("/leave/approvals/steps/{$step->id}", ['action' => 'recommended', 'remarks' => 'OK'])->assertSessionHasNoErrors();

        $step->refresh();
        $this->assertSame($this->colleague->id, $step->acted_by);
        $this->assertSame($this->supervisor->id, $step->acted_for);
    }

    public function test_the_delegate_is_told_when_an_application_arrives(): void
    {
        $this->actingAs($this->supervisor)->post('/leave/delegations', ['delegate_id' => $this->colleague->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-10']);

        Notification::fake();
        $this->file();

        Notification::assertSentTo($this->colleague, LeaveApplicationUpdate::class);
    }

    public function test_a_colleague_without_delegation_or_outside_its_dates_cannot_act(): void
    {
        $application = $this->file();
        $step = $application->pendingStep();

        $this->actingAs($this->colleague)->post("/leave/approvals/steps/{$step->id}", ['action' => 'recommended', 'remarks' => 'OK'])->assertForbidden();

        ApprovalDelegation::factory()->create(['delegator_id' => $this->supervisor->id, 'delegate_id' => $this->colleague->id, 'date_from' => '2026-06-20', 'date_to' => '2026-06-25']);
        $this->actingAs($this->colleague)->post("/leave/approvals/steps/{$step->id}", ['action' => 'recommended', 'remarks' => 'OK'])->assertForbidden();
    }

    public function test_ending_a_delegation_takes_the_access_back(): void
    {
        $delegation = ApprovalDelegation::factory()->create(['delegator_id' => $this->supervisor->id, 'delegate_id' => $this->colleague->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-10']);
        $step = $this->file()->pendingStep();

        // Someone else cannot end it; the delegator can.
        $this->actingAs($this->colleague)->delete("/leave/delegations/{$delegation->id}")->assertForbidden();
        $this->actingAs($this->supervisor)->delete("/leave/delegations/{$delegation->id}")->assertSessionHasNoErrors();

        $this->actingAs($this->colleague)->post("/leave/approvals/steps/{$step->id}", ['action' => 'recommended', 'remarks' => 'OK'])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['event' => 'approval_delegation.ended']);
    }

    public function test_delegation_rules(): void
    {
        $this->actingAs($this->supervisor);

        // Not to yourself, not in the past, not overlapping another.
        $this->post('/leave/delegations', ['delegate_id' => $this->supervisor->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-02'])->assertSessionHasErrors('delegate_id');
        $this->post('/leave/delegations', ['delegate_id' => $this->colleague->id, 'date_from' => '2026-05-01', 'date_to' => '2026-05-02'])->assertSessionHasErrors('date_from');
        $this->post('/leave/delegations', ['delegate_id' => $this->colleague->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-10'])->assertSessionHasNoErrors();
        $this->post('/leave/delegations', ['delegate_id' => $this->colleague->id, 'date_from' => '2026-06-05', 'date_to' => '2026-06-12'])->assertSessionHasErrors('date_from');
        $this->assertSame(1, ApprovalDelegation::query()->count());

        // Someone who approves nothing has nothing to delegate.
        $this->actingAs(User::factory()->create())->post('/leave/delegations', ['delegate_id' => $this->colleague->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-02'])->assertForbidden();
    }

    private function file(): LeaveApplication
    {
        $this->actingAs($this->employee->user)->post('/my/leave/applications', [
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'),
            'date_from' => '2026-06-15', 'date_to' => '2026-06-19', 'detail' => 'within_ph',
        ])->assertSessionHasNoErrors();

        return LeaveApplication::query()->latest('id')->firstOrFail();
    }
}
