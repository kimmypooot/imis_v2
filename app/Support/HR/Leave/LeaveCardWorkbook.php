<?php

namespace App\Support\HR\Leave;

use App\Enums\LedgerEntryKind;
use App\Models\LeaveCardMonth;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Tables\XlsxReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opening Vacation and Sick Leave balances from HR's leave-card workbook
 * (one sheet per person, a block of rows per year, newest first).
 *
 * For each person it reads the latest year's block, finds the last month
 * with credits earned (or the "Balance Brought Forward" row if the year
 * has none yet) and takes that month-end VL and SL balance: column C is
 * VL earned, E the VL balance, G SL earned, I the SL balance. Only an
 * employee with no ledger entries for that leave type is given an opening
 * entry, so a person already carried over (from the legacy LMS, or by HR)
 * is left alone and re-running is safe. Per-year grants (SPL, Wellness)
 * are not taken: the system posts those itself.
 */
class LeaveCardWorkbook
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** What an imported opening entry's particulars start with. */
    public const PARTICULARS = 'Balance carried over from the leave card workbook';

    /** Sheets that aren't a person's card. */
    private const REFERENCE_SHEETS = ['computation to earn lc et al', 'time ref', 'running balance', 'leave credit balance 31dec2023'];

    /**
     * @return array<string, array<int, array<string, string>>> sheet name => row => column => value
     */
    public function read(string $path): array
    {
        return (new XlsxReader)->read($path);
    }

    /**
     * @param  array<string, array<int, array<string, string>>>  $sheets
     * @param  ?string  $asOf  "YYYY-MM": ignore months after it
     * @return Collection<int, array<string, mixed>> one result per person's sheet
     */
    public function check(array $sheets, ?string $asOf = null): Collection
    {
        $users = User::query()->with('employee')->whereHas('employee')->get();
        $types = LeaveType::query()->whereIn('code', ['VL', 'SL'])->get()->keyBy('code');

        $results = collect();
        foreach ($sheets as $name => $rows) {
            if (in_array(mb_strtolower(trim($name)), self::REFERENCE_SHEETS, true)) {
                continue;
            }

            if ($rows === []) {
                $results->push(['sheet' => $name, 'name' => $name, 'employee' => null, 'date' => null, 'vl' => null, 'sl' => null,
                    'warnings' => ['The sheet is empty.'], 'errors' => [], 'ready' => false, 'entries' => []]);

                continue;
            }
            $rows = $this->aligned($rows);

            $result = ['sheet' => $name, 'name' => $this->cardName($rows) ?? $name, 'employee' => null, 'date' => null,
                'vl' => null, 'sl' => null, 'warnings' => [], 'errors' => [], 'ready' => false, 'entries' => []];

            $latest = $this->latest($rows, $asOf);
            if ($latest === null) {
                $result['errors'][] = 'No leave card block with a balance was found.';
                $results->push($result);

                continue;
            }
            [$result['date'], $result['vl'], $result['sl']] = [$latest['date'], $latest['vl'], $latest['sl']];

            [$user, $note] = $this->person($result['name'], $name, $users);
            if ($user === null) {
                $result['errors'][] = $note;
                $results->push($result);

                continue;
            }
            if ($note !== null) {
                $result['warnings'][] = $note;
            }
            $result['employee'] = $user->employee;
            $result['holder'] = $user->name;

            foreach (['VL' => $latest['vl'], 'SL' => $latest['sl']] as $code => $balance) {
                $type = $types->get($code);
                if ($type === null || $balance === null) {
                    $result['warnings'][] = "No {$code} balance on the card.";

                    continue;
                }
                if ($balance < 0) {
                    $result['errors'][] = "{$code} balance is negative ({$balance}); post it by hand.";

                    continue;
                }
                if ($user->employee->leaveLedger()->where('leave_type_id', $type->id)->exists()) {
                    $result['warnings'][] = "{$code} already has ledger entries; left alone.";

                    continue;
                }
                $result['entries'][] = ['type' => $type, 'amount' => round($balance, 3)];
            }

            $result['ready'] = $result['errors'] === [] && $result['entries'] !== [];
            $results->push($result);
        }

        return $results;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $checked
     * @return int opening entries posted
     */
    public function apply(Collection $checked, string $file): int
    {
        $posted = 0;
        DB::transaction(function () use ($checked, $file, &$posted) {
            foreach ($checked->where('ready', true) as $result) {
                foreach ($result['entries'] as $entry) {
                    (new LeaveLedgerEntry)->forceFill([
                        'employee_id' => $result['employee']->id,
                        'leave_type_id' => $entry['type']->id,
                        'entry_date' => $result['date'],
                        'kind' => LedgerEntryKind::Opening,
                        'amount' => $entry['amount'],
                        'particulars' => self::PARTICULARS.' ('.mb_substr(basename($file), 0, 120).')',
                    ])->save();
                    $posted++;
                }
            }
        });

        return $posted;
    }

    /**
     * Every month of every year block on each person's card: what the paper
     * card shows (VL and SL earned, absences/undertime with pay, month-end
     * balance, particulars, applications noted). Display history only —
     * balances come from the ledger. Months with no credits entered yet
     * (the rest of the current year) are skipped.
     *
     * @param  array<string, array<int, array<string, string>>>  $sheets
     * @param  ?string  $asOf  "YYYY-MM": ignore months after it
     * @return Collection<int, array<string, mixed>> one result per person's sheet
     */
    public function history(array $sheets, ?string $asOf = null): Collection
    {
        $users = User::query()->with('employee')->whereHas('employee')->get();
        $cap = $asOf ? Carbon::createFromFormat('!Y-m', $asOf)->endOfMonth() : null;

        $results = collect();
        foreach ($sheets as $name => $rows) {
            if ($rows === [] || in_array(mb_strtolower(trim($name)), self::REFERENCE_SHEETS, true)) {
                continue;
            }
            $rows = $this->aligned($rows);

            $result = ['sheet' => $name, 'name' => $this->cardName($rows) ?? $name, 'employee' => null, 'months' => [],
                'warnings' => [], 'errors' => [], 'ready' => false];

            $months = [];
            foreach ($rows as $number => $cells) {
                if (($cells['A'] ?? '') !== 'PERIOD') {
                    continue;
                }
                foreach ($this->blockMonths($rows, $number, (int) ($rows[$number - 1]['B'] ?? 0)) as $month) {
                    if ($cap && Carbon::create($month['year'], $month['month'], 1)->gt($cap)) {
                        continue;
                    }
                    $months[$month['year'] * 100 + $month['month']] = $month;
                }
            }
            ksort($months);
            $result['months'] = array_values($months);
            if ($result['months'] === []) {
                $result['errors'][] = 'No months with credits were found on the card.';
                $results->push($result);

                continue;
            }
            $result['warnings'] = $this->chainWarnings($result['months']);

            [$user, $note] = $this->person($result['name'], $name, $users);
            if ($user === null) {
                $result['errors'][] = $note;
            } else {
                $result['employee'] = $user->employee;
                $result['holder'] = $user->name;
                if ($note !== null) {
                    $result['warnings'][] = $note;
                }
                $result['ready'] = true;
            }
            $results->push($result);
        }

        return $results;
    }

    /**
     * Saves each ready person's months; re-running updates them in place.
     *
     * @param  Collection<int, array<string, mixed>>  $checked
     * @return int months saved
     */
    public function applyHistory(Collection $checked): int
    {
        $saved = 0;
        DB::transaction(function () use ($checked, &$saved) {
            foreach ($checked->where('ready', true) as $result) {
                foreach ($result['months'] as $month) {
                    LeaveCardMonth::query()->updateOrCreate(
                        ['employee_id' => $result['employee']->id, 'year' => $month['year'], 'month' => $month['month']],
                        collect($month)->except(['year', 'month'])->all(),
                    );
                    $saved++;
                }
            }
        });

        return $saved;
    }

    /**
     * The months of one year block: a period row (label in A, credits
     * earned in C/G) followed by its month-end balance row (E and I).
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function blockMonths(array $rows, int $header, int $blockYear): array
    {
        $months = [];
        for ($line = $header + 1; $line < $header + 60; $line++) {
            $cell = $rows[$line] ?? [];
            if (($cell['A'] ?? '') === 'PERIOD' || ($rows[$line + 1]['A'] ?? '') === 'PERIOD') {
                break;
            }
            $label = $cell['A'] ?? '';
            if (str_starts_with($label, 'Balance Brought Forward')) {
                $months[] = [
                    'year' => $blockYear ?: ((preg_match('/\b(20\d{2})\b/', $label, $found) ? (int) $found[1] : 0) + 1),
                    'month' => 0,
                    'vl_earned' => null, 'vl_used' => null, 'vl_balance' => $this->number($cell['E'] ?? null),
                    'sl_earned' => null, 'sl_used' => null, 'sl_balance' => $this->number($cell['I'] ?? null),
                    'particulars' => $this->note($label, 500),
                    'applications' => null,
                ];

                continue;
            }
            $month = self::MONTHS[mb_strtolower(substr($label, 0, 3))] ?? null;
            if ($month === null || ! is_numeric($cell['C'] ?? null)) {
                continue;
            }
            $year = preg_match('/\b(20\d{2})\b/', $label, $found) ? (int) $found[1] : $blockYear;
            $next = $rows[$line + 1] ?? [];

            $entry = [
                'year' => $year,
                'month' => $month,
                'vl_earned' => $this->number($cell['C'] ?? null),
                'vl_used' => $this->number($cell['D'] ?? null),
                'vl_balance' => $this->number($next['E'] ?? null),
                'sl_earned' => $this->number($cell['G'] ?? null),
                'sl_used' => $this->number($cell['H'] ?? null),
                'sl_balance' => $this->number($next['I'] ?? null),
                'particulars' => $this->note($cell['B'] ?? null, 500),
                'applications' => $this->note(implode('; ', array_filter([$cell['K'] ?? null, $next['K'] ?? null])), 1000),
            ];
            if ($entry['vl_balance'] !== null || $entry['sl_balance'] !== null) {
                $months[] = $entry;
            }
        }

        return $months;
    }

    /**
     * Months whose balance isn't the last one plus earned minus used, within
     * a year (the year's brought-forward balance starts the chain; a
     * difference between December and the next year's brought-forward is a
     * year-end adjustment on the card, not an error). Kept, but listed for
     * HR to check against the paper card.
     *
     * @param  array<int, array<string, mixed>>  $months  oldest first
     * @return array<int, string>
     */
    private function chainWarnings(array $months): array
    {
        $warnings = [];
        $previous = null;
        foreach ($months as $month) {
            if ($month['month'] === 0) {
                $previous = $month;

                continue;
            }
            foreach (['vl' => 'VL', 'sl' => 'SL'] as $key => $code) {
                $before = ($previous['year'] ?? null) === $month['year'] ? ($previous["{$key}_balance"] ?? null) : null;
                $balance = $month["{$key}_balance"];
                if ($before === null || $balance === null || $month["{$key}_earned"] === null) {
                    continue;
                }
                $expected = $before + $month["{$key}_earned"] - ($month["{$key}_used"] ?? 0);
                if (abs($expected - $balance) > 0.01) {
                    $warnings[] = sprintf('%s %d-%02d: balance %s, but the month before plus earned less used is %s.', $code, $month['year'], $month['month'], $balance, round($expected, 3));
                }
            }
            $previous = $month;
        }

        return count($warnings) > 3 ? [...array_slice($warnings, 0, 3), '…and '.(count($warnings) - 3).' more months that do not add up.'] : $warnings;
    }

    private function number(?string $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 3) : null;
    }

    private function note(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Some cards sit a column or more to the right (the PERIOD heading is
     * in B, not A): shifts every row left so PERIOD is column A.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, string>>
     */
    private function aligned(array $rows): array
    {
        $offset = 0;
        foreach ($rows as $cells) {
            $column = array_search('PERIOD', $cells, true);
            if ($column !== false) {
                $offset = $this->columnNumber($column) - 1;
                break;
            }
        }
        if ($offset === 0) {
            return $rows;
        }

        return array_map(function (array $cells) use ($offset) {
            $shifted = [];
            foreach ($cells as $column => $value) {
                $number = $this->columnNumber($column) - $offset;
                if ($number >= 1) {
                    $shifted[$this->columnLetters($number)] = $value;
                }
            }

            return $shifted;
        }, $rows);
    }

    /** The name written at the top of the card, in any of its first rows. */
    private function cardName(array $rows): ?string
    {
        foreach (range(1, 4) as $line) {
            $value = $rows[$line]['A'] ?? null;
            if ($value !== null && $value !== '' && ! is_numeric($value) && $value !== 'PERIOD') {
                return $value;
            }
        }

        return null;
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = $number * 26 + (ord($letter) - 64);
        }

        return $number;
    }

    private function columnLetters(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }

    /**
     * The newest year block's last month-end balance.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return ?array{date: string, vl: ?float, sl: ?float}
     */
    private function latest(array $rows, ?string $asOf): ?array
    {
        $cap = $asOf ? Carbon::createFromFormat('!Y-m', $asOf)->endOfMonth() : null;
        $best = null;

        foreach ($rows as $number => $cells) {
            if (($cells['A'] ?? '') !== 'PERIOD') {
                continue;
            }
            $year = (int) ($rows[$number - 1]['B'] ?? 0);
            if ($year < 1990 || ($cap && $year > $cap->year)) {
                continue;
            }

            $found = null;
            $forward = null;
            for ($line = $number + 1; $line < $number + 40; $line++) {
                $cell = $rows[$line] ?? [];
                if (($cell['A'] ?? '') === 'PERIOD' || ($rows[$line + 1]['A'] ?? '') === 'PERIOD') {
                    break;
                }
                if (str_starts_with($cell['A'] ?? '', 'Balance Brought Forward')) {
                    $forward = $this->balances($cell, $year - 1, 12);

                    continue;
                }
                $month = self::MONTHS[mb_strtolower(substr($cell['A'] ?? '', 0, 3))] ?? null;
                if ($month === null || ! is_numeric($cell['C'] ?? null) || (float) $cell['C'] <= 0) {
                    continue;
                }
                if ($cap && Carbon::create($year, $month, 1)->gt($cap)) {
                    continue;
                }
                $found = $this->balances($rows[$line + 1] ?? [], $year, $month) ?? $found;
            }

            $found ??= $forward;
            if ($found !== null && ($best === null || $found['date'] > $best['date'])) {
                $best = $found;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, string>  $cells
     * @return ?array{date: string, vl: ?float, sl: ?float}
     */
    private function balances(array $cells, int $year, int $month): ?array
    {
        $vl = is_numeric($cells['E'] ?? null) ? (float) $cells['E'] : null;
        $sl = is_numeric($cells['I'] ?? null) ? (float) $cells['I'] : null;
        if ($vl === null && $sl === null) {
            return null;
        }

        return ['date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(), 'vl' => $vl, 'sl' => $sl];
    }

    /**
     * Matches "KIM BENEDICK M. BANOYO" to the account whose surname ends
     * the name and whose first name shares a word with it.
     *
     * @param  Collection<int, User>  $users
     * @return array{0: ?User, 1: ?string}
     */
    private function person(string $full, string $sheet, Collection $users): array
    {
        $text = trim(preg_replace('/^\s*name\s*:/i', '', $full));
        $orders = [$text];
        if (str_contains($text, ',')) {
            // "DELA CRUZ, JUAN P." is surname first.
            [$last, $rest] = array_map('trim', explode(',', $text, 2));
            $orders = [$rest.' '.$last];
        } elseif (str_contains($text, ' ')) {
            // No comma: "JUAN P. DELA CRUZ", else "ESTOQUE RYAN JOSE M." (surname first).
            [$first, $rest] = explode(' ', $text, 2);
            $orders[] = $rest.' '.$first;
        }

        $candidates = collect();
        foreach ($orders as $order) {
            $candidates = $this->candidates($order, $users);
            if ($candidates->isNotEmpty()) {
                break;
            }
        }

        if ($candidates->count() > 1) {
            $inService = $candidates->filter(fn (User $user) => ! $user->employee->isSeparated())->values();
            if ($inService->count() === 1) {
                return [$inService->first(), 'Matches more than one account; took the one in service.'];
            }

            return [null, "Sheet \"{$sheet}\" matches more than one account: ".$candidates->map(fn (User $user) => "#{$user->id} ({$user->username})")->implode(', ').'.'];
        }

        return $candidates->isEmpty()
            ? [null, "No IMIS employee for \"{$full}\" (sheet {$sheet})."]
            : [$candidates->first(), null];
    }

    /**
     * Accounts whose surname ends the name (a hyphenated surname counts by
     * either part) and whose first name shares a word with it.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function candidates(string $text, Collection $users): Collection
    {
        $squashed = $this->squash($text);
        $words = $this->words($text);

        return $users->filter(function (User $user) use ($squashed, $words) {
            $surnames = array_filter([$this->squash($user->last_name), ...array_map($this->squash(...), explode('-', (string) $user->last_name))]);

            return array_filter($surnames, fn (string $surname) => str_ends_with($squashed, $surname)) !== []
                && array_intersect($words, $this->words($user->first_name)) !== [];
        })->values();
    }

    /** @return array<int, string> the name's words, letters only, without initials */
    private function words(?string $name): array
    {
        return array_values(array_filter(
            array_map(fn (string $word) => $this->squash($word), preg_split('/[\s.]+/', (string) $name)),
            fn (string $word) => strlen($word) >= 3,
        ));
    }

    private function squash(?string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value);

        return preg_replace('/[^a-z]/', '', mb_strtolower($ascii === false ? (string) $value : $ascii));
    }
}
