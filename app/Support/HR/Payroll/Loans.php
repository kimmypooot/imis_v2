<?php

namespace App\Support\HR\Payroll;

use App\Enums\PayrollRunStatus;
use App\Models\EmployeeLoan;
use App\Models\PayrollLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * HR-8: what a loan still owes. Nothing is stored: the balance is the
 * total payable, less what was paid before IMIS, less the loan's lines on
 * finalized payrolls — so reopening and correcting a payroll can never
 * leave a balance wrong.
 */
class Loans
{
    /** Loans with this many amortizations or fewer left are "nearly paid". */
    public const NEARLY_PAID_MONTHS = 2;

    /**
     * Paid through finalized payrolls, per loan id (one query for many).
     *
     * @param  array<int, int>  $loanIds
     * @return Collection<int, float>
     */
    public function paidThroughPayroll(array $loanIds, ?int $exceptRunId = null): Collection
    {
        if ($loanIds === []) {
            return collect();
        }

        return PayrollLine::query()
            ->whereIn('employee_loan_id', $loanIds)
            ->whereHas('entry.run', fn ($query) => $query->where('status', PayrollRunStatus::Finalized->value)
                ->when($exceptRunId, fn ($query) => $query->whereKeyNot($exceptRunId)))
            ->selectRaw('employee_loan_id, SUM(amount) as paid')
            ->groupBy('employee_loan_id')
            ->pluck('paid', 'employee_loan_id')
            ->map(fn ($paid) => (float) $paid);
    }

    public function balance(EmployeeLoan $loan, ?float $paidThroughPayroll = null, ?int $exceptRunId = null): float
    {
        $paid = $paidThroughPayroll ?? (float) ($this->paidThroughPayroll([$loan->id], $exceptRunId)[$loan->id] ?? 0);

        return max(0, round((float) $loan->total_payable - (float) $loan->paid_before - $paid, 2));
    }

    /**
     * What to deduct for the month that starts on $month: the amortization,
     * or what's left if less; nothing before it starts, after it was
     * stopped, or once it's paid. `$exceptRunId` leaves out the payroll
     * being (re)generated, so its own draft lines don't count as paid.
     */
    public function amortizationFor(EmployeeLoan $loan, Carbon $month, float $balance): float
    {
        if ($loan->start_month->gt($month->copy()->endOfMonth())
            || ($loan->stopped_on && $loan->stopped_on->lt($month->copy()->startOfMonth()))) {
            return 0.0;
        }

        return round(min((float) $loan->monthly_amortization, $balance), 2);
    }

    /** "active", "paid" or "stopped". */
    public function status(EmployeeLoan $loan, float $balance): string
    {
        return match (true) {
            $balance <= 0 => 'paid',
            $loan->stopped_on !== null => 'stopped',
            default => 'active',
        };
    }

    /**
     * @param  Collection<int, EmployeeLoan>  $loans
     * @return Collection<int, array<string, mixed>>
     */
    public function presentMany(Collection $loans): Collection
    {
        $paid = $this->paidThroughPayroll($loans->pluck('id')->all());

        return $loans->map(fn (EmployeeLoan $loan) => $this->present($loan, (float) ($paid[$loan->id] ?? 0)));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(EmployeeLoan $loan, ?float $paidThroughPayroll = null): array
    {
        $paidThroughPayroll ??= (float) ($this->paidThroughPayroll([$loan->id])[$loan->id] ?? 0);
        $balance = $this->balance($loan, $paidThroughPayroll);
        $amortization = (float) $loan->monthly_amortization;

        return [
            'id' => $loan->id,
            'employee_id' => $loan->employee_id,
            'employee' => $loan->employee?->user?->name,
            'pay_component_id' => $loan->pay_component_id,
            'type' => $loan->component?->name,
            'reference' => $loan->reference,
            'granted_on' => $loan->granted_on?->toDateString(),
            'principal' => (float) $loan->principal,
            'total_payable' => (float) $loan->total_payable,
            'monthly_amortization' => $amortization,
            'start_month' => $loan->start_month->format('Y-m'),
            'paid_before' => (float) $loan->paid_before,
            'paid' => round((float) $loan->paid_before + $paidThroughPayroll, 2),
            'balance' => $balance,
            'months_left' => $amortization > 0 ? (int) ceil($balance / $amortization) : null,
            'status' => $this->status($loan, $balance),
            'stopped_on' => $loan->stopped_on?->toDateString(),
            'remarks' => $loan->remarks,
            'deletable' => ! $loan->payrollLines()->exists(),
        ];
    }
}
