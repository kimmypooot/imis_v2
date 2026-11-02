<?php

namespace App\Http\Controllers;

use App\Enums\PayCategory;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\PayrollEntry;
use App\Models\PayrollLine;
use App\Models\PayslipRequest;
use App\Support\HR\Payroll\Loans;
use App\Support\HR\Payroll\PayProfiles;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8 self-service: "My Earnings & Deductions" — what your latest
 * finalized payslip took and gave, your recurring deductions with their
 * schedule, and your payslip history. Only ever your own record, and
 * never a draft payroll: figures appear once HR finalizes the month. The
 * printable payslip itself still follows the request-and-approval flow.
 */
class MyEarningsController extends Controller
{
    public function show(Request $request, Loans $loans, PayProfiles $profiles): Response
    {
        $employee = $request->user()->employee;
        abort_unless($employee instanceof Employee, 403, 'HR hasn\'t set up your employee record yet.');

        $entries = PayrollEntry::query()->with(['run', 'lines.component'])
            ->where('employee_id', $employee->id)->whereNull('cancelled_at')
            ->whereHas('run', fn ($query) => $query->where('status', 'finalized'))
            ->get()
            ->sortByDesc(fn (PayrollEntry $entry) => $entry->run->year * 100 + $entry->run->month)->values();

        $requests = PayslipRequest::query()->where('employee_id', $employee->id)
            ->whereIn('payroll_entry_id', $entries->pluck('id'))->latest('id')->get()->groupBy('payroll_entry_id');

        $latest = $entries->first();

        return Inertia::render('Portal/MyEarnings', [
            'latest' => $latest ? $this->presentLatest($latest) : null,
            'loans' => $loans->presentMany(EmployeeLoan::query()->with('component')->where('employee_id', $employee->id)->orderByDesc('start_month')->get(), withSchedule: true)
                ->map(fn (array $loan) => collect($loan)->except(['remarks', 'deletable', 'employee', 'employee_id', 'pay_component_id'])->all())
                ->values(),
            'items' => EmployeePayItem::query()->with('component')->where('employee_id', $employee->id)->orderByDesc('effective_from')->get()
                ->filter(fn (EmployeePayItem $item) => $item->effective_from->lte(today()) && (! $item->effective_to || $item->effective_to->gte(today())))
                ->map(fn (EmployeePayItem $item) => collect($profiles->presentItem($item))->only(['component', 'kind', 'amount', 'rule', 'effective_from', 'effective_to'])->all())
                ->values(),
            'history' => $entries->map(fn (PayrollEntry $entry) => [
                'id' => $entry->id,
                'period' => $entry->run->period,
                'gross_pay' => (float) $entry->gross_pay,
                'total_deductions' => (float) $entry->total_deductions,
                'net_pay' => (float) $entry->net_pay,
                'request_status' => $requests->get($entry->id)?->first()?->status->value,
                'payslip_ready' => $requests->get($entry->id)?->contains(fn (PayslipRequest $item) => $item->status->value === 'approved') ?? false,
            ])->values(),
        ]);
    }

    /**
     * Earnings (basic salary, PERA, others) and deductions (mandatory
     * contributions, government loans, other loans, the rest) of one
     * finalized payslip, each with its total.
     *
     * @return array<string, mixed>
     */
    private function presentLatest(PayrollEntry $entry): array
    {
        $line = fn (PayrollLine $line) => ['label' => $line->label, 'amount' => (float) $line->amount, 'adjusted' => $line->adjusted];
        $sum = fn (Collection $lines) => round((float) $lines->sum(fn (PayrollLine $line) => (float) $line->amount), 2);

        $earnings = $entry->lines->where('kind', 'earning');
        $pera = $earnings->filter(fn (PayrollLine $line) => $line->component?->code === 'PERA');
        $otherEarnings = $earnings->reject(fn (PayrollLine $line) => $line->component?->code === 'PERA');

        $deductions = $entry->lines->where('kind', 'deduction');
        $category = fn (PayrollLine $line) => $line->component?->category ?? PayCategory::Other;
        $mandatory = $deductions->filter(fn (PayrollLine $l) => $category($l) === PayCategory::Mandatory);
        $government = $deductions->filter(fn (PayrollLine $l) => $category($l) === PayCategory::GovernmentLoan);
        $recurring = $deductions->filter(fn (PayrollLine $l) => $category($l) === PayCategory::OtherLoan);
        $other = $deductions->reject(fn (PayrollLine $l) => in_array($category($l), [PayCategory::Mandatory, PayCategory::GovernmentLoan, PayCategory::OtherLoan], true));

        return [
            'period' => $entry->run->period,
            'basic_salary' => (float) $entry->basic_salary,
            'earnings' => [
                'pera' => $pera->map($line)->values(),
                'other' => $otherEarnings->map($line)->values(),
            ],
            'deductions' => [
                'mandatory' => $mandatory->map($line)->values(),
                'government_loans' => $government->map($line)->values(),
                'recurring' => $recurring->map($line)->values(),
                'other' => $other->map($line)->values(),
            ],
            'total_earnings' => (float) $entry->gross_pay,
            'total_deductions' => (float) $entry->total_deductions,
            'net_pay' => (float) $entry->net_pay,
            // The net pay as released: the 1st quincena (15th) and the 2nd (30th).
            'first_half' => (float) $entry->first_half,
            'second_half' => (float) $entry->second_half,
            'subtotals' => [
                'mandatory' => $sum($mandatory),
                'government_loans' => $sum($government),
                'recurring' => $sum($recurring),
                'other' => $sum($other),
            ],
        ];
    }
}
