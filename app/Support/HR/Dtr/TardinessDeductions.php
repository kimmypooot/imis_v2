<?php

namespace App\Support\HR\Dtr;

use App\Enums\AuditEvent;
use App\Models\DtrLeaveDeduction;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Leave\LeaveCredits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DTR: charging a month's tardiness and undertime to leave credits.
 *
 * - The minutes come from the employee's DTR for the month (late plus
 *   undertime). Absences, including the unworked half of a half-day leave,
 *   are without pay and are not charged to credits here.
 * - They are converted with CSC Table IV (MC No. 14, s. 1999) on the
 *   month's total (TardinessConversion).
 * - Omnibus Rules on Leave, Rule XVI Sec. 34: tardiness and undertime are
 *   deducted from Vacation Leave, never Sick Leave, unless the undertime is
 *   for health reasons supported by a medical certificate and a leave
 *   application. So Sick Leave is only offered when HR confirms that.
 * - Credits never go below zero: what the balance at the end of the month
 *   cannot cover is recorded as days without pay (for payroll to treat as
 *   leave without pay) instead of a negative balance.
 * - HR reviews, then posts. A month is charged once; reversing the charge
 *   frees it to be charged again. The deduction is dated the last day of
 *   the month.
 * - Employees whose leave card comes from HR's workbook (`leave_card_months`)
 *   are left out: their balances are brought back to the workbook on every
 *   sync, so a charge made here would be lost or doubled. Their tardiness
 *   belongs in the workbook.
 */
class TardinessDeductions
{
    public function __construct(
        private readonly DailyTimeRecord $record = new DailyTimeRecord,
        private readonly TardinessConversion $conversion = new TardinessConversion,
        private readonly LeaveCredits $credits = new LeaveCredits,
    ) {}

    /**
     * Who has tardiness or undertime in the month, or a charge already
     * posted for it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forMonth(Carbon $month): Collection
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();
        $vacation = LeaveType::query()->where('code', 'VL')->first();
        $cardManaged = DB::table('leave_card_months')->distinct()->pluck('employee_id')->flip();

        return Employee::query()->with('user')
            ->whereNotNull('biometric_id')
            ->where(fn ($query) => $query->whereNull('separation_date')->orWhereDate('separation_date', '>=', $from))
            ->get()
            ->map(function (Employee $employee) use ($from, $to, $vacation, $cardManaged) {
                $totals = $this->record->build($employee, $from, $to)['totals'];
                $minutes = $totals['late_minutes'] + $totals['undertime_minutes'];
                $posted = $this->active($employee, $from);

                if ($minutes === 0 && ! $posted) {
                    return null;
                }

                $days = $this->conversion->days($minutes);
                $available = $vacation ? max(0.0, $this->credits->balance($employee, $vacation, $to)) : 0.0;

                return [
                    'employee_id' => $employee->id,
                    'name' => $employee->user->name,
                    'biometric_id' => $employee->biometric_id,
                    'late_minutes' => $totals['late_minutes'],
                    'undertime_minutes' => $totals['undertime_minutes'],
                    'minutes' => $minutes,
                    'days' => $days,
                    'absences' => $totals['absences'],
                    'half_day_absences' => $totals['half_day_absences'],
                    'card_managed' => $cardManaged->has($employee->id),
                    // Vacation Leave credits at the end of the month, and what they would not cover.
                    'available' => $available,
                    'without_pay' => round(max(0.0, $days - $available), 3),
                    'deduction' => $posted ? [
                        'id' => $posted->id,
                        'days' => (float) $posted->days,
                        'days_without_pay' => (float) $posted->days_without_pay,
                        'minutes' => $posted->minutes,
                        'type' => $posted->leaveType->code,
                        'posted_at' => $posted->posted_at->toIso8601String(),
                        // The DTR no longer says what was charged (an adjustment or a late import).
                        'dtr_changed' => $posted->minutes !== $minutes,
                    ] : null,
                ];
            })
            ->filter()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function post(Employee $employee, Carbon $month, string $charge, bool $healthCertified, User $actor): DtrLeaveDeduction
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();

        if (! in_array($charge, ['VL', 'SL'], true)) {
            throw ValidationException::withMessages(['charge' => 'Tardiness and undertime are charged to Vacation Leave, or to Sick Leave for health reasons.']);
        }
        if ($charge === 'SL' && ! $healthCertified) {
            throw ValidationException::withMessages(['charge' => 'Sick Leave can only be charged when the undertime is for health reasons, supported by a medical certificate and a leave application.']);
        }
        if (! $to->lt(today())) {
            throw ValidationException::withMessages(['month' => 'A month can be charged once it has ended.']);
        }

        $label = $from->format('F Y');

        $deduction = DB::transaction(function () use ($employee, $charge, $from, $to, $label, $actor) {
            // One change at a time per employee, so two requests cannot both pass the checks below.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if (DB::table('leave_card_months')->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['month' => 'This employee\'s leave card comes from HR\'s workbook. Record their tardiness and undertime there, so a sync does not undo or double it.']);
            }
            if ($this->active($employee, $from)) {
                throw ValidationException::withMessages(['month' => 'This month is already charged. Reverse the charge first to post it again.']);
            }

            $totals = $this->record->build($employee, $from, $to)['totals'];
            $minutes = $totals['late_minutes'] + $totals['undertime_minutes'];
            if ($minutes <= 0) {
                throw ValidationException::withMessages(['month' => 'There is no tardiness or undertime to charge for this month.']);
            }

            $type = LeaveType::query()->where('code', $charge)->first();
            if (! $type || ! $type->creditType()->hasCredits()) {
                throw ValidationException::withMessages(['charge' => 'That leave type has no credits to charge.']);
            }

            $days = $this->conversion->days($minutes);
            $available = max(0.0, $this->credits->balance($employee, $type, $to));
            $charged = round(min($days, $available), 3);
            $withoutPay = round($days - $charged, 3);
            $hours = intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);

            $entry = $charged > 0
                ? $this->credits->deductLeave($employee, $type, $charged, $to, sprintf(
                    'Tardiness and undertime, %s (%s = %s day, CSC Table IV)%s',
                    $label, $hours, number_format($days, 3), $withoutPay > 0 ? '; '.number_format($withoutPay, 3).' day without pay, credits used up' : ''), $actor)
                : null;

            return DtrLeaveDeduction::query()->create([
                'employee_id' => $employee->id,
                'period_year' => $from->year,
                'period_month' => $from->month,
                'minutes' => $minutes,
                'days' => $charged,
                'days_without_pay' => $withoutPay,
                'leave_type_id' => $type->id,
                'leave_ledger_entry_id' => $entry?->id,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);
        });

        app(AuditLogger::class)->recordFor($actor, AuditEvent::DtrLeaveDeducted, "Charged {$employee->user->name}'s tardiness and undertime for {$label} to {$charge}: ".number_format((float) $deduction->days, 3).' day'.((float) $deduction->days_without_pay > 0 ? ', '.number_format((float) $deduction->days_without_pay, 3).' day without pay' : ''), $employee, [
            'month' => $from->format('Y-m'),
            'minutes' => $deduction->minutes,
            'days' => (float) $deduction->days,
            'days_without_pay' => (float) $deduction->days_without_pay,
            'leave_type' => $charge,
            'health_certified' => $charge === 'SL',
        ]);

        return $deduction->load('entry.leaveType');
    }

    public function reverse(DtrLeaveDeduction $deduction, string $reason, User $actor): void
    {
        $deduction->loadMissing(['employee.user']);

        DB::transaction(function () use ($deduction, $reason, $actor) {
            Employee::query()->whereKey($deduction->employee_id)->lockForUpdate()->first();

            $fresh = DtrLeaveDeduction::query()->with('entry')->findOrFail($deduction->id);
            if (! $fresh->isActive()) {
                throw ValidationException::withMessages(['reason' => 'This charge has already been reversed.']);
            }

            if ($fresh->entry) {
                $this->credits->reverse($fresh->entry, $reason, $actor);
            }
            $fresh->update(['reversed_at' => now()]);
        });

        $label = Carbon::create($deduction->period_year, $deduction->period_month, 1)->format('F Y');
        app(AuditLogger::class)->recordFor($actor, AuditEvent::DtrLeaveDeductionReversed, "Reversed the tardiness and undertime charge of {$deduction->employee->user->name} for {$label}", $deduction->employee, [
            'month' => sprintf('%04d-%02d', $deduction->period_year, $deduction->period_month),
            'days' => (float) $deduction->days,
            'reason' => $reason,
        ]);
    }

    private function active(Employee $employee, Carbon $from): ?DtrLeaveDeduction
    {
        return DtrLeaveDeduction::query()->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('period_year', $from->year)->where('period_month', $from->month)
            ->latest('id')->get()
            ->first(fn (DtrLeaveDeduction $deduction) => $deduction->isActive());
    }
}
