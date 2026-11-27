<?php

namespace App\Support\HR\Leave;

use App\Enums\LeaveApplicationStatus;
use App\Enums\LedgerEntryKind;
use App\Enums\PermissionName;
use App\Models\DtrLeaveDeduction;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveCardMonth;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\HrUpdate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
     * The balances worth showing this person: a type only some people can
     * have (Solo Parent Leave) is left out for anyone it doesn't concern.
     *
     * @return BaseCollection<int, array{type_id: int, code: string, name: string, balance: float}>
     */
    public function visibleBalances(Employee $employee, ?Carbon $asOf = null): BaseCollection
    {
        $shown = $this->shownTypeIds($employee, $this->creditTypes());

        return $this->balances($employee, $asOf)->filter(fn (array $balance) => in_array($balance['type_id'], $shown, true))->values();
    }

    /**
     * Whether a leave type concerns this person at all. Solo Parent Leave
     * does when they're marked a solo parent — even with an expired ID, so
     * they're told to renew it — or when they have entries of it already
     * (history never disappears). Everything else concerns everyone; a type
     * they can't file yet still shows, with the reason.
     *
     * @param  BaseCollection<int, LeaveType>|Collection<int, LeaveType>  $types
     * @return array<int, int> the ids of the types to show
     */
    public function shownTypeIds(Employee $employee, BaseCollection $types): array
    {
        $withEntries = null;

        return $types->filter(function (LeaveType $type) use ($employee, &$withEntries) {
            if (! $type->requires_solo_parent_id || $employee->is_solo_parent) {
                return true;
            }
            $withEntries ??= $employee->leaveLedger()->distinct()->pluck('leave_type_id')->all();

            return in_array($type->creditType()->id, $withEntries, true);
        })->pluck('id')->values()->all();
    }

    /**
     * Answered "yes" to "Are you a solo parent?" (PDS item 40c) but not yet
     * marked one by HR: a prompt for HR to check the Solo Parent ID, never
     * eligibility by itself.
     */
    public function pdsSaysSoloParent(Employee $employee): bool
    {
        return ! $employee->is_solo_parent && ($employee->pdsProfile?->questions['q40c'] ?? null) === true;
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
            ->merge($employee->leaveCardMonths()->pluck('year'))
            ->push((int) today()->year)->push($year)->unique()->sortDesc()->values();

        return [
            'year' => $year,
            'years' => $years,
            'balances' => $this->visibleBalances($employee),
            'card' => $this->card($employee, $year),
            'monthly' => $this->monthlyCard($employee, $year),
        ];
    }

    /**
     * The year laid out like the paper leave card: one row per month with
     * Vacation and Sick Leave side by side — earned, used, month-end
     * balance — and the card's notes. Months the imported card history
     * covers show exactly what the card said; later months are built from
     * the ledger (accruals as earned; leave taken, monetization and lapses
     * as used; opening balances and adjustments apart). Balances always
     * come from the ledger sum, never from the history.
     *
     * @return array{year: int, forward: ?array{vl: ?float, sl: ?float, source: string}, months: array<int, array<string, mixed>>}
     */
    public function monthlyCard(Employee $employee, int $year): array
    {
        $ids = LeaveType::query()->whereIn('code', ['VL', 'SL'])->pluck('id', 'code');
        $history = $employee->leaveCardMonths()->with('corrector:id,name')->where('year', $year)->get()->keyBy('month');
        $entries = $employee->leaveLedger()->with('leaveType')->whereIn('leave_type_id', $ids->values())->orderBy('entry_date')->orderBy('id')->get();

        $inYear = $entries->filter(fn (LeaveLedgerEntry $entry) => $entry->entry_date->year === $year);
        $shown = $history->keys()->reject(fn (int $month) => $month === 0)
            ->merge($inYear->map(fn (LeaveLedgerEntry $entry) => $entry->entry_date->month))->unique()->sort()->values();
        if ($shown->isEmpty()) {
            return ['year' => $year, 'forward' => null, 'months' => []];
        }

        $balanceAt = function (string $code, Carbon $date) use ($entries, $ids): float {
            return round((float) $entries->where('leave_type_id', $ids[$code])->filter(fn (LeaveLedgerEntry $entry) => $entry->entry_date->lte($date))->sum('amount'), 3);
        };

        $forward = $history->get(0);
        $before = Carbon::create($year, 1, 1)->subDay();
        $forwardRow = $forward
            ? ['id' => $forward->id, 'vl' => $forward->vl_balance, 'sl' => $forward->sl_balance, 'source' => 'card', 'correction' => $this->correctionOf($forward)]
            : ($entries->contains(fn (LeaveLedgerEntry $entry) => $entry->entry_date->lte($before))
                ? ['vl' => $balanceAt('VL', $before), 'sl' => $balanceAt('SL', $before), 'source' => 'ledger']
                : null);

        $months = [];
        foreach (range($shown->first(), $shown->last()) as $month) {
            $end = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
            $card = $history->get($month);
            $row = ['month' => $month, 'label' => $end->format('F'), 'source' => $card ? 'card' : 'ledger'];

            $monthEntries = $inYear->filter(fn (LeaveLedgerEntry $entry) => $entry->entry_date->month === $month);

            if ($card) {
                // The card's own figures, plus what HR adjusted in IMIS that month (not
                // the carry-over or the file sync, which only bring the ledger to the card).
                $adjustments = $monthEntries->filter(fn (LeaveLedgerEntry $entry) => $entry->kind === LedgerEntryKind::Adjustment
                    && $entry->legacy_id === null && ! str_starts_with((string) $entry->particulars, LeaveCardSync::PARTICULARS_PREFIX));
                foreach (['VL', 'SL'] as $code) {
                    $key = strtolower($code);
                    $row[$key] = [
                        'earned' => $card["{$key}_earned"], 'used' => $card["{$key}_used"], 'balance' => $card["{$key}_balance"],
                        'adjusted' => round((float) $adjustments->where('leave_type_id', $ids[$code])->sum('amount'), 3),
                    ];
                }
                $row['id'] = $card->id;
                $row['particulars'] = $card->particulars;
                $notes = $adjustments->map(fn (LeaveLedgerEntry $entry) => $entry->leaveType->code.': '.$entry->particulars)->unique()->implode('; ');
                $row['applications'] = collect([$card->applications, $notes])->filter()->implode('; ') ?: null;
                $row['correction'] = $this->correctionOf($card);
                $months[] = $row;

                continue;
            }

            foreach (['VL', 'SL'] as $code) {
                $mine = $monthEntries->where('leave_type_id', $ids[$code]);
                $row[strtolower($code)] = [
                    'earned' => round((float) $mine->whereIn('kind', [LedgerEntryKind::Accrual, LedgerEntryKind::Grant])->sum('amount'), 3),
                    'used' => round(-(float) $mine->whereIn('kind', [LedgerEntryKind::Deduction, LedgerEntryKind::Monetization, LedgerEntryKind::Lapse])->sum('amount'), 3) + 0.0,
                    'adjusted' => round((float) $mine->whereIn('kind', [LedgerEntryKind::Opening, LedgerEntryKind::Adjustment])->sum('amount'), 3),
                    'balance' => $balanceAt($code, $end),
                ];
            }
            $notes = $monthEntries->reject(fn (LeaveLedgerEntry $entry) => in_array($entry->kind, [LedgerEntryKind::Accrual, LedgerEntryKind::Grant], true))
                ->map(fn (LeaveLedgerEntry $entry) => $entry->leaveType->code.': '.$entry->particulars)->unique()->implode('; ');
            $row['particulars'] = null;
            $row['applications'] = $notes !== '' ? $notes : null;
            $months[] = $row;
        }

        return ['year' => $year, 'forward' => $forwardRow, 'months' => $months];
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
     * Corrects an entry's amount: reverses it and posts the right amount as
     * an adjustment, both dated today, in one step. The original stays on
     * the card, struck through.
     *
     * @return array{reversal: LeaveLedgerEntry, corrected: LeaveLedgerEntry}
     */
    public function correct(LeaveLedgerEntry $entry, float $amount, string $reason, User $actor): array
    {
        $amount = round($amount, 3);
        if ($entry->reverses_id !== null) {
            throw ValidationException::withMessages(['reason' => 'A reversal can\'t be corrected; post an adjustment instead.']);
        }
        // A tardiness and undertime charge is redone from its own screen, which keeps the month charged once.
        if (DtrLeaveDeduction::query()->where('leave_ledger_entry_id', $entry->id)->exists()) {
            throw ValidationException::withMessages(['reason' => 'This is a tardiness and undertime charge. Reverse it under Tardiness & Undertime and charge the month again.']);
        }
        if (abs($amount - (float) $entry->amount) < 0.0005) {
            throw ValidationException::withMessages(['amount' => 'That is already the entry\'s amount.']);
        }
        if (abs($amount) < 0.0005) {
            throw ValidationException::withMessages(['amount' => 'To cancel the entry altogether, reverse it instead.']);
        }

        return DB::transaction(function () use ($entry, $amount, $reason, $actor) {
            $reversal = $this->reverse($entry, $reason, $actor);
            $corrected = $this->post($entry->employee, $entry->leaveType, LedgerEntryKind::Adjustment, $amount, today(),
                "Correction of the {$entry->entry_date->toDateString()} entry ({$entry->particulars}): {$reason}", $actor);

            return ['reversal' => $reversal, 'corrected' => $corrected];
        });
    }

    // ── Imported leave card months ───────────────────────────────────

    /**
     * @return array<string, array<int, string>>
     */
    public function monthCorrectionRules(): array
    {
        $figure = ['nullable', 'numeric', 'between:-9999,9999'];

        return [
            'vl_earned' => $figure, 'vl_used' => $figure, 'vl_balance' => $figure,
            'sl_earned' => $figure, 'sl_used' => $figure, 'sl_balance' => $figure,
            'particulars' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'max:300'],
        ];
    }

    /**
     * Corrects a month imported from the leave card file. The figures as
     * first imported are kept; a later import leaves the month alone. Like
     * all card history, this never changes a balance: post an adjustment
     * for that.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array<int, string> the fields that changed
     */
    public function correctMonth(LeaveCardMonth $month, array $data, User $actor): array
    {
        $fields = ['vl_earned', 'vl_used', 'vl_balance', 'sl_earned', 'sl_used', 'sl_balance', 'particulars'];
        // Only the fields sent change (the brought-forward row has balances only).
        $new = collect($fields)->filter(fn (string $field) => array_key_exists($field, $data))
            ->mapWithKeys(fn (string $field) => [$field => match (true) {
                $data[$field] === null || $data[$field] === '' => null,
                $field === 'particulars' => $data[$field],
                default => round((float) $data[$field], 3),
            }]);

        $changed = $new->filter(fn ($value, string $field) => $value !== $month->{$field})->keys()->all();
        if ($changed === []) {
            throw ValidationException::withMessages(['reason' => 'Nothing was changed.']);
        }

        $month->forceFill([
            ...$new->all(),
            'imported_values' => $month->imported_values ?? collect($fields)->mapWithKeys(fn (string $field) => [$field => $month->{$field}])->all(),
            'correction_reason' => $data['reason'],
            'corrected_by' => $actor->id,
            'corrected_at' => now(),
        ])->save();

        return $changed;
    }

    /**
     * @return array{reason: ?string, by: ?string, at: string, imported: array<string, mixed>}|null
     */
    private function correctionOf(LeaveCardMonth $month): ?array
    {
        return $month->corrected_at === null ? null : [
            'reason' => $month->correction_reason,
            'by' => $month->corrector?->name,
            'at' => $month->corrected_at->toIso8601String(),
            'imported' => $month->imported_values ?? [],
        ];
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
     * Posts a month's accrual for everyone in service and eligible, except
     * those whose monthly leave card HR has imported (`card_managed`): their
     * months come from HR's workbook, so a month left blank there stays blank
     * instead of being filled in with the standard 1.25.
     *
     * @param  array<int, int>  $employeeIds  limit to these employees (none: everyone)
     * @return array{posted: int, already: int, not_eligible: int, card_managed: int}
     */
    public function accrueMonth(int $year, int $month, ?User $actor = null, array $employeeIds = []): array
    {
        $start = Carbon::create($year, $month, 1);
        $end = $start->copy()->endOfMonth()->startOfDay();
        $counts = ['posted' => 0, 'already' => 0, 'not_eligible' => 0, 'card_managed' => 0];

        $types = LeaveType::query()->active()->whereNotNull('monthly_accrual')->where('monthly_accrual', '>', 0)->get();
        $cardManaged = DB::table('leave_card_months')->distinct()->pluck('employee_id')->flip();

        DB::transaction(function () use ($types, $start, $end, $year, $month, $actor, $employeeIds, $cardManaged, &$counts) {
            foreach ($this->inServiceDuring($start, $end, $employeeIds) as $employee) {
                if ($cardManaged->has($employee->id)) {
                    $counts['card_managed']++;

                    continue;
                }
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
     * Reminds HR (anyone with leave.manage) that people whose leave card was
     * imported were not accrued for a month, so the next workbook needs
     * importing for their credits to move. Once per person per month, so
     * re-running the accrual doesn't repeat it.
     *
     * @return int how many people were reminded
     */
    public function remindCardImportDue(int $year, int $month, int $people): int
    {
        if ($people < 1) {
            return 0;
        }

        $label = Carbon::create($year, $month, 1)->format('F Y');
        $title = "Import the leave card for {$label}";
        $recipients = User::query()->where('status', 'active')
            ->whereHas('activeRoles.permissions', fn ($query) => $query->where('name', PermissionName::LeaveManage->value))
            ->get()
            ->reject(fn (User $user) => $user->notifications()->where('data->title', $title)->exists());

        Notification::send($recipients, new HrUpdate(
            $title,
            "{$people} ".($people === 1 ? 'employee has' : 'employees have')." an imported leave card and were not accrued for {$label}. Import the latest leave card workbook so their credits are up to date.",
            '/admin/hr/leave/sync',
        ));

        return $recipients->count();
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

        // Run during the year: also whoever joined since January 1.
        if ($year === today()->year && today()->gt($january)) {
            $due = $this->grantDue($actor);
            $counts['granted'] += $due['granted'];
        }

        return $counts;
    }

    /**
     * This year's annual grants (SPL, Solo Parent, Wellness Leave, …) for
     * anyone in service today who doesn't have them yet: people who
     * assumed duty after January 1, and leave types switched on mid-year.
     * CSC makes these available from the date of assumption of duty (e.g.
     * MC No. 01, s. 2026, 2). Dated January 1, or the first day in service
     * if later. Re-running is safe; the monthly accrual run calls it.
     *
     * @return array{granted: int, already: int, not_eligible: int}
     */
    public function grantDue(?User $actor = null): array
    {
        $counts = ['granted' => 0, 'already' => 0, 'not_eligible' => 0];
        $today = today();
        $year = $today->year;
        $january = Carbon::create($year, 1, 1);
        $types = LeaveType::query()->active()->where('annual_grant', '>', 0)->get();

        DB::transaction(function () use ($types, $today, $year, $january, $actor, &$counts) {
            foreach ($this->inServiceDuring($today, $today) as $employee) {
                foreach ($types as $type) {
                    if ($this->alreadyPosted($employee, $type, LedgerEntryKind::Grant, $year)) {
                        $counts['already']++;

                        continue;
                    }
                    if (! $this->eligible($employee, $type, $today)) {
                        $counts['not_eligible']++;

                        continue;
                    }

                    $started = $employee->original_appointment_date;
                    $date = $started && $started->gt($january) ? $started->copy() : $january;
                    $this->post($employee, $type, LedgerEntryKind::Grant, (float) $type->annual_grant, $date, "{$type->code} for {$year}", $actor, $year);
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
            $reasons[] = $employee->is_solo_parent && $employee->solo_parent_id_expiry?->lt($on->copy()->startOfDay())
                ? "Your Solo Parent ID expired on {$employee->solo_parent_id_expiry->format('F j, Y')}; give HR your renewed ID to file {$type->name}."
                : "{$type->name} needs a valid Solo Parent ID on file with HR.";
        }

        return $reasons;
    }

    /**
     * The first reason someone may not have this kind of leave, short enough
     * to sit beside its name in a list ("needs 24 months of service"), and
     * whether it can never change for them (a leave for the other sex), in
     * which case the leave is better left off their list altogether.
     *
     * @return array{label: string, permanent: bool}|null null when eligible
     */
    public function shortReason(Employee $employee, LeaveType $type, ?Carbon $on = null): ?array
    {
        $on ??= today();
        $sex = $employee->user->sex;

        if ($type->sex && $sex !== $type->sex) {
            return $sex
                ? ['label' => 'for '.($type->sex === 'female' ? 'women' : 'men').' only', 'permanent' => true]
                : ['label' => 'your sex isn\'t on your record; ask HR', 'permanent' => false];
        }
        if ($type->eligible_statuses && ! in_array($employee->employment_status?->value, $type->eligible_statuses, true)) {
            return ['label' => $employee->employment_status ? "not for {$employee->employment_status->label()} employees" : 'employment status not set; ask HR', 'permanent' => false];
        }
        if ($type->min_service_months > 0) {
            $since = $employee->original_appointment_date;
            if (! $since || $since->diffInMonths($on) < $type->min_service_months) {
                return ['label' => "needs {$type->min_service_months} months of service", 'permanent' => false];
            }
        }
        if ($type->requires_solo_parent_id && ! $employee->hasValidSoloParentId($on)) {
            return ['label' => $employee->is_solo_parent && $employee->solo_parent_id_expiry?->lt($on->copy()->startOfDay()) ? 'Solo Parent ID expired' : 'needs a Solo Parent ID on file', 'permanent' => false];
        }

        return null;
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
     * @param  array<int, int>  $employeeIds  limit to these employees (none: everyone)
     * @return Collection<int, Employee>
     */
    private function inServiceDuring(Carbon $start, Carbon $end, array $employeeIds = []): Collection
    {
        return Employee::query()
            ->with('user')
            ->when($employeeIds !== [], fn ($query) => $query->whereIn('id', $employeeIds))
            ->where(fn ($query) => $query->whereNull('original_appointment_date')->orWhereDate('original_appointment_date', '<=', $end))
            ->where(fn ($query) => $query->whereNull('separation_date')->orWhereDate('separation_date', '>=', $start))
            ->get();
    }
}
