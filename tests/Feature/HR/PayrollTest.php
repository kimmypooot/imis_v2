<?php

namespace Tests\Feature\HR;

use App\Enums\AuditEvent;
use App\Enums\PayslipRequestStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeLoan;
use App\Models\OrganizationalUnit;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\HR\Payroll\Loans;
use App\Support\HR\Payroll\Payrolls;
use Database\Seeders\PayComponentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-8: payroll — generating a month from plantilla, pay profiles and
 * loans; HR's adjustments; finalizing; loan balances; and payslips that
 * employees request and the head of HRD approves. Amounts are made up.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $hrd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 09:00:00');
        $this->hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD', 'code' => 'HRD']);
        $this->seed(PayComponentSeeder::class);

        $schedule = SalarySchedule::query()->create(['name' => 'Test schedule', 'reference' => 'Test', 'effective_date' => '2026-01-01']);
        $schedule->rates()->createMany([
            ['salary_grade' => 15, 'step' => 1, 'monthly_salary' => 30000],
            ['salary_grade' => 24, 'step' => 1, 'monthly_salary' => 110000],
            ['salary_grade' => 1, 'step' => 1, 'monthly_salary' => 8000],
        ]);
    }

    public function test_a_payroll_computes_basic_pay_automatic_items_the_profile_and_loans(): void
    {
        $employee = $this->staff('Administrative Officer IV', 15);
        $this->profileItem($employee, 'WTAX', 1234.56);
        $loan = $this->loan($employee, total: 3000, amortization: 1000, paidBefore: 1500);

        $entry = $this->payroll('2026-10')->entries()->sole();

        $this->assertSame(30000.0, (float) $entry->basic_salary);
        $this->assertSame([
            ['earning', 'PERA', 2000.0],
            ['deduction', 'GSIS Personal Share', 2700.0],   // 9% of 30,000
            ['deduction', 'PhilHealth', 750.0],             // 2.5% of 30,000
            ['deduction', 'Pag-IBIG', 200.0],               // 2% of at most 10,000
            ['deduction', 'Withholding Tax', 1234.56],      // from the profile
            ['deduction', 'GSIS Consolidated Loan', 1000.0], // the amortization
        ], $entry->lines->map(fn ($line) => [$line->kind, $line->label, (float) $line->amount])->all());

        $this->assertSame(32000.0, (float) $entry->gross_pay);
        $this->assertSame(5884.56, (float) $entry->total_deductions);
        $this->assertSame(26115.44, (float) $entry->net_pay);
        $this->assertSame(13057.72, (float) $entry->first_half);
        $this->assertSame(13057.72, (float) $entry->second_half);
        $this->assertSame(1500.0, app(Loans::class)->balance($loan), 'a draft pays nothing yet');
    }

    public function test_percent_items_respect_the_floor_and_ceiling_and_halves_share_an_odd_centavo(): void
    {
        $low = $this->staff('Utility Worker', 1);      // 8,000: PhilHealth on the 10,000 floor
        $high = $this->staff('Chief Human Resource Specialist', 24); // 110,000: on the 100,000 ceiling

        $run = $this->payroll('2026-10');
        $lines = fn (Employee $employee) => $run->entries()->where('employee_id', $employee->id)->sole()->lines->pluck('amount', 'label')->map(fn ($amount) => (float) $amount);

        $this->assertSame(250.0, $lines($low)['PhilHealth']);
        $this->assertSame(160.0, $lines($low)['Pag-IBIG'], '2% of 8,000, under the cap');
        $this->assertSame(2500.0, $lines($high)['PhilHealth']);
        $this->assertSame(200.0, $lines($high)['Pag-IBIG']);

        // Net 8,000 + 2,000 − 720 − 250 − 160 = 8,870.00; add a centavo to make it odd.
        $entry = $run->entries()->where('employee_id', $low->id)->sole();
        app(Payrolls::class)->addLine($entry, ['kind' => 'earning', 'label' => 'Rounding', 'amount' => 0.01]);
        $entry->refresh();
        $this->assertSame(8870.01, (float) $entry->net_pay);
        $this->assertSame(4435.0, (float) $entry->first_half);
        $this->assertSame(4435.01, (float) $entry->second_half);
    }

    public function test_a_profile_amount_overrides_an_automatic_item_and_zero_exempts(): void
    {
        $employee = $this->staff('Administrative Officer IV', 15);
        $this->profileItem($employee, 'PERA', 0);
        $this->profileItem($employee, 'HDMF', 500); // voluntary higher Pag-IBIG

        $labels = $this->payroll('2026-10')->entries()->sole()->lines->pluck('amount', 'label')->map(fn ($amount) => (float) $amount);

        $this->assertArrayNotHasKey('PERA', $labels->all());
        $this->assertSame(500.0, $labels['Pag-IBIG']);
    }

    public function test_loans_count_finalized_payrolls_and_stop_when_paid(): void
    {
        $employee = $this->staff('Administrative Officer IV', 15);
        $loan = $this->loan($employee, total: 1500, amortization: 1000);
        $hr = $this->hr();
        $payrolls = app(Payrolls::class);

        $october = $this->payroll('2026-10');
        $payrolls->finalize($october, $hr);
        $this->assertSame(500.0, app(Loans::class)->balance($loan));

        $november = $this->payroll('2026-11');
        $this->assertSame(500.0, (float) $november->entries()->sole()->lines->firstWhere('employee_loan_id', $loan->id)->amount, 'only what is left');
        $payrolls->finalize($november, $hr);
        $this->assertSame(0.0, app(Loans::class)->balance($loan));

        $december = $this->payroll('2026-12');
        $this->assertNull($december->entries()->sole()->lines->firstWhere('employee_loan_id', $loan->id));

        // Reopening October un-pays it: the balance follows.
        $payrolls->reopen($october);
        $this->assertSame(1000.0, app(Loans::class)->balance($loan));
    }

    public function test_only_staff_with_a_priced_complete_period_are_on_it_and_the_rest_are_listed(): void
    {
        $paid = $this->staff('Administrative Officer IV', 15);
        $incomplete = Employee::factory()->create();
        $item = PlantillaItem::query()->create(['position_title' => 'Administrative Officer IV', 'salary_grade' => 15, 'organizational_unit_id' => $this->hrd->id]);
        (new PlantillaAppointment)->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $incomplete->id])->save();

        $run = $this->payroll('2026-10');

        $this->assertSame([$paid->id], $run->entries()->pluck('employee_id')->all());
        $this->assertStringContainsString('incomplete', app(Payrolls::class)->leftOut($run)->firstWhere('employee_id', $incomplete->id)['reason']);
    }

    public function test_hr_adjusts_a_draft_but_never_their_own_pay_and_not_once_finalized(): void
    {
        $employee = $this->staff('Administrative Officer IV', 15);
        $hr = $this->hr();
        $hrEmployee = Employee::factory()->for($hr)->create();
        $this->appoint($hrEmployee, 'Administrative Officer IV', 15);
        $run = $this->payroll('2026-10');
        $entry = $run->entries()->where('employee_id', $employee->id)->sole();
        $own = $run->entries()->where('employee_id', $hrEmployee->id)->sole();

        $this->actingAs($hr)->post("/admin/hr/payroll/runs/{$run->id}/entries/{$entry->id}/lines", ['kind' => 'deduction', 'label' => 'Absences (2 days)', 'amount' => 2727.27])
            ->assertRedirect();
        $this->assertSame(25622.73, (float) $entry->fresh()->net_pay); // 32,000 − 2,700 − 750 − 200 − 2,727.27
        $this->assertTrue($entry->fresh()->lines->firstWhere('label', 'Absences (2 days)')->adjusted);

        $this->actingAs($hr)->post("/admin/hr/payroll/runs/{$run->id}/entries/{$own->id}/lines", ['kind' => 'earning', 'label' => 'Bonus', 'amount' => 99999])
            ->assertForbidden();

        $this->actingAs($hr)->post("/admin/hr/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $line = $entry->fresh()->lines->first();
        $this->actingAs($hr)->put("/admin/hr/payroll/runs/{$run->id}/entries/{$entry->id}/lines/{$line->id}", ['amount' => 1])
            ->assertSessionHasErrors('run');
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::PayrollFinalized->value)->exists());
    }

    public function test_payroll_pages_need_payroll_permissions(): void
    {
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->get('/admin/hr/payroll')->assertForbidden();

        $viewer = $this->userWith(['payroll.view']);
        $this->actingAs($viewer)->get('/admin/hr/payroll')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Payroll/Index')->where('canManage', false));
        $this->actingAs($viewer)->post('/admin/hr/payroll/runs', ['month' => '2026-10'])->assertForbidden();
    }

    public function test_an_employee_requests_a_payslip_the_head_of_hrd_approves_and_then_they_can_print_it(): void
    {
        Notification::fake();
        $employee = $this->staff('Administrative Officer IV', 15);
        $chief = $this->staff('Chief Human Resource Specialist', 24);
        $run = $this->payroll('2026-10');
        $entry = $run->entries()->where('employee_id', $employee->id)->sole();

        // Not before the payroll is final.
        $this->actingAs($employee->user)->post('/my/payslips', ['payroll_entry_id' => $entry->id])->assertSessionHasErrors('payroll_entry_id');
        app(Payrolls::class)->finalize($run, $this->hr());

        $this->actingAs($employee->user)->post('/my/payslips', ['payroll_entry_id' => $entry->id, 'purpose' => 'Bank loan'])->assertRedirect();
        $request = PayslipRequest::query()->sole();
        $this->assertSame(PayslipRequestStatus::Pending, $request->status);
        Notification::assertSentTo($chief->user, HrUpdate::class);
        $this->actingAs($employee->user)->get("/payslips/{$entry->id}")->assertForbidden();

        // Only the head of HRD decides; never the requester.
        $this->actingAs($employee->user)->post("/payslips/approvals/{$request->id}", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->staff('Administrative Officer IV', 15)->user)->post("/payslips/approvals/{$request->id}", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($chief->user)->post("/payslips/approvals/{$request->id}", ['decision' => 'approved'])->assertRedirect();

        $this->assertTrue($request->fresh()->isApproved());
        Notification::assertSentTo($employee->user, HrUpdate::class);
        $this->actingAs($employee->user)->get("/payslips/{$entry->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('HR/PayslipPrint')->where('entry.net_pay', 28350)->where('request.approved_by', $chief->user->name));

        // Someone else's payslip stays closed, and payroll staff opening it is audited.
        $this->actingAs($chief->user)->get("/payslips/{$entry->id}")->assertForbidden();
        $this->actingAs($this->userWith(['payroll.view']))->get("/payslips/{$entry->id}")->assertOk();
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::PayslipViewed->value)->exists());

        // One open request per month.
        $this->actingAs($employee->user)->post('/my/payslips', ['payroll_entry_id' => $entry->id])->assertSessionHasErrors('payroll_entry_id');
    }

    public function test_a_designated_head_of_hrd_decides_for_the_chief_and_a_rejection_needs_remarks(): void
    {
        Notification::fake();
        $employee = $this->staff('Administrative Officer IV', 15);
        $chief = $this->staff('Chief Human Resource Specialist', 24);
        $oic = $this->staff('Administrative Officer IV', 15);
        EmployeeAssignment::query()->forceCreate([
            'employee_id' => $oic->id, 'kind' => 'designation', 'as_head' => true, 'to_unit_id' => $this->hrd->id,
            'effective_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);
        $run = $this->payroll('2026-10');
        app(Payrolls::class)->finalize($run, $this->hr());
        $entry = $run->entries()->where('employee_id', $employee->id)->sole();
        $this->actingAs($employee->user)->post('/my/payslips', ['payroll_entry_id' => $entry->id]);
        $request = PayslipRequest::query()->sole();

        $this->actingAs($oic->user)->get('/payslips/approvals')->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests', 1));
        $this->actingAs($oic->user)->post("/payslips/approvals/{$request->id}", ['decision' => 'rejected'])->assertSessionHasErrors('remarks');
        $this->actingAs($oic->user)->post("/payslips/approvals/{$request->id}", ['decision' => 'rejected', 'remarks' => 'Wrong month'])->assertRedirect();

        $request->refresh();
        $this->assertSame(PayslipRequestStatus::Rejected, $request->status);
        $this->assertSame($chief->user_id, $request->acting_for_id);

        // A rejected month can be asked for again, and a pending one cancelled.
        $this->actingAs($employee->user)->post('/my/payslips', ['payroll_entry_id' => $entry->id])->assertRedirect();
        $again = PayslipRequest::query()->where('status', 'pending')->sole();
        $this->actingAs($employee->user)->post("/my/payslips/{$again->id}/cancel")->assertRedirect();
        $this->assertSame(PayslipRequestStatus::Cancelled, $again->fresh()->status);
    }

    private function payroll(string $month): PayrollRun
    {
        return app(Payrolls::class)->create($month, null, $this->hr());
    }

    /** An employee holding a complete, priced plantilla period at HRD. */
    private function staff(string $title, int $grade): Employee
    {
        $employee = Employee::factory()->create();
        $this->appoint($employee, $title, $grade);

        return $employee;
    }

    private function appoint(Employee $employee, string $title, int $grade): void
    {
        $item = PlantillaItem::query()->create(['position_title' => $title, 'salary_grade' => $grade, 'organizational_unit_id' => $this->hrd->id]);
        (new PlantillaAppointment(['step' => 1, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2020-01-01']))
            ->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();
    }

    private function profileItem(Employee $employee, string $code, float $amount): void
    {
        $employee->payItems()->create([
            'pay_component_id' => PayComponent::query()->where('code', $code)->value('id'),
            'amount' => $amount,
            'effective_from' => '2026-01-01',
        ]);
    }

    private function loan(Employee $employee, float $total, float $amortization, float $paidBefore = 0): EmployeeLoan
    {
        return $employee->loans()->create([
            'pay_component_id' => PayComponent::query()->where('code', 'GSIS-CONSO')->value('id'),
            'principal' => $total,
            'total_payable' => $total,
            'monthly_amortization' => $amortization,
            'start_month' => '2026-01-01',
            'paid_before' => $paidBefore,
        ]);
    }

    private function hr(): User
    {
        return $this->userWith(['payroll.view', 'payroll.manage']);
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
