<?php

namespace App\Support\HR\Dtr;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Models\DtrAdjustment;
use App\Models\DtrLeaveDeduction;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Leave\LeaveCredits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
 *   are charged only for months the workbook has not reached yet. The
 *   workbook stays the master: each sync brings the ledger back to the
 *   card's last month, so a charge made for a month the card already covers
 *   would be undone (the workbook has its own tardiness there). A charge for
 *   a later month is a pending one: it keeps the balance honest meanwhile,
 *   and when the workbook reaches that month the sync settles the difference
 *   (the workbook's figure wins, so nothing is doubled).
 */
class TardinessDeductions
{
    public function __construct(
        private readonly DailyTimeRecord $record = new DailyTimeRecord,
        private readonly TardinessConversion $conversion = new TardinessConversion,
        private readonly LeaveCredits $credits = new LeaveCredits,
        private readonly HabitualAttendance $habitual = new HabitualAttendance,
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
        $cardThrough = $this->cardThrough();
        $key = $from->year * 100 + $from->month;

        return Employee::query()->with('user')
            ->whereNotNull('biometric_id')
            ->where(fn ($query) => $query->whereNull('separation_date')->orWhereDate('separation_date', '>=', $from))
            ->get()
            ->map(function (Employee $employee) use ($from, $to, $vacation, $cardThrough, $key) {
                $built = $this->record->build($employee, $from, $to);
                $totals = $built['totals'];
                $minutes = $totals['late_minutes'] + $totals['undertime_minutes'];
                $posted = $this->active($employee, $from);
                $habitual = $this->habitual->evaluate($employee, $from, $built['days']);

                if ($minutes === 0 && ! $posted && ! $habitual['tardiness'] && ! $habitual['absenteeism']) {
                    return null;
                }

                // Days the employee entered themselves, and the minutes they bring.
                $entered = $built['days']->filter(fn (array $day) => $day['adjustment']['by_employee'] ?? false)->values();

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
                    // The workbook already covers this month: its own tardiness is there.
                    'card_managed' => $cardThrough->has($employee->id) && $cardThrough[$employee->id] >= $key,
                    // The workbook doesn't reach this month yet: charged here until it does.
                    'card_pending' => $cardThrough->has($employee->id) && $cardThrough[$employee->id] < $key,
                    'entered_days' => $entered->map(fn (array $day) => [
                        'date' => $day['date'],
                        'time_in' => $day['adjustment']['time_in'],
                        'time_out' => $day['adjustment']['time_out'],
                        'remark' => $day['adjustment']['remark'],
                        'minutes' => $day['late_minutes'] + $day['undertime_minutes'],
                        'verified' => $day['adjustment']['verified'],
                    ])->all(),
                    'entered_minutes' => $entered->sum(fn (array $day) => $day['late_minutes'] + $day['undertime_minutes']),
                    'unverified_days' => $entered->where('adjustment.verified', false)->count(),
                    'habitual' => ['tardiness' => $habitual['tardiness'], 'absenteeism' => $habitual['absenteeism']],
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

    /**
     * @param  bool  $postAnyway  charge although days the employee entered are not verified yet; audited
     */
    public function post(Employee $employee, Carbon $month, string $charge, bool $healthCertified, User $actor, bool $postAnyway = false): DtrLeaveDeduction
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

        $unverified = 0;
        $deduction = DB::transaction(function () use ($employee, $charge, $from, $to, $label, $actor, $postAnyway, &$unverified) {
            // One change at a time per employee, so two requests cannot both pass the checks below.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $through = $this->cardThrough()->get($employee->id);
            if ($through !== null && $through >= $from->year * 100 + $from->month) {
                throw ValidationException::withMessages(['month' => 'HR\'s leave card workbook already covers '.$label.' for this employee. Record their tardiness and undertime there, so a sync does not undo or double it.']);
            }
            $pending = $through !== null;
            if ($this->active($employee, $from)) {
                throw ValidationException::withMessages(['month' => 'This month is already charged. Reverse the charge first to post it again.']);
            }

            $totals = $this->record->build($employee, $from, $to)['totals'];
            $minutes = $totals['late_minutes'] + $totals['undertime_minutes'];
            if ($minutes <= 0) {
                throw ValidationException::withMessages(['month' => 'There is no tardiness or undertime to charge for this month.']);
            }

            $unverified = DtrAdjustment::query()->where('employee_id', $employee->id)->where('entered_by_employee', true)->whereNull('verified_at')
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->count();
            if ($unverified > 0 && ! $postAnyway) {
                throw ValidationException::withMessages(['month' => $unverified.' day'.($unverified === 1 ? '' : 's').' entered by the employee '.($unverified === 1 ? 'is' : 'are').' not verified yet. Have their supervisor or office head verify '.($unverified === 1 ? 'it' : 'them').', or charge anyway.']);
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
                    $label, $hours, number_format($days, 3), ($withoutPay > 0 ? '; '.number_format($withoutPay, 3).' day without pay, credits used up' : '').($pending ? '; pending the leave card file' : '')), $actor)
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
            'unverified_days' => $unverified,
            'posted_anyway' => $unverified > 0,
        ]);

        $this->tellEmployee($employee, $deduction, $label, $charge);

        return $deduction->load('entry.leaveType');
    }

    /**
     * Tells the employee what was charged and why, so a deduction on their
     * leave card never comes as a surprise.
     */
    private function tellEmployee(Employee $employee, DtrLeaveDeduction $deduction, string $label, string $charge): void
    {
        $hours = intdiv($deduction->minutes, 60).' hr '.($deduction->minutes % 60).' min';
        $days = (float) $deduction->days;
        $withoutPay = (float) $deduction->days_without_pay;
        $type = $charge === 'SL' ? 'Sick Leave' : 'Vacation Leave';

        $result = match (true) {
            $days > 0 && $withoutPay > 0 => number_format($days, 3)." day was charged to your {$type} and ".number_format($withoutPay, 3).' day is without pay, as your credits ran out.',
            $days > 0 => number_format($days, 3)." day was charged to your {$type}.",
            default => number_format($withoutPay, 3).' day is without pay, as your credits ran out.',
        };

        $employee->loadMissing('user')->user->notify(new HrUpdate(
            "Tardiness charged to your leave for {$label}",
            "Your DTR for {$label} has {$hours} of tardiness and undertime (CSC Table IV). {$result}",
            '/my/leave',
        ));
    }

    /**
     * Reminds HR (anyone with leave.manage) that a finished month still has
     * tardiness to charge, or a charge whose DTR changed. Once per person
     * per month, so running it daily never repeats the notice.
     *
     * @return int how many people were reminded
     */
    public function remindSyncDue(Carbon $month): int
    {
        $rows = $this->forMonth($month);
        $uncharged = $rows->filter(fn (array $row) => ! $row['deduction'] && $row['minutes'] > 0 && ! $row['card_managed'])->count();
        $changed = $rows->filter(fn (array $row) => $row['deduction'] && $row['deduction']['dtr_changed'] && ! $row['card_managed'])->count();

        if ($uncharged + $changed === 0) {
            return 0;
        }

        $label = $month->format('F Y');
        $title = "Sync {$label} tardiness with leave credits";
        $recipients = User::query()->where('status', 'active')
            ->whereHas('activeRoles.permissions', fn ($query) => $query->where('name', PermissionName::LeaveManage->value))
            ->get()
            ->reject(fn (User $user) => $user->notifications()->where('data->title', $title)->exists());

        $parts = array_filter([
            $uncharged > 0 ? $uncharged.($uncharged === 1 ? ' employee has' : ' employees have').' tardiness or undertime not yet charged' : null,
            $changed > 0 ? $changed.' charged '.($changed === 1 ? 'month has' : 'months have').' a DTR that changed afterwards' : null,
        ]);

        Notification::send($recipients, new HrUpdate(
            $title,
            ucfirst(implode('; ', $parts))." for {$label}. Open Tardiness & Undertime and press Sync with DTR.",
            '/admin/hr/leave/tardiness?month='.$month->format('Y-m'),
        ));

        return $recipients->count();
    }

    /**
     * Charges every row that is ready to Vacation Leave: the month has ended,
     * there is something to charge, nothing is waiting for verification, and
     * the leave card isn't HR's workbook. Anything that needs a decision is
     * left in the list.
     *
     * @param  callable(Employee): bool  $mayCharge
     * @return array{posted: int, left: int}
     */
    public function postReviewed(Carbon $month, User $actor, callable $mayCharge): array
    {
        $posted = 0;
        $left = 0;

        foreach ($this->forMonth($month) as $row) {
            if ($row['deduction'] || $row['minutes'] === 0 || $row['card_managed']) {
                continue;
            }

            $employee = Employee::query()->with('user')->find($row['employee_id']);
            if ($row['unverified_days'] > 0 || ! $employee || ! $mayCharge($employee)) {
                $left++;

                continue;
            }

            try {
                $this->post($employee, $month, 'VL', false, $actor);
                $posted++;
            } catch (ValidationException) {
                $left++;
            }
        }

        return ['posted' => $posted, 'left' => $left];
    }

    /**
     * Brings the leave cards in line with the DTR for a month that has
     * ended: charges what is ready (see postReviewed) and re-charges a
     * posted month whose DTR changed since (an adjustment or a late biometric
     * import) — the old charge is reversed and the new total posted to the
     * same leave type. Rows needing a decision (unverified days, a workbook
     * card, someone the actor may not charge) are left alone.
     *
     * @param  callable(Employee): bool  $mayCharge
     * @return array{posted: int, updated: int, left: int}
     */
    public function sync(Carbon $month, User $actor, callable $mayCharge): array
    {
        $updated = 0;
        $left = 0;

        foreach ($this->forMonth($month) as $row) {
            if (! $row['deduction'] || ! $row['deduction']['dtr_changed'] || $row['card_managed']) {
                continue;
            }

            $employee = Employee::query()->with('user')->find($row['employee_id']);
            if ($row['unverified_days'] > 0 || ! $employee || ! $mayCharge($employee)) {
                $left++;

                continue;
            }

            $charge = $row['deduction']['type'];

            try {
                $this->reverse(DtrLeaveDeduction::query()->findOrFail($row['deduction']['id']), 'The DTR changed after this was charged; re-synced.', $actor);
                if ($row['minutes'] > 0) {
                    $this->post($employee, $month, $charge, $charge === 'SL', $actor);
                }
                $updated++;
            } catch (ValidationException) {
                $left++;
            }
        }

        $new = $this->postReviewed($month, $actor, $mayCharge);

        return ['posted' => $new['posted'], 'updated' => $updated, 'left' => $left + $new['left']];
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

    /**
     * The last month (as year*100+month) each workbook-managed employee's
     * card reaches. A year's brought-forward row (month 0) counts as the end
     * of the year before.
     *
     * @return Collection<int, int> employee id => last month covered
     */
    private function cardThrough(): Collection
    {
        return DB::table('leave_card_months')
            ->selectRaw('employee_id, max(year * 100 + month) as through')
            ->groupBy('employee_id')
            ->pluck('through', 'employee_id')
            ->map(fn ($value) => (int) $value);
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
