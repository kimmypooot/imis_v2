<?php

namespace App\Support\HR\Payroll;

use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Notifications\HrUpdate;
use Illuminate\Support\Facades\Cache;

/**
 * HR-8: tells employees, under the bell, what happens to their recurring
 * deductions: assigned, changed, suspended, resumed, cancelled, on a
 * finalized payslip, nearly done, and done. Every notice points to
 * "My Earnings & Deductions". Amounts are left out of the text on purpose:
 * the page shows them to the employee alone.
 */
class DeductionNotices
{
    private const URL = '/my/earnings';

    public function __construct(private readonly Loans $loans) {}

    public function assigned(EmployeeLoan $loan): void
    {
        $this->send($loan, 'New recurring deduction', "{$this->name($loan)} was added to your deductions, starting {$loan->start_month->format('F Y')}.");
    }

    public function amountChanged(EmployeeLoan $loan): void
    {
        $this->send($loan, 'Deduction amount changed', "The monthly amount of your {$this->name($loan)} was changed.");
    }

    public function suspended(EmployeeLoan $loan): void
    {
        $this->send($loan, 'Deduction suspended', "Your {$this->name($loan)} is suspended: it is not deducted until HR resumes it.");
    }

    public function resumed(EmployeeLoan $loan): void
    {
        $this->send($loan, 'Deduction resumed', "Your {$this->name($loan)} is being deducted again.");
    }

    public function cancelled(EmployeeLoan $loan): void
    {
        $this->send($loan, 'Deduction cancelled', "Your {$this->name($loan)} was cancelled: nothing more will be deducted.");
    }

    /**
     * A payroll was finalized: tell each person whose payslip carries a
     * recurring deduction, and those whose loan it settled.
     */
    public function afterFinalize(PayrollRun $run): void
    {
        $run->entries()->whereNull('cancelled_at')
            ->whereHas('lines', fn ($query) => $query->whereNotNull('employee_loan_id'))
            ->with(['lines' => fn ($query) => $query->whereNotNull('employee_loan_id'), 'employee.user'])
            ->get()
            ->each(function (PayrollEntry $entry) use ($run) {
                $entry->employee->user->notify(new HrUpdate(
                    'Deduction reflected in your payslip',
                    "Your recurring deductions for {$run->period} are on your payslip.",
                    self::URL,
                ));

                EmployeeLoan::query()->with('component')->whereIn('id', $entry->lines->pluck('employee_loan_id'))->get()
                    ->each(function (EmployeeLoan $loan) {
                        if ($this->loans->balance($loan) <= 0) {
                            $this->send($loan, 'Deduction completed', "Your {$this->name($loan)} is fully settled.");
                        }
                    });
            });
    }

    /**
     * Once per loan and months-left, for loans about to finish.
     * Returns whether a notice went out.
     */
    public function endingSoon(EmployeeLoan $loan, int $monthsLeft): bool
    {
        if (! Cache::add("deduction-ending:{$loan->id}:{$monthsLeft}", true, now()->addDays(45))) {
            return false;
        }

        $this->send($loan, 'Deduction nearly completed', "Your {$this->name($loan)} has {$monthsLeft} ".($monthsLeft === 1 ? 'deduction' : 'deductions').' left.');

        return true;
    }

    private function send(EmployeeLoan $loan, string $title, string $message): void
    {
        $loan->loadMissing('employee.user');
        $loan->employee->user->notify(new HrUpdate($title, $message, self::URL));
    }

    private function name(EmployeeLoan $loan): string
    {
        $loan->loadMissing('component');

        return $loan->component->name.($loan->reference ? " ({$loan->reference})" : '');
    }
}
