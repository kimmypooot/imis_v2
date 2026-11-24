<?php

namespace Tests\Feature\HR;

use App\Enums\PayComponentKind;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\PayComponent;
use App\Models\User;
use App\Support\HR\Payroll\PayrollWorkbook;
use Database\Seeders\PayComponentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PayrollWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(): PayrollWorkbook
    {
        return app(PayrollWorkbook::class);
    }

    public function test_a_three_row_block_is_read_with_its_mandatory_shares_and_other_deductions(): void
    {
        $rows = [
            10 => ['B' => 'NAME'],
            15 => ['B' => 'REYES,', 'D' => '60000'],
            16 => ['B' => 'ANA B.', 'C' => 'DIV', 'D' => '2000', 'E' => '62000', 'F' => '5400', 'N' => '1500', 'Q' => '200', 'U' => '9000', 'W' => '1000', 'AD' => '44900'],
            17 => ['J' => '655.56', 'K' => '0'],
        ];

        $employee = $this->workbook()->employees(['Final Payroll' => $rows])->sole();

        $this->assertSame('REYES', $employee['surname']);
        $this->assertSame('ANA B.', $employee['first_name']);
        $this->assertSame(60000.0, $employee['basic']);
        $this->assertSame(5400.0, $employee['gsis_ps']);
        $this->assertSame(9000.0, $employee['withholding_tax']);
        $this->assertSame(['Withholding Tax' => 9000.0, 'Welfare Fund Contribution' => 1000.0, 'GSIS Emergency Loan' => 655.56], $employee['other_deductions']);
        $this->assertSame(44244.44, $employee['net_recomputed']);
        $this->assertSame(44900.0, $employee['net_in_workbook']);
    }

    public function test_a_name_wrapped_after_the_first_word_and_a_gross_on_the_salary_row_are_read(): void
    {
        $rows = [
            544 => ['B' => 'BANOYO, KIM', 'D' => '36125', 'AD' => '28792.84'],
            545 => ['B' => 'BENEDICK M.', 'D' => '2000', 'E' => '38125', 'F' => '3251.25'],
        ];

        $employee = $this->workbook()->employees(['final payroll' => $rows])->sole();

        $this->assertSame('BANOYO', $employee['surname']);
        $this->assertSame('KIM BENEDICK M.', $employee['first_name']);
        $this->assertSame(38125.0, $employee['gross']);
        $this->assertSame(28792.84, $employee['net_in_workbook']);
    }

    public function test_a_block_with_its_deductions_on_the_salary_row_is_read_too(): void
    {
        $rows = [
            417 => ['B' => 'LAMPAYA,', 'D' => '19716', 'E' => '21716', 'F' => '1774.44', 'N' => '492.9', 'Q' => '200', 'W' => '300', 'Y' => '50', 'AD' => '15817.64'],
            418 => ['B' => 'MA. DESIREE D.', 'D' => '2000', 'U' => '3081.02'],
        ];

        $employee = $this->workbook()->employees(['Final Payroll' => $rows])->sole();

        $this->assertSame('LAMPAYA', $employee['surname']);
        $this->assertSame('MA. DESIREE D.', $employee['first_name']);
        $this->assertSame(2000.0, $employee['pera']);
        $this->assertSame(1774.44, $employee['gsis_ps']);
        $this->assertSame(3081.02, $employee['withholding_tax']);
        $this->assertSame(15817.64, round($employee['net_recomputed'], 2));
    }

    public function test_totals_and_headings_are_not_taken_for_employees(): void
    {
        $rows = [
            39 => ['D' => '1000'],
            40 => ['B' => 'T O T A L', 'D' => '696895', 'E' => '696895', 'F' => '61820.55'],
        ];

        $this->assertTrue($this->workbook()->employees(['Final Payroll' => $rows])->isEmpty());
    }

    public function test_a_workbook_without_the_final_payroll_sheet_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->workbook()->employees(['Payslip' => []]);
    }

    public function test_applying_saves_items_and_loans_once_and_leaves_what_hr_already_has(): void
    {
        $this->seed(PayComponentSeeder::class);
        $reyes = Employee::factory()->for(User::factory()->state(['first_name' => 'Ana', 'last_name' => 'Reyes']))->create();
        $rows = collect([[
            'surname' => 'REYES', 'first_name' => 'ANA B.', 'hdmf_ps' => 1000.0,
            'other_deductions' => ['Withholding Tax' => 9000.0, 'Withholding Tax (second line)' => 500.0, 'GSIS MPL' => 6169.17, 'Welfare Fund Contribution' => 1000.0],
        ]]);

        $preview = $this->workbook()->apply($rows, '2026-11', false);
        $this->assertSame(0, EmployeeLoan::query()->count(), 'a preview saves nothing');
        $this->assertSame(0, EmployeePayItem::query()->count());
        $this->assertSame(3, $preview['items']);
        $this->assertSame(1, $preview['loans']);

        $saved = $this->workbook()->apply($rows, '2026-11');

        $this->assertSame(1, $saved['loans']);
        $tax = EmployeePayItem::query()->whereHas('component', fn ($query) => $query->where('code', 'WTAX'))->sole();
        $this->assertSame(9500.0, (float) $tax->amount, 'the second line adds to the first');
        $this->assertSame('2026-11-30', $tax->effective_to->toDateString(), 'tax does not carry forward');
        $this->assertNull(EmployeePayItem::query()->whereHas('component', fn ($query) => $query->where('code', 'WF'))->sole()->effective_to);
        $this->assertSame(1000.0, (float) EmployeePayItem::query()->whereHas('component', fn ($query) => $query->where('code', 'HDMF'))->sole()->amount, 'a Pag-IBIG election above P200');
        $loan = EmployeeLoan::query()->sole();
        $this->assertSame(6169.17, (float) $loan->monthly_amortization);
        $this->assertSame(PayComponentKind::Loan, $loan->component->kind);
        $this->assertSame($reyes->id, $loan->employee_id);

        $again = $this->workbook()->apply($rows, '2026-11');

        $this->assertSame(0, $again['items'] + $again['loans'], 'running it twice saves nothing more');
        $this->assertCount(4, $again['skipped']);
        $this->assertSame(1, EmployeeLoan::query()->count());
        $this->assertNotNull(PayComponent::query()->where('code', 'GSIS-MPL')->first());
    }
}
