<?php

namespace App\Support\HR\Leave;

use App\Enums\LedgerEntryKind;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Brings IMIS in line with HR's leave card workbook, which stays the master
 * file: one-way, workbook to IMIS.
 *
 * For each person on the workbook it (1) refreshes the monthly card
 * history and (2) compares the Vacation and Sick Leave balance the card
 * shows at its last month with the ledger's balance at that date. A
 * difference is posted as one visible entry dated that month-end (an
 * "Opening balance" when the person has no entries of that type yet,
 * otherwise an "Adjustment"), so the ledger stays append-only and every
 * correction can be seen, and reversed, on the leave card. Nothing is
 * posted when the two already agree, so syncing again is safe.
 */
class LeaveCardSync
{
    /** A difference bigger than this many days is flagged for a second look. */
    public const LARGE = 5.0;

    public function __construct(private LeaveCardWorkbook $workbook, private LeaveCredits $credits) {}

    /**
     * What a sync of this workbook would do, person by person.
     *
     * @return Collection<int, array<string, mixed>> the history results, each with a `sync` plan (null when the sheet can't be used)
     */
    public function plan(string $path): Collection
    {
        $types = LeaveType::query()->whereIn('code', ['VL', 'SL'])->get()->keyBy('code');

        return $this->workbook->history($this->workbook->read($path))->map(function (array $row) use ($types) {
            if (! $row['ready']) {
                return $row + ['sync' => null];
            }

            $anchor = $this->anchor($row['months']);
            $date = Carbon::parse($anchor['date']);
            $employee = $row['employee'];
            $sync = ['date' => $anchor['date'], 'status' => 'in_sync'];

            foreach (['VL' => 'vl', 'SL' => 'sl'] as $code => $key) {
                $card = $anchor[$key];
                if ($card === null) {
                    $sync[$key] = null;

                    continue;
                }

                $type = $types[$code];
                $entries = $employee->leaveLedger()->where('leave_type_id', $type->id);
                $hasEntries = (clone $entries)->exists();
                $hasEarlier = (clone $entries)->whereDate('entry_date', '<=', $date)->exists();
                $system = $this->credits->balance($employee, $type, $date);
                $diff = round($card - $system, 3);

                $sync[$key] = ['card' => $card, 'system' => $system, 'diff' => $diff,
                    'kind' => ! $hasEntries && $card >= 0 ? LedgerEntryKind::Opening->value : LedgerEntryKind::Adjustment->value];

                if ($hasEntries && ! $hasEarlier) {
                    // The system's entries all start after the card's last month: a
                    // difference here isn't one HR can read, so only the history is saved.
                    $sync['status'] = 'check';
                    $row['warnings'][] = "{$code}: the system's entries start after the card's last month; the balance was not adjusted.";
                } elseif (abs($diff) >= 0.0005 && $sync['status'] !== 'check') {
                    // Worst wins: check, then large, then an ordinary adjustment.
                    $sync['status'] = abs($diff) > self::LARGE ? 'large' : ($sync['status'] === 'large' ? 'large' : 'adjust');
                }
            }

            return $row + ['sync' => $sync];
        });
    }

    /**
     * Saves the selected people's history and posts their balance
     * corrections.
     *
     * @param  Collection<int, array<string, mixed>>  $plan  from plan()
     * @param  array<int, int>  $employeeIds  who to sync
     * @return array{people: int, months: int, adjustments: int, openings: int}
     */
    public function apply(Collection $plan, array $employeeIds, User $actor, string $file): array
    {
        $selected = $plan->filter(fn (array $row) => $row['ready'] && in_array($row['employee']->id, $employeeIds, true))->values();
        $counts = ['people' => $selected->count(), 'months' => 0, 'adjustments' => 0, 'openings' => 0];
        $types = LeaveType::query()->whereIn('code', ['VL', 'SL'])->get()->keyBy('code');

        DB::transaction(function () use ($selected, $types, $actor, $file, &$counts) {
            $counts['months'] = $this->workbook->applyHistory($selected);

            foreach ($selected as $row) {
                if ($row['sync']['status'] === 'check') {
                    continue;
                }
                foreach (['VL' => 'vl', 'SL' => 'sl'] as $code => $key) {
                    $line = $row['sync'][$key] ?? null;
                    if ($line === null || abs($line['diff']) < 0.0005) {
                        continue;
                    }

                    (new LeaveLedgerEntry)->forceFill([
                        'employee_id' => $row['employee']->id,
                        'leave_type_id' => $types[$code]->id,
                        'entry_date' => $row['sync']['date'],
                        'kind' => LedgerEntryKind::from($line['kind']),
                        'amount' => $line['diff'],
                        'particulars' => mb_substr(sprintf('Synced with the leave card file (%s): card %s, system had %s', basename($file), $line['card'], $line['system']), 0, 500),
                        'recorded_by' => $actor->id,
                    ])->save();
                    $counts[$line['kind'] === LedgerEntryKind::Opening->value ? 'openings' : 'adjustments']++;
                }
            }
        });

        return $counts;
    }

    /**
     * The card's last month with a balance (or the year's brought-forward
     * balance, dated December 31 of the year before).
     *
     * @param  array<int, array<string, mixed>>  $months  oldest first
     * @return array{date: string, vl: ?float, sl: ?float}
     */
    private function anchor(array $months): array
    {
        // Oldest first, a year's brought-forward row (month 0) comes before its months, so the
        // last item is the newest: a card that ends on a new year's brought-forward row ends there.
        $last = end($months);
        $end = $last['month'] > 0
            ? Carbon::create($last['year'], $last['month'], 1)->endOfMonth()
            : Carbon::create($last['year'] - 1, 12, 31);

        return ['date' => $end->toDateString(), 'vl' => $last['vl_balance'], 'sl' => $last['sl_balance']];
    }
}
