<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveApplicationStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveDesignation;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdate;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-6b: filing leave on CS Form No. 6 and its approval chain —
 * supervisor → HR → Head of Agency (or an officer-in-charge).
 */
class LeaveApplicationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $supervisor;

    private User $hr;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-06-01 09:00:00');
        $this->seed(LeaveTypeSeeder::class);

        $this->supervisor = User::factory()->create();
        $this->hr = $this->userWith(['leave.certify', 'leave.view']);
        $this->head = $this->userWith(['leave.approve']);

        $user = User::factory()->create(['sex' => 'female']);
        $this->employee = Employee::factory()->for($user)->create([
            'employment_status' => EmploymentStatus::Permanent,
            'original_appointment_date' => '2020-01-02',
            'immediate_supervisor_id' => $this->supervisor->id,
        ]);
        $this->credit('VL', 10);
        $this->credit('SL', 10);
    }

    public function test_filing_vacation_leave_starts_the_chain_and_tells_the_supervisor(): void
    {
        Notification::fake();

        $this->actingAs($this->employee->user)->post('/my/leave/applications', $this->vacation())->assertRedirect()->assertSessionHasNoErrors();

        $application = LeaveApplication::query()->sole();
        $this->assertSame('LV-2026-'.str_pad((string) $application->id, 5, '0', STR_PAD_LEFT), $application->reference_number);
        $this->assertSame(LeaveApplicationStatus::ForReview, $application->status);
        $this->assertSame('5.000', $application->days);
        $this->assertSame(['supervisor', 'hr', 'head'], $application->steps()->pluck('role')->all());
        Notification::assertSentTo($this->supervisor, LeaveApplicationUpdate::class);
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_application.filed', 'target_id' => $application->id]);
    }

    public function test_filing_rules_from_the_legacy_lms(): void
    {
        $user = $this->employee->user;

        // Not 5 days ahead.
        $this->actingAs($user)->post('/my/leave/applications', $this->vacation(['date_from' => '2026-06-03', 'date_to' => '2026-06-03']))->assertSessionHasErrors('date_from');
        // More than the credits.
        $this->actingAs($user)->post('/my/leave/applications', $this->vacation(['date_to' => '2026-06-30']))->assertSessionHasErrors('date_to');
        // The details box must be ticked.
        $this->actingAs($user)->post('/my/leave/applications', $this->vacation(['detail' => null]))->assertSessionHasErrors('detail');
        // Paternity leave is for men.
        $this->actingAs($user)->post('/my/leave/applications', ['leave_type_id' => $this->type('PL')->id, 'date_from' => '2026-06-15', 'date_to' => '2026-06-16'])->assertSessionHasErrors('leave_type_id');
        // Maternity leave isn't by the half day.
        $this->actingAs($user)->post('/my/leave/applications', ['leave_type_id' => $this->type('ML')->id, 'date_from' => '2026-06-15', 'date_to' => '2026-06-15', 'is_half_day' => true, 'half_day_period' => 'AM'])->assertSessionHasErrors('is_half_day');
        // Sick leave over 5 days needs a medical certificate.
        $this->actingAs($user)->post('/my/leave/applications', ['leave_type_id' => $this->type('SL')->id, 'date_from' => '2026-05-18', 'date_to' => '2026-05-26', 'detail' => 'out_patient', 'detail_text' => 'Flu'])->assertSessionHasErrors('attachment');

        // Overlaps and pending credits.
        $this->actingAs($user)->post('/my/leave/applications', $this->vacation())->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/my/leave/applications', $this->vacation())->assertSessionHasErrors('date_from');
        $this->actingAs($user)->post('/my/leave/applications', $this->vacation(['date_from' => '2026-07-06', 'date_to' => '2026-07-13']))->assertSessionHasErrors('date_to');

        // No supervisor: standard leave can't be filed.
        $this->employee->forceFill(['immediate_supervisor_id' => null])->save();
        $this->actingAs($user->fresh())->post('/my/leave/applications', $this->vacation(['date_from' => '2026-07-20', 'date_to' => '2026-07-20']))->assertSessionHasErrors('leave_type_id');
        $this->assertSame(1, LeaveApplication::query()->count());
    }

    public function test_the_full_chain_approves_and_deducts_the_credits(): void
    {
        Notification::fake();
        $application = $this->file();

        $this->act($this->supervisor, $application, 'recommended')->assertSessionHasNoErrors();
        $this->act($this->hr, $application, 'certified')->assertSessionHasNoErrors();
        $certified = $application->steps()->where('role', 'hr')->sole()->certified_credits;
        $this->assertEquals(10, $certified['VL']);
        $this->assertEquals(10, $certified['SL']);

        $this->act($this->head, $application, 'approved')->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(LeaveApplicationStatus::Approved, $application->status);
        $this->assertSame('5.000', $application->days_with_pay);
        $this->assertSame('0.000', $application->days_without_pay);
        $this->assertSame(5.0, app(LeaveCredits::class)->balance($this->employee, $this->type('VL')));
        // Dated the day it's approved (so today's balance already excludes it).
        $this->assertSame('2026-06-01', $this->employee->leaveLedger()->where('kind', 'deduction')->sole()->entry_date->toDateString());
        Notification::assertSentTo($this->employee->user, LeaveApplicationUpdate::class);
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_application.acted', 'target_id' => $application->id]);
    }

    public function test_only_the_waiting_actor_can_act_and_never_on_their_own(): void
    {
        $application = $this->file();

        $this->act($this->hr, $application, 'certified')->assertForbidden();
        $this->act(User::factory()->create(), $application, 'recommended')->assertForbidden();

        // HR filing their own leave can't certify it.
        $hrEmployee = Employee::factory()->for($this->hr)->create(['employment_status' => EmploymentStatus::Permanent, 'immediate_supervisor_id' => $this->supervisor->id]);
        $this->credit('VL', 10, $hrEmployee);
        $own = $this->file($hrEmployee);
        $this->act($this->supervisor, $own, 'recommended');
        $this->act($this->hr, $own, 'certified')->assertForbidden();
    }

    public function test_an_officer_in_charge_acts_for_the_head(): void
    {
        $application = $this->file();
        $this->act($this->supervisor, $application, 'recommended');
        $this->act($this->hr, $application, 'certified');

        $oic = User::factory()->create();
        $this->act($oic, $application, 'approved')->assertForbidden();

        $designation = new LeaveDesignation(['designee_id' => $oic->id, 'acting_for_id' => $this->head->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-05']);
        $designation->save();

        $this->act($oic, $application, 'approved')->assertSessionHasNoErrors();
        $step = $application->steps()->where('role', 'head')->sole();
        $this->assertSame($oic->id, $step->acted_by);
        $this->assertSame($this->head->id, $step->acted_for);
    }

    public function test_returned_applications_are_corrected_and_resubmitted_as_a_new_round(): void
    {
        $application = $this->file();
        $this->act($this->supervisor, $application, 'returned', '')->assertSessionHasErrors('remarks');
        $this->act($this->supervisor, $application, 'returned', 'Please give the destination.')->assertSessionHasNoErrors();
        $this->assertSame(LeaveApplicationStatus::Returned, $application->fresh()->status);

        $this->actingAs($this->employee->user)->get("/my/leave/apply?resubmit={$application->id}")
            ->assertInertia(fn (Assert $page) => $page->where('resubmitting.returned_remarks', 'Please give the destination.'));

        $this->actingAs($this->employee->user)->post("/my/leave/applications/{$application->id}", [...$this->vacation(['detail' => 'abroad', 'detail_text' => 'Japan']), '_method' => 'put'])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(LeaveApplicationStatus::ForReview, $application->status);
        $this->assertSame(2, $application->currentRound());
        $this->assertSame(1, $application->current_step);
        $this->assertSame(4, $application->steps()->count());
    }

    public function test_insufficient_credits_disapproves_without_deducting(): void
    {
        $application = $this->file();
        $this->act($this->supervisor, $application, 'not_recommended', 'Busy season')->assertSessionHasNoErrors();
        $this->act($this->hr, $application, 'insufficient_credits', 'Credits already committed')->assertSessionHasNoErrors();

        $this->assertSame(LeaveApplicationStatus::Disapproved, $application->fresh()->status);
        $this->assertSame(10.0, app(LeaveCredits::class)->balance($this->employee, $this->type('VL')));
    }

    public function test_special_leave_skips_the_supervisor_and_lwop_reaches_accrual(): void
    {
        $maternity = $this->file(data: ['leave_type_id' => $this->type('ML')->id, 'date_from' => '2026-06-15', 'date_to' => '2026-06-21']);
        $this->assertSame(['hr', 'head'], $maternity->steps()->pluck('role')->all());
        $this->assertSame('7.000', $maternity->days);

        $lwop = $this->file(data: ['leave_type_id' => $this->type('LWOP')->id, 'date_from' => '2026-05-11', 'date_to' => '2026-05-15']);
        $this->act($this->supervisor, $lwop, 'recommended');
        $this->act($this->hr, $lwop, 'certified');
        $this->act($this->head, $lwop, 'approved');
        $this->assertSame('5.000', $lwop->fresh()->days_without_pay);

        // May 2026 has 21 weekdays: 1.25 × 16/21.
        app(LeaveCredits::class)->accrueMonth(2026, 5);
        $this->assertSame('0.952', $this->employee->leaveLedger()->where('kind', 'accrual')->where('leave_type_id', $this->type('VL')->id)->value('amount'));
    }

    public function test_cancelling_and_who_can_see_an_application(): void
    {
        $application = $this->file();

        $this->actingAs(User::factory()->create())->get("/leave/applications/{$application->id}")->assertForbidden();
        $this->actingAs($this->supervisor)->get("/leave/applications/{$application->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/LeaveApplication')->where('action.role', 'supervisor'));
        $this->actingAs($this->employee->user)->get("/leave/applications/{$application->id}/form")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('HR/LeaveFormPrint'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_application.viewed', 'actor_id' => $this->supervisor->id]);

        $this->actingAs($this->supervisor)->post("/my/leave/applications/{$application->id}/cancel")->assertForbidden();
        $this->actingAs($this->employee->user)->post("/my/leave/applications/{$application->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame(LeaveApplicationStatus::Cancelled, $application->fresh()->status);
    }

    public function test_the_attachment_is_private_and_the_bell_counts_unread_notifications(): void
    {
        $application = $this->file(data: ['leave_type_id' => $this->type('SL')->id, 'date_from' => '2026-05-18', 'date_to' => '2026-05-26', 'detail' => 'out_patient', 'detail_text' => 'Flu',
            'attachment' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf')]);

        $this->actingAs($this->employee->user)->get("/leave/applications/{$application->id}/attachment")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create())->get("/leave/applications/{$application->id}/attachment")->assertForbidden();

        $this->actingAs($this->supervisor)->get('/leave/approvals')->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread', 1)
            ->where('flags.leaveApprover', true)
            ->has('queue', 1));

        $notification = $this->supervisor->notifications()->sole();
        $this->actingAs($this->supervisor)->get("/notifications/{$notification->id}")->assertRedirect('/leave/approvals');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_the_form_previews_days_and_credits(): void
    {
        $this->actingAs($this->employee->user)
            ->get('/my/leave/apply?leave_type_id='.$this->type('VL')->id.'&date_from=2026-06-15&date_to=2026-06-19')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/LeaveApply')
                ->where('preview.days', 5)
                ->where('preview.credit.available', 10)
                ->where('preview.issues.detail', 'Tick one of the details of leave.'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function vacation(array $overrides = []): array
    {
        return ['leave_type_id' => $this->type('VL')->id, 'date_from' => '2026-06-15', 'date_to' => '2026-06-19', 'detail' => 'within_ph', ...$overrides];
    }

    /**
     * @param  ?array<string, mixed>  $data
     */
    private function file(?Employee $employee = null, ?array $data = null): LeaveApplication
    {
        $employee ??= $this->employee;
        $this->actingAs($employee->user)->post('/my/leave/applications', $data ?? $this->vacation())->assertSessionHasNoErrors();

        return LeaveApplication::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    }

    private function act(User $user, LeaveApplication $application, string $action, string $remarks = 'OK')
    {
        $step = $application->fresh()->pendingStep() ?? $application->steps()->latest('id')->first();

        return $this->actingAs($user)->post("/leave/approvals/steps/{$step->id}", ['action' => $action, 'remarks' => $remarks]);
    }

    private function credit(string $code, float $days, ?Employee $employee = null): void
    {
        app(LeaveCredits::class)->postManual($employee ?? $this->employee, [
            'leave_type_id' => $this->type($code)->id, 'kind' => 'opening', 'entry_date' => '2026-01-01', 'amount' => $days, 'particulars' => 'Opening balance',
        ], $this->hr ?? User::factory()->create());
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->sole();
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
