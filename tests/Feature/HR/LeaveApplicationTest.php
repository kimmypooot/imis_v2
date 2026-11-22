<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveApplicationStatus;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveDesignation;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdate;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Leave\LeaveCredits;
use App\Support\HR\Leave\LeaveForm;
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

        // Not 5 days ahead: VL is filed ahead "whenever possible", so later needs a reason.
        $this->actingAs($user)->post('/my/leave/applications', $this->vacation(['date_from' => '2026-06-03', 'date_to' => '2026-06-03']))->assertSessionHasErrors('late_reason');
        // More than the credits: the excess would be without pay (MC 41, s. 1998), not refused.
        $this->actingAs($user)->get('/my/leave/apply?leave_type_id='.$this->type('VL')->id.'&date_from=2026-06-15&date_to=2026-06-30&detail=within_ph')
            ->assertInertia(fn (Assert $page) => $page->missing('preview.issues.date_to')->where('preview.pay.without_pay', 2)
                ->where('preview.notices.0', '2 day(s) are beyond your credits and would be without pay.'));
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
        $this->actingAs($user)->get('/my/leave/apply?leave_type_id='.$this->type('VL')->id.'&date_from=2026-07-06&date_to=2026-07-13&detail=within_ph')
            ->assertInertia(fn (Assert $page) => $page->where('preview.credit.available', 5)->where('preview.pay.without_pay', 1));

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

    public function test_cs_form_6_is_filled_from_the_approval_chain(): void
    {
        $application = $this->file(data: $this->vacation(['date_from' => '2026-06-15', 'date_to' => '2026-06-16', 'detail' => 'within_ph', 'detail_text' => 'Residence']));
        $this->act($this->supervisor, $application, 'recommended');
        $this->act($this->hr, $application, 'certified');
        $this->act($this->head, $application, 'approved');

        $this->actingAs($this->employee->user)->get("/leave/applications/{$application->id}/form")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/LeaveFormPrint')
                ->where('form.type', 'VL')
                ->where('form.details.within_ph', ['checked' => true, 'text' => 'Residence'])
                ->where('form.days', '2.000 days')
                ->where('form.inclusive_dates', '6/15/26 - 6/16/26')
                ->where('form.filed_on', 'Jun. 1, 2026')
                ->where('form.credits.rows.VL', ['earned' => '10.000', 'enjoyed' => '0.000', 'balance' => '10.000', 'less' => '2.000', 'after' => '8.000'])
                ->where('form.credits.rows.SL.less', '0.000')
                ->where('form.recommendation', 'approval')
                ->where('form.approved_for.with_pay', '2.000')
                ->where('form.printed_by', $this->employee->user->email)
                ->where('form.approver.name', mb_strtoupper($this->head->name))
                ->has('types', 13));
    }

    public function test_cs_form_6_downloads_as_a_folio_workbook_on_the_csc_template(): void
    {
        $application = $this->file();

        $this->actingAs(User::factory()->create())->get("/leave/applications/{$application->id}/form.xlsx")->assertForbidden();

        $response = $this->actingAs($this->supervisor)->get("/leave/applications/{$application->id}/form.xlsx")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString($application->reference_number, $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_application.viewed', 'actor_id' => $this->supervisor->id]);

        $path = tempnam(sys_get_temp_dir(), 'cs6test');
        file_put_contents($path, $response->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('paperSize="14"', $sheet);
        $this->assertStringContainsString('fitToPage="1"', $sheet);
        $this->assertStringContainsString('fitToHeight="1"', $sheet);
        $this->assertStringContainsString('<pageMargins left="0.25" right="0.25" top="0.25" bottom="0.25" header="0" footer="0.08"/>', $sheet);
        // Rows grow so the form fills Folio's height, not just A4's.
        $this->assertMatchesRegularExpression('/<row r="3"[^>]* ht="(\d+(\.\d+)?)"/', $sheet);
        preg_match('/<row r="3"[^>]* ht="([\d.]+)"/', $sheet, $title);
        $this->assertGreaterThan(45.75, (float) $title[1]);
        $this->assertStringContainsString('Government Center, Palo, Leyte', $sheet);
        $this->assertStringContainsString('This is a system generated document.', $sheet);

        // Vacation Leave (Check Box 5 → its control part) is ticked; the template's sample ticks are cleared.
        $ticked = collect(range(1, 25))->filter(fn (int $n) => str_contains((string) $zip->getFromName("xl/ctrlProps/ctrlProp{$n}.xml"), 'checked="Checked"'))->count();
        $this->assertSame(3, $ticked); // VL, Within the Philippines, commutation not requested.

        $drawing = $zip->getFromName('xl/drawings/drawing1.xml');
        $this->assertStringContainsString(mb_strtoupper($this->employee->user->last_name), $drawing);
        $this->assertStringNotContainsString('DELA CRUZ', $drawing);
        $this->assertStringNotContainsString('Trillana', $zip->getFromName('docProps/core.xml').$zip->getFromName('xl/workbook.xml'));
        $zip->close();
        unlink($path);
    }

    public function test_the_form_shows_the_salary_imis_pays_on_the_date_of_filing(): void
    {
        // Plantilla: the period held that day, priced by the salary schedule in force.
        SalarySchedule::query()->create(['name' => 'Test schedule', 'reference' => 'Test', 'effective_date' => '2026-01-01'])
            ->rates()->create(['salary_grade' => 15, 'step' => 2, 'monthly_salary' => 51357]);
        $item = PlantillaItem::query()->create(['position_title' => 'Administrative Officer IV', 'salary_grade' => 15]);
        (new PlantillaAppointment(['step' => 2, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2020-01-02']))
            ->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $this->employee->id])->save();

        $application = $this->file();
        $this->assertSame('Php 51,357.00', app(LeaveForm::class)->salary($application));

        // Contract staff: their contract's rate, per day when daily.
        $worker = Employee::factory()->create();
        $worker->nonPlantillaContracts()->create(['employment_status' => 'contractual', 'designation' => 'Driver', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'rate' => 650, 'rate_basis' => 'daily']);
        $filed = (new LeaveApplication(['submitted_at' => now()]))->setRelation('employee', $worker);
        $this->assertSame('Php 650.00/day', app(LeaveForm::class)->salary($filed));
    }

    public function test_leave_can_be_filed_on_dates_picked_one_by_one(): void
    {
        // Five separate days of VL (Mon 15, Wed 17, Fri 19, Mon 22, Wed 24 June).
        $picked = ['2026-06-24', '2026-06-15', '2026-06-17', '2026-06-19', '2026-06-22'];
        $application = $this->file(data: ['leave_type_id' => $this->type('VL')->id, 'dates' => $picked, 'detail' => 'within_ph']);

        $this->assertSame(['2026-06-15', '2026-06-17', '2026-06-19', '2026-06-22', '2026-06-24'], $application->dates);
        $this->assertSame('2026-06-15', $application->date_from->toDateString());
        $this->assertSame('2026-06-24', $application->date_to->toDateString());
        $this->assertSame('5.000', $application->days);
        $this->assertTrue($application->covers('2026-06-17'));
        $this->assertFalse($application->covers('2026-06-16'));

        // A day between them is free; one of them isn't.
        $this->actingAs($this->employee->user)
            ->get('/my/leave/apply?leave_type_id='.$this->type('SL')->id.'&date_from=2026-06-16&date_to=2026-06-16')
            ->assertInertia(fn (Assert $page) => $page->missing('preview.issues.date_from'));
        $this->actingAs($this->employee->user)
            ->get('/my/leave/apply?leave_type_id='.$this->type('SL')->id.'&date_from=2026-06-17&date_to=2026-06-17')
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.date_from', "Those dates overlap application {$application->reference_number}."));

        // A weekend date is refused.
        $this->actingAs($this->employee->user)
            ->get('/my/leave/apply?leave_type_id='.$this->type('VL')->id.'&dates[]=2026-07-06&dates[]=2026-07-11')
            ->assertInertia(fn (Assert $page) => $page->where('preview.days', 1)->where('preview.issues.dates', 'July 11, 2026 isn\'t a working day.'));

        $this->actingAs($this->employee->user)->get("/leave/applications/{$application->id}/form")
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.inclusive_dates', '6/15/26, 6/17/26, 6/19/26, 6/22/26, 6/24/26')
                ->where('form.days', '5.000 days'));
    }

    public function test_wellness_leave_on_picked_dates_goes_on_the_others_line_and_keeps_its_three_day_cap(): void
    {
        $this->credit('WL', 5);
        $wellness = $this->type('WL')->id;

        // Mon–Wed in a row and the Friday: no more than 3 in a row.
        $application = $this->file(data: ['leave_type_id' => $wellness, 'dates' => ['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-19']]);
        $this->assertSame('4.000', $application->days);

        // Thursday would join them into 5 in a row.
        $this->actingAs($this->employee->user)
            ->get("/my/leave/apply?leave_type_id={$wellness}&dates[]=2026-06-18")
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.dates', fn (string $issue) => str_contains($issue, 'at most 3 days in a row')));

        $this->actingAs($this->employee->user)->get("/leave/applications/{$application->id}/form")
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.type', 'others')
                ->where('form.others', 'Wellness Leave')
                ->where('form.inclusive_dates', '6/15/26 - 6/17/26, 6/19/26'));
    }

    public function test_late_vacation_leave_is_accepted_with_a_reason(): void
    {
        $application = $this->file(data: $this->vacation(['date_from' => '2026-06-03', 'date_to' => '2026-06-03', 'late_reason' => 'The trip was moved up.']));

        $this->assertSame('The trip was moved up.', $application->late_reason);
        $this->actingAs($this->supervisor)->get("/leave/applications/{$application->id}")
            ->assertInertia(fn (Assert $page) => $page->where('application.late_reason', 'The trip was moved up.'));
    }

    public function test_sick_leave_beyond_its_credits_is_charged_to_vacation_leave_then_without_pay(): void
    {
        // 10 SL, 10 VL; 14 working days of SL (June 1–18), filed on return with a medical certificate.
        $this->travelTo('2026-06-19 09:00:00');
        $application = $this->file(data: ['leave_type_id' => $this->type('SL')->id, 'date_from' => '2026-06-01', 'date_to' => '2026-06-18', 'detail' => 'out_patient', 'detail_text' => 'Flu',
            'attachment' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf')]);
        $this->assertSame('14.000', $application->days);

        $this->act($this->supervisor, $application, 'recommended');
        $this->act($this->hr, $application, 'certified');
        $this->act($this->head, $application, 'approved');

        $application->refresh();
        $this->assertSame(['SL' => 10, 'VL' => 4], $application->charges);
        $this->assertSame('14.000', $application->days_with_pay);
        $this->assertSame(0.0, app(LeaveCredits::class)->balance($this->employee, $this->type('SL')));
        $this->assertSame(6.0, app(LeaveCredits::class)->balance($this->employee, $this->type('VL')));
        $this->actingAs($this->employee->user)->get("/leave/applications/{$application->id}/form")
            ->assertInertia(fn (Assert $page) => $page->where('form.credits.rows.VL.less', '4.000')->where('form.credits.rows.SL.less', '10.000'));
    }

    public function test_csc_filing_requirements_by_leave_type(): void
    {
        // A fresh user each time, so changes to the employee record are seen.
        $apply = fn (string $query) => $this->actingAs($this->employee->user()->first())->get("/my/leave/apply?{$query}");

        // Sick leave filed in advance needs a medical certificate.
        $apply('leave_type_id='.$this->type('SL')->id.'&date_from=2026-06-15&date_to=2026-06-15&detail=out_patient&detail_text=Surgery')
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.attachment', 'Attach a medical certificate: Sick Leave filed in advance needs one.'));

        // Special Privilege Leave is filed a week ahead.
        $this->credit('SPL', 3);
        $apply('leave_type_id='.$this->type('SPL')->id.'&date_from=2026-06-05&date_to=2026-06-05&detail=within_ph')
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.date_from', fn (string $issue) => str_contains($issue, 'at least 7 days ahead')));

        // Special emergency leave: within 30 days of the calamity.
        $sel = $this->type('SEL')->id;
        $apply("leave_type_id={$sel}&date_from=2026-06-15&date_to=2026-06-15")
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.event_date', 'Give the Date of the calamity (or of its declaration).'));
        $apply("leave_type_id={$sel}&date_from=2026-06-15&date_to=2026-06-15&event_date=2026-05-01")
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.date_to', fn (string $issue) => str_contains($issue, 'within 30 days')));

        // 30 calendar days or more needs a clearance (CSC MC No. 2, s. 1985).
        $apply('leave_type_id='.$this->type('LWOP')->id.'&date_from=2026-07-01&date_to=2026-07-30')
            ->assertInertia(fn (Assert $page) => $page->where('preview.needs.clearance', true)->has('preview.issues.clearance'));

        // Solo Parent Leave needs six months of service (RA 11861).
        $this->employee->forceFill(['original_appointment_date' => '2026-03-01'])->save();
        $apply('leave_type_id='.$this->type('SOLO')->id.'&date_from=2026-06-15&date_to=2026-06-15')
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.leave_type_id', fn (string $issue) => str_contains($issue, 'at least 6 months of service')));

        // Job Order and Contract of Service workers aren't covered by civil service leave.
        $this->employee->forceFill(['employment_status' => EmploymentStatus::JobOrder])->save();
        $apply('leave_type_id='.$this->type('SPL')->id.'&date_from=2026-06-15&date_to=2026-06-15&detail=within_ph')
            ->assertInertia(fn (Assert $page) => $page->has('preview.issues.leave_type_id'));
    }

    public function test_paternity_leave_needs_proof_and_is_for_the_first_four_deliveries(): void
    {
        $father = Employee::factory()->for(User::factory()->create(['sex' => 'male']))->create([
            'employment_status' => EmploymentStatus::Permanent, 'original_appointment_date' => '2020-01-02', 'immediate_supervisor_id' => $this->supervisor->id,
        ]);
        $paternity = ['leave_type_id' => $this->type('PL')->id, 'date_from' => '2026-06-15', 'date_to' => '2026-06-15'];

        $this->actingAs($father->user)->post('/my/leave/applications', $paternity)->assertSessionHasErrors('attachment');

        foreach (['2026-06-15', '2026-07-15', '2026-08-14', '2026-09-15'] as $day) {
            $this->file($father, [...$paternity, 'date_from' => $day, 'date_to' => $day, 'attachment' => UploadedFile::fake()->create('birth.pdf', 50, 'application/pdf')]);
        }
        $this->actingAs($father->user)->post('/my/leave/applications', [...$paternity, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15',
            'attachment' => UploadedFile::fake()->create('birth.pdf', 50, 'application/pdf')])
            ->assertSessionHasErrors(['leave_type_id' => 'Paternity Leave can be taken at most 4 times; you have 4 on record.']);
    }

    public function test_maternity_leave_beyond_its_paid_days_is_without_pay(): void
    {
        // 105 paid days (the employee has no Solo Parent ID), then up to 30 more without pay.
        $maternity = ['leave_type_id' => $this->type('ML')->id, 'date_from' => '2026-07-01', 'date_to' => '2026-11-12',
            'clearance' => UploadedFile::fake()->create('clearance.pdf', 50, 'application/pdf')];
        $application = $this->file(data: $maternity);
        $this->assertSame('135.000', $application->days);

        $this->act($this->hr, $application, 'certified');
        $this->act($this->head, $application, 'approved');
        $this->assertSame('105.000', $application->fresh()->days_with_pay);
        $this->assertSame('30.000', $application->fresh()->days_without_pay);

        $this->actingAs($this->employee->user)->get('/my/leave/apply?leave_type_id='.$this->type('ML')->id.'&date_from=2027-01-01&date_to=2027-05-31')
            ->assertInertia(fn (Assert $page) => $page->where('preview.issues.date_to', fn (string $issue) => str_contains($issue, 'at most 135 days')));
    }

    public function test_approvers_are_reminded_as_the_five_working_days_run_out(): void
    {
        Notification::fake();
        $application = $this->file();

        // Filed Monday 1 June: Friday the 5th is the 4th working day, Monday the 8th the 5th.
        $this->travelTo('2026-06-05 08:00:00');
        $this->artisan('imis:leave-deadlines')->assertSuccessful();
        $this->assertSame(1, $application->fresh()->deadline_notices);
        $this->assertFalse(app(LeaveApplications::class)->isDeemedApproved($application->fresh()));

        $this->travelTo('2026-06-08 08:00:00');
        $this->artisan('imis:leave-deadlines')->assertSuccessful();
        $this->assertSame(2, $application->fresh()->deadline_notices);
        $this->assertTrue(app(LeaveApplications::class)->isDeemedApproved($application->fresh()));
        Notification::assertSentTo($this->supervisor, LeaveApplicationUpdate::class, fn ($notification) => str_contains($notification->toArray($this->supervisor)['title'] ?? '', 'deemed approved'));
        Notification::assertSentTo($this->hr, LeaveApplicationUpdate::class);

        // Told once per stage.
        $this->artisan('imis:leave-deadlines')->assertSuccessful();
        $this->assertSame(2, $application->fresh()->deadline_notices);
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
