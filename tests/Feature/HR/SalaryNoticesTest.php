<?php

namespace Tests\Feature\HR;

use App\Enums\AppointmentNature;
use App\Enums\EmployeeDocumentType;
use App\Enums\SalaryNoticeType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalaryNotice;
use App\Models\SalarySchedule;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Notices of Salary Adjustment (NOSA) and of Step Increment (NOSI):
 * generated from the plantilla and salary schedules, printed for signing,
 * and filed in the 201 File once signed. Amounts are made up.
 */
class SalaryNoticesTest extends TestCase
{
    use RefreshDatabase;

    private SalarySchedule $old;

    private SalarySchedule $new;

    private ?User $hr = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-01-15 09:00:00');
        Storage::fake('local');

        $this->old = $this->schedule('Third tranche, 2026', '2026-01-01', 30000);
        $this->new = $this->schedule('Fourth tranche, 2027', '2027-01-01', 31000, [
            'reference' => 'National Budget Circular No. 999 s. 2027',
            'issuance_date' => '2027-01-10',
            'implements' => 'Executive Order No. 64 s. 2024 dated 02 August 2024',
        ]);

        // The Regional Director signs.
        $this->holder('Director IV', 28, 'Juana', 'Dela Cruz', ['honorific' => 'Atty.'], '2020-01-01', 1);
    }

    public function test_only_hr_with_the_permission_opens_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/hr/salary-notices')->assertForbidden();
        $this->actingAs($this->userWith(['plantilla.manage']))->get('/admin/hr/salary-notices')->assertForbidden();

        $this->actingAs($this->hr())->get('/admin/hr/salary-notices?tab=nosa')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/SalaryNotices/Index')->where('tab', 'nosa'));
    }

    public function test_the_nosa_batch_adjusts_each_holder_at_the_same_grade_and_step_and_says_why_others_are_left_out(): void
    {
        $hr = $this->hr();
        $hr->employee()->create(['employee_number' => 'HR-1']);
        $ready = $this->holder('Administrative Officer IV', 15, 'Rey Albert', 'Uy', [], '2024-03-01', 2);
        $this->holder('Director II', 26, 'Dir', 'Ector', [], '2022-01-01', 1);
        $this->holder('Attorney III', 15, 'Over', 'Paid', [], '2023-01-01', 1, ['salary_override' => 99999]);
        $this->holderFor($hr->employee, 'Human Resource Management Officer', 15, '2023-01-01', 1);
        $this->holder('Clerk', 15, 'New', 'Hire', [], '2027-01-05', 1); // came after the schedule took effect

        $this->actingAs($hr)->get("/admin/hr/salary-notices?tab=nosa&schedule={$this->new->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('nosa.ready', 1)
                ->where('nosa.ready.0.name', 'Rey Albert Uy')
                ->where('nosa.ready.0.from_salary', 30010)
                ->where('nosa.ready.0.to_salary', 31010)
                // The Director IV and II, the one paid above step 8, and the preparer.
                ->has('nosa.skipped', 4));

        $this->actingAs($hr)->post('/admin/hr/salary-notices/nosa', ['schedule_id' => $this->new->id, 'notice_date' => '2027-01-15', 'signatory' => 'director_iv'])
            ->assertRedirect()->assertSessionHas('status');

        $notice = SalaryNotice::query()->sole();
        $this->assertSame(SalaryNoticeType::Nosa, $notice->type);
        $this->assertSame($ready->id, $notice->employee_id);
        $this->assertSame([2, 2, 30010.0, 31010.0], [$notice->from_step, $notice->to_step, (float) $notice->from_salary, (float) $notice->to_salary]);
        $this->assertSame('National Budget Circular No. 999 s. 2027 dated 10 January 2027, implementing Executive Order No. 64 s. 2024 dated 02 August 2024', $notice->basis);
        $this->assertSame(['Atty. Juana Dela Cruz', 'Director IV'], [$notice->signatory_name, $notice->signatory_position]);
        $this->assertSame('Rey Albert Uy', $notice->addressee);

        // Generating again adds nobody twice.
        $this->actingAs($hr)->post('/admin/hr/salary-notices/nosa', ['schedule_id' => $this->new->id, 'notice_date' => '2027-01-15', 'signatory' => 'director_iv'])
            ->assertSessionHasErrors('schedule');
        $this->assertSame(1, SalaryNotice::query()->count());
    }

    public function test_the_skip_reasons_name_each_case(): void
    {
        $hr = $this->hr();
        $hr->employee()->create(['employee_number' => 'HR-1']);
        $this->holder('Director II', 26, 'Dir', 'Ector', [], '2022-01-01', 1);
        $this->holder('Attorney III', 15, 'Over', 'Paid', [], '2023-01-01', 1, ['salary_override' => 99999]);
        $this->holderFor($hr->employee, 'Human Resource Management Officer', 15, '2023-01-01', 1);

        $this->actingAs($hr)->get("/admin/hr/salary-notices?tab=nosa&schedule={$this->new->id}")
            ->assertInertia(fn (Assert $page) => $page->where('nosa.skipped', fn ($rows) => collect($rows)->pluck('reason')->sort()->values()->all() === [
                'Issued by the Central Office.',
                'Issued by the Central Office.',
                'Paid ₱99,999.00, above the new Step 8: no adjustment (NBC §5.1.3).',
                'Your own notice: another HR staff member generates it.',
            ]));
    }

    public function test_the_nosa_needs_the_issuance_it_quotes(): void
    {
        $this->new->forceFill(['issuance_date' => null])->save();
        $this->holder('Administrative Officer IV', 15, 'Rey Albert', 'Uy', [], '2024-03-01', 2);

        $this->actingAs($this->hr())->post('/admin/hr/salary-notices/nosa', ['schedule_id' => $this->new->id, 'notice_date' => '2027-01-15', 'signatory' => 'director_iv'])
            ->assertSessionHasErrors('schedule');
        $this->assertSame(0, SalaryNotice::query()->count());
    }

    public function test_recording_a_due_step_increment_moves_the_plantilla_and_writes_the_nosi(): void
    {
        $employee = $this->holder('Administrative Assistant III', 15, 'Elizar', 'Casinillo', ['sex' => 'male'], '2024-01-03', 1);
        $period = $employee->plantillaAppointments()->sole();

        $this->actingAs($this->hr())->get('/admin/hr/salary-notices?tab=nosi')
            ->assertInertia(fn (Assert $page) => $page->has('due', 1)->where('due.0.due', '2027-01-03')->where('due.0.overdue', true)->where('due.0.next_step', 2));

        $this->actingAs($this->hr())->post('/admin/hr/salary-notices/nosi', [
            'appointment_id' => $period->id, 'record' => true, 'effective_date' => '2027-01-03', 'notice_date' => '2027-01-15', 'signatory' => 'director_iv',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('2027-01-02', $period->fresh()->date_to->toDateString());
        $next = $employee->plantillaAppointments()->whereNull('date_to')->sole();
        $this->assertSame([2, AppointmentNature::StepIncrement], [$next->step, $next->nature]);

        $notice = SalaryNotice::query()->sole();
        $this->assertSame(SalaryNoticeType::Nosi, $notice->type);
        $this->assertSame($next->id, $notice->plantilla_appointment_id);
        // Step 1 on January 2 (the 2027 schedule is already in force), step 2 from January 3.
        $this->assertSame([1, 31000.0, 2, 31010.0], [$notice->from_step, (float) $notice->from_salary, $notice->to_step, (float) $notice->to_salary]);
        $this->assertSame('Dear Mr. Casinillo:', $notice->salutation);
        $this->assertTrue(AuditLog::query()->where('event', 'plantilla_appointment.step_increment')->exists());
    }

    public function test_leave_without_pay_over_fifteen_days_moves_the_due_date(): void
    {
        $this->seed(LeaveTypeSeeder::class);
        $short = $this->holder('Clerk I', 15, 'Short', 'Leave', [], '2024-01-03', 1);
        $long = $this->holder('Clerk II', 15, 'Long', 'Leave', [], '2024-01-03', 1);
        $this->withoutPay($short, '2025-05-05', 15);
        $this->withoutPay($long, '2025-05-05', 20);

        $this->actingAs($this->hr())->get('/admin/hr/salary-notices?tab=nosi')
            ->assertInertia(fn (Assert $page) => $page->where('due', fn ($rows) => collect($rows)->pluck('due', 'name')->all() === [
                'Short Leave' => '2027-01-03',
                'Long Leave' => '2027-01-23',
            ]));
    }

    public function test_nobody_prepares_their_own_notice(): void
    {
        $hr = $this->hr();
        $hr->employee()->create(['employee_number' => 'HR-1']);
        $period = $this->holderFor($hr->employee, 'Human Resource Management Officer', 15, '2024-01-03', 1);

        $this->actingAs($hr)->post('/admin/hr/salary-notices/nosi', [
            'appointment_id' => $period->id, 'record' => true, 'effective_date' => '2027-01-03', 'notice_date' => '2027-01-15', 'signatory' => 'director_iv',
        ])->assertForbidden();
        $this->assertSame(0, SalaryNotice::query()->count());

        $own = SalaryNotice::factory()->create(['employee_id' => $hr->employee->id]);
        $this->actingAs($hr)->delete("/admin/hr/salary-notices/{$own->id}")->assertForbidden();
    }

    public function test_a_signed_copy_goes_to_the_201_file_and_the_notice_can_no_longer_be_deleted(): void
    {
        $notice = SalaryNotice::factory()->create(['employee_id' => $this->holder('Clerk', 15, 'Rey Albert', 'Uy', [], '2024-01-01', 1)->id]);
        $hr = $this->hr();

        $this->actingAs($hr)->post("/admin/hr/salary-notices/{$notice->id}/file", ['file' => UploadedFile::fake()->create('signed.pdf', 50, 'application/pdf')])
            ->assertRedirect()->assertSessionHas('status');

        $notice->refresh();
        $this->assertTrue($notice->isFiled());
        $this->assertSame(EmployeeDocumentType::SalaryAdjustment, $notice->document->document_type);
        $this->assertSame('Notice of Salary Adjustment effective January 1, 2027', $notice->document->title);
        Storage::disk('local')->assertExists($notice->document->file_path);

        $this->actingAs($hr)->delete("/admin/hr/salary-notices/{$notice->id}")->assertSessionHasErrors('notice');
        $this->assertNotNull($notice->fresh());
    }

    public function test_many_signed_copies_are_matched_by_file_name(): void
    {
        $uy = SalaryNotice::factory()->create(['employee_id' => $this->holder('Clerk', 15, 'Rey Albert', 'Uy', [], '2024-01-01', 1)->id]);
        $aguillon = SalaryNotice::factory()->nosi()->create(['employee_id' => $this->holder('Attorney V', 15, 'Otelia Fe', 'Aguillon', [], '2024-01-01', 1)->id]);

        $this->actingAs($this->hr())->post('/admin/hr/salary-notices/file', ['files' => [
            UploadedFile::fake()->create('NOSA_Uy, Rey Albert .pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('nosi_aguillon,  otelia fe.pdf', 50, 'application/pdf'),
            UploadedFile::fake()->create('NOSA_Nobody, Here.pdf', 50, 'application/pdf'),
        ]])->assertRedirect()->assertSessionHas('status', fn (string $message) => str_contains($message, '2 signed notices filed') && str_contains($message, 'NOSA_Nobody, Here.pdf'));

        $this->assertTrue($uy->fresh()->isFiled());
        $this->assertTrue($aguillon->fresh()->isFiled());
    }

    public function test_the_print_page_words_the_notice_and_logs_the_access(): void
    {
        $employee = $this->holder('Attorney V', 25, 'Otelia Fe', 'Aguillon', [], '2024-01-01', 1);
        $notice = SalaryNotice::factory()->create([
            'employee_id' => $employee->id, 'addressee' => 'Otelia Fe C. Aguillon', 'salary_grade' => 25, 'position_title' => 'Attorney V',
            'from_salary' => 107208, 'to_salary' => 111727, 'effective_date' => '2027-01-01', 'notice_date' => '2027-01-15',
        ]);

        $this->actingAs($this->hr())->get("/admin/hr/salary-notices/print?ids[]={$notice->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('HR/SalaryNoticePrint')
                ->where('title', 'NOSA_Aguillon, Otelia Fe')
                ->where('notices.0.addressee', 'OTELIA FE C. AGUILLON')
                ->where('notices.0.effective', '01 January 2027')
                ->where('notices.0.as_of', '31 December 2026')
                ->where('notices.0.difference', '4,519.00')
                ->where('notices.0.signatory_name', 'ATTY. JUANA DELA CRUZ'));

        $this->assertTrue(AuditLog::query()->where('event', 'salary_notice.printed')->exists());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Grade 15 (and 25, 26, 28 for the other items) at $base + 10 per step.
     *
     * @param  array<string, mixed>  $extra
     */
    private function schedule(string $name, string $effective, int $base, array $extra = []): SalarySchedule
    {
        $schedule = SalarySchedule::query()->create(['name' => $name, 'effective_date' => $effective, ...$extra]);

        foreach ([15, 25, 26, 28] as $grade) {
            foreach (range(1, 8) as $step) {
                $schedule->rates()->create(['salary_grade' => $grade, 'step' => $step, 'monthly_salary' => $base + ($grade - 15) * 1000 + ($step - 1) * 10]);
            }
        }

        return $schedule;
    }

    /**
     * Someone holding a new item from $from at $step.
     *
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $period
     */
    private function holder(string $title, int $grade, string $first, string $last, array $user, string $from, int $step, array $period = []): Employee
    {
        $employee = Employee::factory()->create();
        $employee->user->forceFill(['first_name' => $first, 'last_name' => $last, 'middle_name' => null, ...$user])->save();
        $this->holderFor($employee, $title, $grade, $from, $step, $period);

        return $employee->fresh('user');
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function holderFor(Employee $employee, string $title, int $grade, string $from, int $step, array $period = []): PlantillaAppointment
    {
        $item = PlantillaItem::query()->create(['item_number' => strtoupper(substr($title, 0, 4)).'-'.$employee->id, 'position_title' => $title, 'salary_grade' => $grade, 'level' => 'second']);
        $appointment = new PlantillaAppointment(['step' => $step, 'status' => 'permanent', 'nature' => 'original', 'date_from' => $from, ...$period]);
        $appointment->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

        return $appointment;
    }

    private function withoutPay(Employee $employee, string $from, float $days): void
    {
        $leave = new LeaveApplication;
        $leave->forceFill([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'),
            'date_from' => $from,
            'date_to' => $from,
            'days' => $days,
            'days_without_pay' => $days,
            'status' => 'approved',
        ])->save();
    }

    private function hr(): User
    {
        return $this->hr ??= $this->userWith(['salary_notices.manage', 'plantilla.manage', 'employee_documents.manage', 'employees.view']);
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
