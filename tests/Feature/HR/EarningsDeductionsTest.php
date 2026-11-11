<?php

namespace Tests\Feature\HR;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\OrganizationalUnit;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\HR\Payroll\DeductionNotices;
use App\Support\HR\Payroll\Loans;
use App\Support\HR\Payroll\Payrolls;
use App\Support\HR\Plantilla\Plantilla;
use Database\Seeders\PayComponentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-8 extension: amount overrides that keep the configured amount, the
 * loan installment schedule and its statuses, loan lifecycle notices,
 * cancelled payslips, and the employee's own Earnings & Deductions page.
 */
class EarningsDeductionsTest extends TestCase
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
        $schedule->rates()->createMany([['salary_grade' => 15, 'step' => 1, 'monthly_salary' => 30000]]);
    }

    public function test_an_override_keeps_the_configured_amount_and_records_who_and_why(): void
    {
        $employee = $this->staff();
        $loan = $this->loan($employee, total: 12000, amortization: 1500);
        $hr = $this->hr();
        $run = $this->payroll('2026-10', $hr);
        $entry = $run->entries()->sole();
        $line = $entry->lines->firstWhere('employee_loan_id', $loan->id);

        $this->actingAs($hr)->put("/admin/hr/payroll/runs/{$run->id}/entries/{$entry->id}/lines/{$line->id}", ['amount' => 1652.83, 'reason' => 'Revised by GSIS'])
            ->assertRedirect();

        $line->refresh();
        $this->assertSame(1652.83, (float) $line->amount);
        $this->assertSame(1500.0, (float) $line->original_amount, 'the configured amount is kept');
        $this->assertSame($hr->id, $line->adjusted_by);
        $this->assertNotNull($line->adjusted_at);
        $this->assertSame('Revised by GSIS', $line->adjust_reason);
        $this->assertSame(1500.0, (float) $loan->fresh()->monthly_amortization, 'the loan itself is untouched');
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::PayrollAdjusted->value)->where('metadata->reason', 'Revised by GSIS')->exists());

        // A second override keeps the very first configured amount; going back clears it.
        $this->actingAs($hr)->put("/admin/hr/payroll/runs/{$run->id}/entries/{$entry->id}/lines/{$line->id}", ['amount' => 1700]);
        $this->assertSame(1500.0, (float) $line->fresh()->original_amount);
        $this->actingAs($hr)->put("/admin/hr/payroll/runs/{$run->id}/entries/{$entry->id}/lines/{$line->id}", ['amount' => 1500]);
        $this->assertNull($line->fresh()->original_amount);
    }

    public function test_the_schedule_moves_from_scheduled_to_reflected_confirmed_paid_and_overdue(): void
    {
        $employee = $this->staff();
        $loan = $this->loan($employee, total: 4000, amortization: 1000, start: '2026-09-01');
        $loans = app(Loans::class);
        $hr = $this->hr();

        $rows = fn () => collect($loans->schedule($loan->fresh())['rows'])->keyBy('month');
        $this->assertSame('scheduled', $rows()['2026-10']['status']);
        $this->assertSame(4, $rows()->count());
        $this->assertSame('2027-01', $loans->schedule($loan->fresh())['expected_end']);

        $run = $this->payroll('2026-10', $hr);
        $this->assertSame('reflected', $rows()['2026-10']['status']);

        app(Payrolls::class)->finalize($run, $hr);
        $this->assertSame('confirmed', $rows()['2026-10']['status']);
        $this->assertSame('scheduled', $rows()['2026-11']['status'], 'the months after follow on');

        $line = $run->entries()->sole()->lines->firstWhere('employee_loan_id', $loan->id);
        $this->actingAs($hr)->post("/admin/hr/payroll/profiles/{$employee->id}/loans/{$loan->id}/lines/{$line->id}/remit", ['paid' => true])->assertRedirect();
        $this->assertSame('paid', $rows()['2026-10']['status']);
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::LoanRemitted->value)->exists());

        $this->actingAs($hr)->post("/admin/hr/payroll/profiles/{$employee->id}/loans/{$loan->id}/lines/{$line->id}/remit", ['paid' => false]);
        $this->travelTo('2026-12-15 09:00:00');
        $this->assertSame('overdue', $rows()['2026-10']['status'], 'confirmed, but never marked paid');
    }

    public function test_a_draft_deduction_cannot_be_marked_paid_and_a_cancelled_loan_stops(): void
    {
        $employee = $this->staff();
        $loan = $this->loan($employee, total: 4000, amortization: 1000);
        $hr = $this->hr();
        $run = $this->payroll('2026-10', $hr);
        $line = $run->entries()->sole()->lines->firstWhere('employee_loan_id', $loan->id);

        $this->actingAs($hr)->post("/admin/hr/payroll/profiles/{$employee->id}/loans/{$loan->id}/lines/{$line->id}/remit", ['paid' => true])
            ->assertSessionHasErrors('line');

        $this->actingAs($hr)->post("/admin/hr/payroll/profiles/{$employee->id}/loans/{$loan->id}/cancel", [])->assertSessionHasErrors('reason');
        $this->actingAs($hr)->post("/admin/hr/payroll/profiles/{$employee->id}/loans/{$loan->id}/cancel", ['reason' => 'Loan was refinanced'])->assertRedirect();

        $loan->refresh();
        $this->assertNotNull($loan->cancelled_on);
        $this->assertSame('cancelled', app(Loans::class)->present($loan)['status']);
        $this->assertEmpty(collect(app(Loans::class)->schedule($loan)['rows'])->where('status', 'scheduled'));
        $this->actingAs($hr)->post("/admin/hr/payroll/profiles/{$employee->id}/loans/{$loan->id}/resume")->assertSessionHasErrors('loan');
        $this->assertSame(0.0, app(Loans::class)->amortizationFor($loan, today()->addMonths(2)->startOfMonth(), 3000.0), 'a cancelled loan deducts nothing after it stopped');
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::LoanCancelled->value)->where('metadata->reason', 'Loan was refinanced')->exists());
    }

    public function test_employees_are_told_about_their_recurring_deductions(): void
    {
        Notification::fake();
        $employee = $this->staff();
        $hr = $this->hr();
        $base = "/admin/hr/payroll/profiles/{$employee->id}/loans";
        $payload = [
            'pay_component_id' => PayComponent::query()->where('code', 'GSIS-CONSO')->value('id'),
            'principal' => 3000, 'total_payable' => 3000, 'monthly_amortization' => 1000, 'start_month' => '2026-10',
        ];

        $this->actingAs($hr)->post($base, $payload)->assertRedirect();
        $loan = $employee->loans()->sole();
        Notification::assertSentTo($employee->user, HrUpdate::class, fn ($n) => $n->toArray($employee->user)['title'] === 'New recurring deduction');

        $this->actingAs($hr)->put("{$base}/{$loan->id}", [...$payload, 'monthly_amortization' => 1200, 'change_reason' => 'Restructured']);
        Notification::assertSentTo($employee->user, HrUpdate::class, fn ($n) => $n->toArray($employee->user)['title'] === 'Deduction amount changed');

        $this->actingAs($hr)->put("{$base}/{$loan->id}", [...$payload, 'monthly_amortization' => 1200]); // unchanged amount: no second notice
        Notification::assertSentToTimes($employee->user, HrUpdate::class, 2);

        $this->actingAs($hr)->post("{$base}/{$loan->id}/stop", ['stopped_on' => '2026-10-31']);
        Notification::assertSentTo($employee->user, HrUpdate::class, fn ($n) => $n->toArray($employee->user)['title'] === 'Deduction suspended');

        $this->actingAs($hr)->post("{$base}/{$loan->id}/resume");
        $run = $this->payroll('2026-10', $hr);
        app(Payrolls::class)->finalize($run, $hr);
        app(DeductionNotices::class)->afterFinalize($run);
        Notification::assertSentTo($employee->user, HrUpdate::class, fn ($n) => $n->toArray($employee->user)['title'] === 'Deduction reflected in your payslip');
    }

    public function test_the_ending_soon_notice_goes_out_once(): void
    {
        Notification::fake();
        $employee = $this->staff();
        $this->loan($employee, total: 2000, amortization: 1000);

        $this->artisan('imis:notify-loan-endings')->assertSuccessful();
        $this->artisan('imis:notify-loan-endings')->assertSuccessful();

        Notification::assertSentToTimes($employee->user, HrUpdate::class, 1);
    }

    public function test_a_cancelled_payslip_counts_for_nothing_and_needs_a_reason(): void
    {
        $employee = $this->staff();
        $loan = $this->loan($employee, total: 3000, amortization: 1000);
        $hr = $this->hr();
        $run = $this->payroll('2026-10', $hr);
        $entry = $run->entries()->sole();
        app(Payrolls::class)->finalize($run, $hr);
        $this->assertSame(2000.0, app(Loans::class)->balance($loan));

        $this->actingAs($hr)->post("/admin/hr/payroll/runs/{$run->id}/entries/{$entry->id}/cancel", [])->assertSessionHasErrors('reason');
        $this->actingAs($hr)->post("/admin/hr/payroll/runs/{$run->id}/entries/{$entry->id}/cancel", ['reason' => 'Issued in error'])->assertRedirect();

        $this->assertTrue($entry->fresh()->isCancelled());
        $this->assertSame(3000.0, app(Loans::class)->balance($loan), 'its deduction no longer counts as paid');
        $this->assertSame(0, app(Payrolls::class)->totals($run)['people']);
        $this->actingAs($employee->user)->get("/payslips/{$entry->id}")->assertForbidden();
        $this->actingAs($hr)->get("/payslips/{$entry->id}")->assertOk();
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::PayslipCancelled->value)->exists());
    }

    public function test_reopening_a_finalized_payroll_needs_a_reason_that_is_audited(): void
    {
        $this->staff();
        $hr = $this->hr();
        $run = $this->payroll('2026-10', $hr);
        app(Payrolls::class)->finalize($run, $hr);

        $this->actingAs($hr)->post("/admin/hr/payroll/runs/{$run->id}/reopen", [])->assertSessionHasErrors('reason');
        $this->actingAs($hr)->post("/admin/hr/payroll/runs/{$run->id}/reopen", ['reason' => 'Wrong tax'])->assertRedirect();

        $this->assertSame('Wrong tax', $run->fresh()->reopen_reason);
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::PayrollReopened->value)->where('metadata->reason', 'Wrong tax')->exists());
    }

    public function test_my_earnings_shows_only_your_own_finalized_figures(): void
    {
        $employee = $this->staff();
        $other = $this->staff();
        $this->loan($employee, total: 3000, amortization: 1000);
        $this->loan($other, total: 9000, amortization: 500);
        $hr = $this->hr();
        $run = $this->payroll('2026-10', $hr);

        $this->actingAs($employee->user)->get('/my/earnings')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyEarnings')->where('latest', null)->has('loans', 1)->has('history', 0));

        app(Payrolls::class)->finalize($run, $hr);

        $this->actingAs($employee->user)->get('/my/earnings')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('latest.period', 'October 2026')
                ->where('latest.basic_salary', 30000)
                ->where('latest.total_earnings', 32000)
                ->has('latest.deductions.mandatory', 3)
                ->has('latest.deductions.government_loans', 1)
                ->where('loans.0.monthly_amortization', 1000)
                ->where('loans.0.balance', 2000)
                ->missing('loans.0.remarks')
                ->has('history', 1)
                ->where('history.0.payslip_ready', false));

        $this->actingAs(User::factory()->create())->get('/my/earnings')->assertForbidden();
    }

    public function test_a_promotion_pays_the_new_grade_from_its_date_and_past_months_keep_the_old_one(): void
    {
        SalarySchedule::query()->sole()->rates()->create(['salary_grade' => 24, 'step' => 1, 'monthly_salary' => 110000]);
        $employee = $this->staff();
        $old = $employee->plantillaAppointments()->sole();
        $old->forceFill(['date_to' => '2026-10-15', 'end_reason' => 'Promotion'])->save();

        $item = PlantillaItem::query()->create(['position_title' => 'Chief Human Resource Specialist', 'salary_grade' => 24, 'organizational_unit_id' => $this->hrd->id]);
        (new PlantillaAppointment(['step' => 1, 'status' => 'permanent', 'nature' => 'promotion', 'date_from' => '2026-10-16']))
            ->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

        $hr = $this->hr();
        $october = $this->payroll('2026-10', $hr)->entries()->sole();
        $this->assertSame('Chief Human Resource Specialist', $october->position);
        $this->assertSame(24, $october->salary_grade);
        $this->assertSame(71290.32, (float) $october->basic_salary); // 15 days of 30,000 + 16 of 110,000, over 31

        $september = $this->payroll('2026-09', $hr)->entries()->sole();
        $this->assertSame(15, $september->salary_grade);
        $this->assertSame(30000.0, (float) $september->basic_salary);

        $november = $this->payroll('2026-11', $hr)->entries()->sole();
        $this->assertSame(110000.0, (float) $november->basic_salary);
    }

    public function test_a_salary_override_on_the_appointment_replaces_the_schedule_amount(): void
    {
        // Step 3 has no amount in the test schedule, so it would price blank.
        $employee = $this->staff();
        $period = $employee->plantillaAppointments()->sole();
        $period->forceFill(['step' => 3])->save();
        $this->assertEmpty($this->payroll('2026-09', $this->hr())->entries);

        $item = $period->item;
        $hr = $this->hr();
        $role = $hr->roles()->first();
        foreach (['plantilla.manage', 'plantilla.correct'] as $name) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name]));
        }
        $this->actingAs($hr)->put("/admin/hr/plantilla/{$item->id}/appointments/{$period->id}", [
            'step' => 3, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2020-01-01', 'salary_override' => 28500.5,
        ])->assertSessionHasNoErrors();

        $this->assertSame(28500.5, app(Plantilla::class)->presentAppointment($period->fresh()->load(['item', 'employee.user']))['monthly_salary']);
        $entry = $this->payroll('2026-10', $hr)->entries()->sole();
        $this->assertSame(28500.5, (float) $entry->basic_salary);
    }

    public function test_only_permanent_employees_are_on_the_payroll(): void
    {
        $permanent = $this->staff();
        $temporary = $this->staff();
        $temporary->plantillaAppointments()->sole()->forceFill(['status' => 'temporary'])->save();

        $run = $this->payroll('2026-10', $this->hr());

        $this->assertSame([$permanent->id], $run->entries()->pluck('employee_id')->all());
        $this->assertNotContains($temporary->id, app(Payrolls::class)->leftOut($run)->pluck('employee_id')->all());
    }

    private function payroll(string $month, User $by): PayrollRun
    {
        return app(Payrolls::class)->create($month, null, $by);
    }

    private function staff(): Employee
    {
        $employee = Employee::factory()->create();
        $item = PlantillaItem::query()->create(['position_title' => 'Administrative Officer IV', 'salary_grade' => 15, 'organizational_unit_id' => $this->hrd->id]);
        (new PlantillaAppointment(['step' => 1, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2020-01-01']))
            ->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

        return $employee;
    }

    private function loan(Employee $employee, float $total, float $amortization, string $start = '2026-01-01'): EmployeeLoan
    {
        return $employee->loans()->create([
            'pay_component_id' => PayComponent::query()->where('code', 'GSIS-CONSO')->value('id'),
            'principal' => $total,
            'total_payable' => $total,
            'monthly_amortization' => $amortization,
            'start_month' => $start,
            'paid_before' => 0,
        ]);
    }

    private function hr(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        foreach (['payroll.view', 'payroll.manage'] as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }
        $user->roles()->attach($role);

        return $user;
    }
}
