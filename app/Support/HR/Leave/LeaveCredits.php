<?php

namespace App\Support\HR\Leave;

use App\Enums\LeaveApplicationStatus;
use App\Enums\LedgerEntryKind;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-6: the only writer of the leave-credit ledger, and of employees' leave
 * settings. Ports the legacy LeaveAccrualService / LeaveBalanceService:
 *
 * - A balance is the sum of the ledger entries for a type up to a date.
 * - Monthly accrual (VL and SL, 1.25 days a month) is posted on the last
 *   day of the month for everyone in service and eligible; each employee,
 *   type and month is posted once (re-running is safe).
 * - Each January, the year's grants (Special Privilege Leave, Solo Parent
 *   Leave) are posted, after last year's unused non-cumulative credits
 *   lapse.
 * - Entries are never edited or deleted: a mistake gets a reversing entry.
 */
class LeaveCredits
{
    // ── Balances ─────────────────────────────────────────────────────

    public function balance(Employee $employee, LeaveType $type, ?Carbon $asOf = null): float
    {
        return round((float) $employee->leaveLedger()
            ->where('leave_type_id', $type->creditType()->id)
            ->whereDate('entry_date', '<=', $asOf ?? today())
            ->sum('amount'), 3);
    }

    /**
     * Every type that holds credits, with this person's balance.
     *
     * @return BaseCollection<int, array{type_id: int, code: string, name: string, balance: float}>
     */
    public function balances(Employee $employee, ?Carbon $asOf = null): BaseCollection
    {
        $sums = $employee->leaveLedger()
            ->whereDate('entry_date', '<=', $asOf ?? today())
            ->selectRaw('leave_type_id, SUM(amount) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        return $this->creditTypes()->map(fn (LeaveType $type) => [
            'type_id' => $type->id,
            'code' => $type->code,
            'name' => $type->name,
            'balance' => round((float) ($sums[$type->id] ?? 0), 3),
        ])->values();
    }

    /**
     * @return Collection<int, LeaveType>
     */
    public function creditTypes(): Collection
    {
        return LeaveType::query()->active()->ordered()->get()->filter(fn (LeaveType $type) => $type->hasCredits())->values();
    }

    /**
     * One person's ledger for a year, oldest first, each line with the
     * running balance of its type (the leave card), plus the balances
     * brought forward from the year before.
     *
     * @return array{year: int, forward: array<int, float>, lines: BaseCollection<int, array<string, mixed>>}
     */
    public function card(Employee $employee, int $year): array
    {
        $before = Carbon::create($year, 1, 1)->subDay();
        $forward = $this->balances($employee, $before)->pluck('balance', 'type_id')->all();
        $running = $forward;

        $lines = $employee->leaveLedger()
            ->with(['leaveType', 'recorder:id,name'])
            ->whereYear('entry_date', $year)
            ->orderBy('entry_date')->orderBy('id')
            ->get()
            ->map(function (LeaveLedgerEntry $entry) use (&$running) {
                $running[$entry->leave_type_id] = round(($running[$entry->leave_type_id] ?? 0) + (float) $entry->amount, 3);

                return [
                    'id' => $entry->id,
                    'date' => $entry->entry_date->toDateString(),
                    'type_id' => $entry->leave_type_id,
                    'code' => $entry->leaveType->code,
                    'kind' => $entry->kind->value,
                    'kind_label' => $entry->kind->label(),
                    'amount' => (float) $entry->amount,
                    'balance' => $running[$entry->leave_type_id],
                    'particulars' => $entry->particulars,
                    'recorded_by' => $entry->recorder?->name,
                    'reverses_id' => $entry->reverses_id,
                ];
            });

        $reversed = $lines->pluck('reverses_id')->filter()->flip();

        return [
            'year' => $year,
            'forward' => $forward,
            'lines' => $lines->map(fn (array $line) => [...$line, 'reversed' => $reversed->has($line['id'])]),
        ];
    }

    /**
     * What a leave-card page shows: the year picked, the years to pick
     * from, today's balances and the year's card.
     *
     * @return array<string, mixed>
     */
    public function cardPage(Employee $employee, int $year): array
    {
        $years = $employee->leaveLedger()->pluck('entry_date')->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->push((int) today()->year)->push($year)->unique()->sortDesc()->values();

        return [
            'year' => $year,
            'years' => $years,
            'balances' => $this->balances($employee),
            'card' => $this->card($employee, $year),
        ];
    }

    // ── Manual entries ───────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    public function manualRules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'kind' => ['required', 'in:'.LedgerEntryKind::Opening->value.','.LedgerEntryKind::Adjustment->value],
            'entry_date' => ['required', 'date', 'after:1900-01-01'],
            'amount' => ['required', 'numeric', 'between:-999,999', 'not_in:0'],
            'particulars' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * An opening balance (carried over from the paper or legacy leave card)
     * or an adjustment, posted by HR.
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function postManual(Employee $employee, array $data, User $actor): LeaveLedgerEntry
    {
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        if (! $type->hasCredits()) {
            throw ValidationException::withMessages(['leave_type_id' => "{$type->name} has no credits of its own."]);
        }
        if ($data['kind'] === LedgerEntryKind::Opening->value && (float) $data['amount'] < 0) {
            throw ValidationException::withMessages(['amount' => 'An opening balance can\'t be negative.']);
        }

        return $this->post($employee, $type, LedgerEntryKind::from($data['kind']), (float) $data['amount'], Carbon::parse($data['entry_date']), $data['particulars'], $actor);
    }

    /**
     * Cancels an entry with an equal and opposite one; the original stays.
     */
    public function reverse(LeaveLedgerEntry $entry, string $reason, User $actor): LeaveLedgerEntry
    {
        if ($entry->reverses_id !== null) {
            throw ValidationException::withMessages(['reason' => 'A reversal can\'t itself be reversed; post an adjustment instead.']);
        }
        if (LeaveLedgerEntry::query()->where('reverses_id', $entry->id)->exists()) {
            throw ValidationException::withMessages(['reason' => 'This entry has already been reversed.']);
        }

        return $this->post($entry->employee, $entry->leaveType, LedgerEntryKind::Adjustment, -(float) $entry->amount, today(),
            "Reversal of the {$entry->entry_date->toDateString()} entry ({$entry->particulars}): {$reason}", $actor, reverses: $entry);
    }

    /**
     * HR-6b: approved leave with pay comes off the credits it draws on
     * (Forced Leave off Vacation Leave), dated the day it's approved.
     */
    public function deductLeave(Employee $employee, LeaveType $type, float $days, Carbon $date, string $particulars, User $actor): ?LeaveLedgerEntry
    {
        if ($days <= 0 || ! $type->creditType()->hasCredits()) {
            return null;
        }

        return $this->post($employee, $type, LedgerEntryKind::Deduction, -$days, $date, $particulars, $actor);
    }

    /**
     * HR-6c: approved monetization or terminal leave comes off the credits.
     */
    public function monetize(Employee $employee, LeaveType $type, float $days, string $particulars, User $actor): ?LeaveLedgerEntry
    {
        return $days > 0
            ? $this->post($employee, $type, LedgerEntryKind::Monetization, -$days, today(), $particulars, $actor)
            : null;
    }

    // ── Runs ─────────────────────────────────────────────────────────

    /**
     * Posts a month's accrual for everyone in service and eligible.
     *
     * @return array{posted: int, already: int, not_eligible: int}
     */
    public function accrueMonth(int $year, int $month, ?User $actor = null): array
    {
        $start = Carbon::create($year, $month, 1);
        $end = $start->copy()->endOfMonth()->startOfDay();
        $counts = ['posted' => 0, 'already' => 0, 'not_eligible' => 0];

        $types = LeaveType::query()->active()->whereNotNull('monthly_accrual')->where('monthly_accrual', '>', 0)->get();

        DB::transaction(function () use ($types, $start, $end, $year, $month, $actor, &$counts) {
            foreach ($this->inServiceDuring($start, $end) as $employee) {
                foreach ($types as $type) {
                    if (! $this->eligible($employee, $type)) {
                        $counts['not_eligible']++;

                        continue;
                    }
                    if ($this->alreadyPosted($employee, $type, LedgerEntryKind::Accrual, $year, $month)) {
                        $counts['already']++;

                        continue;
                    }

                    $earned = $this->proRated((float) $type->monthly_accrual, $this->daysWithoutPay($employee, $start, $end), $year, $month);
                    $this->post($employee, $type, LedgerEntryKind::Accrual, $earned, $end,
                        "{$type->code} earned, ".$start->format('F Y').($earned < (float) $type->monthly_accrual ? ' (less leave without pay)' : ''), $actor, $year, $month);
                    $counts['posted']++;
                }
            }
        });

        return $counts;
    }

    /**
     * The start of a year: last year's unused non-cumulative credits lapse
     * (dated December 31), then this year's grants are posted (dated
     * January 1). Re-running is safe.
     *
     * @return array{granted: int, lapsed: int, already: int, not_eligible: int}
     */
    public function startYear(int $year, ?User $actor = null): array
    {
        $counts = ['granted' => 0, 'lapsed' => 0, 'already' => 0, 'not_eligible' => 0];
        $january = Carbon::create($year, 1, 1);
        $lastYearEnd = $january->copy()->subDay();
        $types = LeaveType::query()->active()->where(fn ($query) => $query->where('lapses_yearly', true)->orWhereNotNull('annual_grant'))->get();

        DB::transaction(function () use ($types, $year, $january, $lastYearEnd, $actor, &$counts) {
            foreach ($this->inServiceDuring($january, $january) as $employee) {
                foreach ($types as $type) {
                    if ($type->lapses_yearly && ! $this->alreadyPosted($employee, $type, LedgerEntryKind::Lapse, $year - 1)) {
                        $left = $this->balance($employee, $type, $lastYearEnd);
                        if ($left > 0) {
                            $this->post($employee, $type, LedgerEntryKind::Lapse, -$left, $lastYearEnd, "Unused {$type->code} of ".($year - 1).' lapsed', $actor, $year - 1);
                            $counts['lapsed']++;
                        }
                    }

                    if ((float) $type->annual_grant <= 0) {
                        continue;
                    }
                    if (! $this->eligible($employee, $type, $january)) {
                        $counts['not_eligible']++;

                        continue;
                    }
                    if ($this->alreadyPosted($employee, $type, LedgerEntryKind::Grant, $year)) {
                        $counts['already']++;

                        continue;
                    }

                    $this->post($employee, $type, LedgerEntryKind::Grant, (float) $type->annual_grant, $january, "{$type->code} for {$year}", $actor, $year);
                    $counts['granted']++;
                }
            }
        });

        return $counts;
    }

    /**
     * Whether someone may have this kind of leave: sex, employment status
     * (HR-1), minimum service, and a valid Solo Parent ID where required.
     */
    public function eligible(Employee $employee, LeaveType $type, ?Carbon $on = null): bool
    {
        return $this->ineligibilityReasons($employee, $type, $on) === [];
    }

    /**
     * @return array<int, string> why not (empty when eligible)
     */
    public function ineligibilityReasons(Employee $employee, LeaveType $type, ?Carbon $on = null): array
    {
        $on ??= today();
        $reasons = [];
        $user = $employee->user;

        if ($type->sex && $user->sex !== $type->sex) {
            $reasons[] = "{$type->name} is for ".($type->sex === 'female' ? 'women' : 'men').' only.';
        }
        if ($type->eligible_statuses && ! in_array($employee->employment_status?->value, $type->eligible_statuses, true)) {
            $reasons[] = $employee->employment_status
                ? "{$type->name} isn't available to {$employee->employment_status->label()} employees."
                : 'Your employment status isn\'t set yet; ask HR.';
        }
        if ($type->min_service_months > 0) {
            $since = $employee->original_appointment_date;
            if (! $since || $since->diffInMonths($on) < $type->min_service_months) {
                $reasons[] = "{$type->name} needs at least {$type->min_service_months} months of service.";
            }
        }
        if ($type->requires_solo_parent_id && ! $employee->hasValidSoloParentId($on)) {
            $reasons[] = "{$type->name} needs a valid Solo Parent ID on file with HR.";
        }

        return $reasons;
    }

    // ── Leave settings ───────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    public function settingsRules(): array
    {
        return [
            'immediate_supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_solo_parent' => ['boolean'],
            'solo_parent_id_number' => ['nullable', 'required_if:is_solo_parent,true', 'string', 'max:50'],
            'solo_parent_id_expiry' => ['nullable', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     * @return array<int, string> changed field names (never the ID number itself)
     */
    public function updateSettings(Employee $employee, array $data): array
    {
        if (($data['immediate_supervisor_id'] ?? null) === $employee->user_id) {
            throw ValidationException::withMessages(['immediate_supervisor_id' => 'Someone can\'t be their own supervisor.']);
        }

        if (! ($data['is_solo_parent'] ?? false)) {
            $data['solo_parent_id_number'] = null;
            $data['solo_parent_id_expiry'] = null;
        }

        $employee->forceFill(collect($data)->only(['immediate_supervisor_id', 'is_solo_parent', 'solo_parent_id_number', 'solo_parent_id_expiry'])->all());
        $changed = array_keys($employee->getDirty());
        $employee->save();

        return $changed;
    }

    /**
     * Approved days without pay that started in the period (HR-6b).
     */
    public function daysWithoutPay(Employee $employee, Carbon $start, Carbon $end): float
    {
        return round((float) LeaveApplication::query()
            ->where('employee_id', $employee->id)
            ->where('status', LeaveApplicationStatus::Approved)
            ->whereDate('date_from', '>=', $start)->whereDate('date_from', '<=', $end)
            ->sum('days_without_pay'), 3);
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * The legacy LMS rule: a month's accrual × the share of its weekdays
     * actually rendered (weekdays less days without pay).
     */
    private function proRated(float $rate, float $daysWithoutPay, int $year, int $month): float
    {
        if ($daysWithoutPay <= 0) {
            return $rate;
        }

        $weekdays = app(WorkingDays::class)->weekdaysInMonth($year, $month);

        return round($rate * max(0, $weekdays - min($daysWithoutPay, $weekdays)) / $weekdays, 3);
    }

    private function post(
        Employee $employee,
        LeaveType $type,
        LedgerEntryKind $kind,
        float $amount,
        Carbon $date,
        string $particulars,
        ?User $actor,
        ?int $year = null,
        ?int $month = null,
        ?LeaveLedgerEntry $reverses = null,
    ): LeaveLedgerEntry {
        $entry = new LeaveLedgerEntry;
        $entry->forceFill([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->creditType()->id,
            'entry_date' => $date->toDateString(),
            'kind' => $kind,
            'amount' => round($amount, 3),
            'particulars' => mb_substr($particulars, 0, 500),
            'period_year' => $year,
            'period_month' => $month,
            'reverses_id' => $reverses?->id,
            'recorded_by' => $actor?->id,
        ])->save();

        return $entry;
    }

    private function alreadyPosted(Employee $employee, LeaveType $type, LedgerEntryKind $kind, int $year, ?int $month = null): bool
    {
        return $employee->leaveLedger()
            ->where('leave_type_id', $type->id)
            ->where('kind', $kind)
            ->where('period_year', $year)
            ->when($month, fn ($query) => $query->where('period_month', $month))
            // A reversed run still counts as posted (as in the legacy LMS):
            // re-running never brings back an entry HR cancelled.
            ->exists();
    }

    /**
     * Everyone with an employee record in service at some point in the
     * period: appointed by its end (or not recorded), not separated before
     * its start.
     *
     * @return Collection<int, Employee>
     */
    private function inServiceDuring(Carbon $start, Carbon $end): Collection
    {
        return Employee::query()
            ->with('user')
            ->where(fn ($query) => $query->whereNull('original_appointment_date')->orWhereDate('original_appointment_date', '<=', $end))
            ->where(fn ($query) => $query->whereNull('separation_date')->orWhereDate('separation_date', '>=', $start))
            ->get();
    }
}
