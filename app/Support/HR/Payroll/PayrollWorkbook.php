<?php

namespace App\Support\HR\Payroll;

use App\Enums\PayCategory;
use App\Enums\PayComponentKind;
use App\Enums\PayComputation;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Tables\XlsxReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads the accounting office's monthly "Final Payroll" workbook and checks
 * what IMIS would pay against it, so HR can run IMIS beside the workbook
 * before relying on it. Read-only: nothing here saves payroll data.
 *
 * Each employee takes three rows on the "Final Payroll" sheet: the basic
 * salary (and surname), then PERA with the first line of deductions (and
 * the first name), then a second line of loans. The net pay is the sum of
 * every deduction column, recomputed here so a row whose own formula
 * strays (a refund added back, a range that misses a column) shows up.
 */
class PayrollWorkbook
{
    private const SHEET = 'final payroll';

    /** Deduction columns on the PERA row (first line) and the loans row (second line). */
    private const FIRST_LINE = [
        'I' => 'GSIS Consolidated Loan', 'J' => 'GSIS Regular Policy Loan', 'K' => 'GSIS Solar Loan', 'L' => 'GSIS MPL Lite',
        'M' => 'GSIS MPL', 'P' => 'GSIS HELP', 'S' => 'Pag-IBIG Housing Loan', 'T' => 'Pag-IBIG MP2',
        'U' => 'Withholding Tax', 'V' => 'GSIS EAL/Flexi', 'W' => 'Welfare Fund Contribution', 'X' => 'CSC PAL',
        'Y' => 'PINTIG', 'Z' => 'Other (column Z)', 'AA' => 'Other (column AA)', 'AB' => 'Other (column AB)', 'AC' => 'Other (column AC)',
    ];

    private const SECOND_LINE = [
        'I' => 'GSIS Real Estate Loan', 'J' => 'GSIS Emergency Loan', 'K' => 'GSIS CPL', 'L' => 'GSIS OPT Life',
        'M' => 'GSIS (column M, second line)', 'P' => 'CEAP', 'S' => 'Pag-IBIG MP Loan', 'T' => 'SAFE Loan',
        'U' => 'Withholding Tax (second line)', 'V' => 'eCA', 'W' => 'Welfare Fund MPL', 'X' => 'GPL',
        'Y' => 'PINTIG (second line)', 'Z' => 'Other (column Z, second line)', 'AA' => 'Other (column AA, second line)',
        'AB' => 'Other (column AB, second line)', 'AC' => 'Other (column AC, second line)',
    ];

    public function __construct(private readonly XlsxReader $reader, private readonly Payrolls $payrolls) {}

    /**
     * @return array<string, array<int, array<string, string>>> sheet name => row => column => value
     */
    public function read(string $path): array
    {
        return $this->reader->read($path);
    }

    /**
     * @param  array<string, array<int, array<string, string>>>  $sheets
     * @return Collection<int, array<string, mixed>>
     */
    public function employees(array $sheets): Collection
    {
        $rows = null;
        foreach ($sheets as $name => $sheet) {
            if (mb_strtolower(trim($name)) === self::SHEET) {
                $rows = $sheet;
            }
        }
        if ($rows === null) {
            throw new RuntimeException('The workbook has no "Final Payroll" sheet.');
        }
        ksort($rows);

        $found = collect();
        foreach ($rows as $number => $cells) {
            $basic = $this->number($cells['D'] ?? null);
            $next = $rows[$number + 1] ?? [];
            // Usually the deductions start on the PERA row; in some blocks they sit on the salary row.
            $onSalaryRow = $this->number($cells['F'] ?? null) !== null && $this->number($next['F'] ?? null) === null;
            if ($basic === null || $basic < 5000 || $this->number($next['D'] ?? null) === null || ($this->number($next['F'] ?? null) === null && ! $onSalaryRow)) {
                continue;
            }

            $first = $onSalaryRow ? $cells : $next;
            $loans = $onSalaryRow ? $next : ($rows[$number + 2] ?? []);
            $continued = $this->number($loans['D'] ?? null) === null ? trim((string) ($loans['B'] ?? '')) : '';
            $deductions = [];
            foreach ([[self::FIRST_LINE, $first], [self::SECOND_LINE, $loans]] as [$labels, $line]) {
                foreach ($labels as $column => $label) {
                    $amount = $this->number($line[$column] ?? null);
                    if ($amount !== null && abs($amount) > 0.004) {
                        $deductions[$label] = round(($deductions[$label] ?? 0) + $amount, 2);
                    }
                }
            }

            // Gross and net sit on the salary row in some blocks, the PERA row in others.
            $columns = fn (string $column) => $this->number($first[$column] ?? null) ?? 0.0;
            $either = fn (string $column) => $this->number($next[$column] ?? null) ?? $this->number($cells[$column] ?? null);
            $mandatory = ['gsis_ps' => $columns('F'), 'phic_ps' => $columns('N'), 'hdmf_ps' => $columns('Q')];
            $pera = $this->number($next['D'] ?? null) ?? 0.0;
            $gross = $either('E') ?? round($basic + $pera, 2);

            // A wrapped name ("BANOYO, KIM" over "BENEDICK M.") keeps its first word on the surname row.
            [$surname, $given] = array_pad(array_map('trim', explode(',', trim((string) ($cells['B'] ?? '')), 2)), 2, '');
            $given = trim(rtrim($given, ','));
            $recomputed = round($gross - $mandatory['gsis_ps'] - $mandatory['phic_ps'] - $mandatory['hdmf_ps'] - array_sum($deductions), 2);

            $found->push([
                'row' => $number,
                'surname' => $surname,
                'first_name' => trim($given.' '.((string) ($next['B'] ?? '')).' '.$continued),
                'designation' => trim((string) ($next['C'] ?? $cells['C'] ?? '')),
                'basic' => $basic,
                'pera' => $pera,
                'gross' => $gross,
                ...$mandatory,
                'gsis_employer' => $columns('G'),
                'withholding_tax' => ($deductions['Withholding Tax'] ?? 0.0) + ($deductions['Withholding Tax (second line)'] ?? 0.0),
                'other_deductions' => $deductions,
                'net_in_workbook' => $either('AD'),
                'net_recomputed' => $recomputed,
            ]);
        }

        return $found;
    }

    /**
     * Sets each workbook employee beside IMIS's payroll for the month. With
     * $simulate, the workbook's deductions and loans are first loaded onto
     * the matched employees' profiles and the payroll generated afresh, all
     * inside a transaction that is rolled back.
     *
     * @param  Collection<int, array<string, mixed>>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    public function compare(Collection $employees, string $month, User $actor, bool $simulate = false): Collection
    {
        $matches = $this->matchAll($employees);
        [$entries, $generated] = $simulate
            ? [$this->simulated($employees->values(), $matches, $month, $actor), true]
            : $this->entriesFor($month, $actor);

        return $employees->values()->map(function (array $row, int $index) use ($matches, $entries, $generated, $simulate) {
            [$user, $note] = $matches[$index];
            $entry = $user ? $entries->get($user->employee->id) : null;
            $other = round(array_sum($row['other_deductions']), 2);
            $result = [...$row, 'user' => $user?->name, 'note' => $note, 'imis' => null];

            if ($entry === null) {
                $result['note'] ??= $user === null ? null : 'On no IMIS payroll for the month (no complete plantilla period, or separated).';

                return $result;
            }

            $lines = $entry->lines->groupBy('label')->map(fn ($group) => round($group->sum(fn ($line) => (float) $line->amount), 2));
            $line = fn (string $label) => (float) ($lines[$label] ?? 0.0);
            $imis = [
                'basic' => (float) $entry->basic_salary,
                'pera' => $line('PERA'),
                'gsis_ps' => $line('GSIS Personal Share'),
                'phic_ps' => $line('PhilHealth'),
                'hdmf_ps' => $line('Pag-IBIG'),
                'generated' => $generated,
            ];
            $imis['gross'] = round($imis['basic'] + $imis['pera'], 2);
            // Simulated: IMIS's own net, with the workbook's extras loaded. Otherwise what its net would be if they were.
            $imis['net_with_workbook_extras'] = $simulate
                ? (float) $entry->net_pay
                : round($imis['gross'] - $imis['gsis_ps'] - $imis['phic_ps'] - $imis['hdmf_ps'] - $other, 2);
            $result['imis'] = $imis;
            $result['net_variance'] = round($imis['net_with_workbook_extras'] - $row['net_recomputed'], 2);
            // What the salary gap alone explains: IMIS pays that much more basic, and deducts that much more GSIS and PhilHealth.
            $result['explained_by_salary'] = round(($imis['basic'] - $row['basic']) - ($imis['gsis_ps'] - $row['gsis_ps']) - ($imis['phic_ps'] - $row['phic_ps']), 2);

            return $result;
        });
    }

    /**
     * Sets the workbook beside a saved payroll run, person by person: who
     * agrees to the centavo, and for each who doesn't, what accounts for
     * the difference (an effect on the net pay for each item that differs,
     * so the effects add up to the variance). Also lists the people on the
     * run who are not in the workbook. Reads only.
     *
     * @param  Collection<int, array<string, mixed>>  $employees
     * @return array{rows: array<int, array<string, mixed>>, only_in_imis: array<int, string>, summary: array<string, int>}
     */
    public function reconcile(Collection $employees, PayrollRun $run): array
    {
        $employees = $employees->values();
        $matches = $this->matchAll($employees);
        $entries = $run->entries()->whereNull('cancelled_at')->with('lines')->get()->keyBy('employee_id');
        $seen = [];

        $rows = $employees->map(function (array $row, int $index) use ($matches, $entries, &$seen) {
            [$user, $note] = $matches[$index];
            $entry = $user ? $entries->get($user->employee->id) : null;
            $name = trim("{$row['surname']}, {$row['first_name']}");
            $result = [
                'name' => $name, 'account' => $user?->name, 'status' => 'unmatched', 'note' => $note,
                'workbook_basic' => $row['basic'], 'workbook_net' => $row['net_recomputed'], 'imis_basic' => null, 'imis_net' => null, 'variance' => null, 'effects' => [],
            ];
            if ($entry === null) {
                $result['note'] ??= $user === null ? null : 'Not on this payroll.';

                return $result;
            }
            $seen[$entry->employee_id] = true;

            $lines = $entry->lines->groupBy('label')->map(fn ($group) => round($group->sum(fn ($line) => (float) $line->amount), 2));
            $line = fn (string $label) => (float) ($lines[$label] ?? 0.0);
            $imis = ['basic' => (float) $entry->basic_salary, 'pera' => $line('PERA'), 'gsis' => $line('GSIS Personal Share'), 'phic' => $line('PhilHealth'), 'hdmf' => $line('Pag-IBIG')];
            $imisOther = round((float) $entry->total_deductions - $imis['gsis'] - $imis['phic'] - $imis['hdmf'], 2);
            $workbookOther = round(array_sum($row['other_deductions']), 2);

            // Each item's effect on IMIS's net compared with the workbook's.
            $effects = [
                'Basic salary' => $imis['basic'] - $row['basic'],
                'PERA and other pay' => ((float) $entry->gross_pay - $imis['basic']) - ($row['gross'] - $row['basic']),
                'GSIS personal share' => -($imis['gsis'] - $row['gsis_ps']),
                'PhilHealth' => -($imis['phic'] - $row['phic_ps']),
                'Pag-IBIG' => -($imis['hdmf'] - $row['hdmf_ps']),
                'Tax, loans and other deductions' => -($imisOther - $workbookOther),
            ];
            $variance = round((float) $entry->net_pay - $row['net_recomputed'], 2);

            return [
                ...$result,
                'status' => abs($variance) < 0.01 ? 'agrees' : 'differs',
                'imis_basic' => $imis['basic'], 'imis_net' => (float) $entry->net_pay, 'variance' => $variance,
                'effects' => collect($effects)->map(fn (float $effect) => round($effect, 2))->filter(fn (float $effect) => abs($effect) >= 0.01)
                    ->map(fn (float $effect, string $label) => ['label' => $label, 'amount' => $effect])->values()->all(),
            ];
        })->all();

        $onlyInImis = $entries->reject(fn (PayrollEntry $entry) => isset($seen[$entry->employee_id]))->pluck('name')->sort()->values()->all();

        return [
            'rows' => $rows,
            'only_in_imis' => $onlyInImis,
            'summary' => [
                'workbook' => count($rows),
                'agree' => count(array_filter($rows, fn (array $row) => $row['status'] === 'agrees')),
                'differ' => count(array_filter($rows, fn (array $row) => $row['status'] === 'differs')),
                'unmatched' => count(array_filter($rows, fn (array $row) => $row['status'] === 'unmatched')),
                'only_in_imis' => count($onlyInImis),
            ],
        ];
    }

    /**
     * Saves the workbook's deductions and loans onto the matched employees'
     * pay profiles, effective from the month. Anything IMIS already has
     * for the same component (an item in effect, an open loan) is left as
     * it is and reported. The workbook has no loan balances or terms, so
     * each loan gets a placeholder total payable for HR to correct.
     *
     * @param  Collection<int, array<string, mixed>>  $employees
     * @return array{people: int, items: int, loans: int, components: int, skipped: array<int, string>}
     */
    public function apply(Collection $employees, string $month, bool $save = true): array
    {
        $employees = $employees->values();
        $matches = $this->matchAll($employees);
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();

        DB::beginTransaction();
        try {
            $result = $this->load($employees, $matches, $start, false);
            $save ? DB::commit() : DB::rollBack();

            return $result;
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * Loads the workbook's deductions and loans onto the matched employees
     * (their existing pay items and loans stand aside), then generates the
     * month's payroll afresh. Always rolled back.
     *
     * @param  Collection<int, array<string, mixed>>  $employees
     * @param  Collection<int, array{0: ?User, 1: ?string}>  $matches
     * @return Collection<int, PayrollEntry>
     */
    private function simulated(Collection $employees, Collection $matches, string $month, User $actor): Collection
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();

        DB::beginTransaction();
        try {
            PayrollRun::query()->where('year', $start->year)->where('month', $start->month)->get()->each->delete();
            $ids = $matches->map(fn (array $match) => $match[0]?->employee->id)->filter()->all();
            EmployeePayItem::query()->whereIn('employee_id', $ids)->delete();
            // Earlier payrolls refer to the existing loans, so they are stopped rather than removed.
            EmployeeLoan::query()->whereIn('employee_id', $ids)->update(['stopped_on' => '2000-01-01']);

            $this->load($employees, $matches, $start, true);
            $run = $this->payrolls->create($month, null, $actor);

            return $run->entries()->with('lines')->get()->keyBy('employee_id');
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $employees
     * @param  Collection<int, array{0: ?User, 1: ?string}>  $matches
     * @return array{people: int, items: int, loans: int, components: int, skipped: array<int, string>}
     */
    private function load(Collection $employees, Collection $matches, Carbon $start, bool $replacing): array
    {
        $result = ['people' => 0, 'items' => 0, 'loans' => 0, 'components' => 0, 'skipped' => []];
        $hdmf = PayComponent::query()->where('code', 'HDMF')->first();

        foreach ($employees as $index => $row) {
            $user = $matches[$index][0];
            if ($user === null) {
                continue;
            }
            $employee = $user->employee;
            $result['people']++;

            // One line per component: the workbook's second line (a second loan, a tax top-up) adds to the first.
            $wanted = [];
            foreach ($row['other_deductions'] as $label => $amount) {
                [$code, $name, $kind, $monthOnly] = $this->spec($label);
                $wanted[$code] ??= ['name' => $name, 'kind' => $kind, 'monthOnly' => $monthOnly, 'amount' => 0.0];
                $wanted[$code]['amount'] = round($wanted[$code]['amount'] + $amount, 2);
            }

            // Pag-IBIG above the ₱200 rule is the employee's own election.
            if ($hdmf && abs($row['hdmf_ps'] - 200) >= 0.01) {
                $this->saveItem($employee, $hdmf, $row['hdmf_ps'], $start, false, $replacing, $user->name, $result);
            }

            foreach ($wanted as $code => $line) {
                $component = PayComponent::query()->where('code', $code)->first();
                if ($component === null) {
                    $component = PayComponent::query()->create([
                        'code' => $code, 'name' => $line['name'], 'kind' => $line['kind']->value, 'category' => PayCategory::defaultFor($line['kind'])->value,
                        'method' => PayComputation::Manual->value, 'sort_order' => 900, 'is_active' => true,
                    ]);
                    $result['components']++;
                }

                if ($component->kind === PayComponentKind::Loan) {
                    $exists = EmployeeLoan::query()->where('employee_id', $employee->id)->where('pay_component_id', $component->id)
                        ->whereNull('stopped_on')->whereNull('cancelled_on')->exists();
                    if ($exists && ! $replacing) {
                        $result['skipped'][] = "{$user->name}: already has an open {$component->name}.";

                        continue;
                    }
                    EmployeeLoan::query()->create([
                        'employee_id' => $employee->id, 'pay_component_id' => $component->id, 'reference' => 'Payroll workbook',
                        'principal' => $line['amount'] * 120, 'total_payable' => $line['amount'] * 120, 'monthly_amortization' => $line['amount'],
                        'start_month' => $start, 'paid_before' => 0,
                        'remarks' => 'From the payroll workbook, which has no balance or term: the total payable is a placeholder. HR to enter the real balance.',
                    ]);
                    $result['loans']++;
                } else {
                    $this->saveItem($employee, $component, $line['amount'], $start, $line['monthOnly'], $replacing, $user->name, $result);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array{people: int, items: int, loans: int, components: int, skipped: array<int, string>}  $result
     */
    private function saveItem(Employee $employee, PayComponent $component, float $amount, Carbon $start, bool $monthOnly, bool $replacing, string $name, array &$result): void
    {
        $exists = EmployeePayItem::query()->where('employee_id', $employee->id)->where('pay_component_id', $component->id)->inEffectDuring($start)->exists();
        if ($exists && ! $replacing) {
            $result['skipped'][] = "{$name}: already has {$component->name} on the profile.";

            return;
        }

        EmployeePayItem::query()->create([
            'employee_id' => $employee->id, 'pay_component_id' => $component->id, 'amount' => $amount, 'effective_from' => $start,
            // Withholding tax changes every month, so it must not carry forward.
            'effective_to' => $monthOnly ? $start->copy()->endOfMonth() : null,
            'remarks' => 'From the payroll workbook.',
        ]);
        $result['items']++;
    }

    /**
     * The IMIS component a workbook column stands for: code, name, kind, and
     * whether it applies to the month only. A column IMIS has no name for
     * gets a "WB-" code and its own label, for HR to rename.
     *
     * @return array{0: string, 1: string, 2: PayComponentKind, 3: bool}
     */
    private function spec(string $label): array
    {
        $label = trim(str_replace('(second line)', '', $label));
        $loan = PayComponentKind::Loan;
        $deduction = PayComponentKind::Deduction;
        $known = [
            'Withholding Tax' => ['WTAX', 'Withholding Tax', $deduction, true],
            'Pag-IBIG MP2' => ['HDMF-MP2', 'Pag-IBIG MP2 Savings', $deduction, false],
            'Welfare Fund Contribution' => ['WF', 'Welfare Fund Contribution', $deduction, false],
            'PINTIG' => ['PINTIG', 'PINTIG', $deduction, false],
            'GSIS OPT Life' => ['GSIS-OPT-LIFE', 'GSIS OPT Life', $deduction, false],
            'GSIS Consolidated Loan' => ['GSIS-CONSO', 'GSIS Consolidated Loan', $loan, false],
            'GSIS MPL' => ['GSIS-MPL', 'GSIS Multi-Purpose Loan', $loan, false],
            'GSIS Emergency Loan' => ['GSIS-EML', 'GSIS Emergency Loan', $loan, false],
            'GSIS Regular Policy Loan' => ['GSIS-POL', 'GSIS Policy Loan', $loan, false],
            'Pag-IBIG MP Loan' => ['HDMF-MPL', 'Pag-IBIG Multi-Purpose Loan', $loan, false],
            'Pag-IBIG Housing Loan' => ['HDMF-HOUSING', 'Pag-IBIG Housing Loan', $loan, false],
            'GSIS Solar Loan' => ['GSIS-SOLAR', 'GSIS Solar Loan', $loan, false],
            'GSIS MPL Lite' => ['GSIS-MPL-LITE', 'GSIS MPL Lite', $loan, false],
            'GSIS HELP' => ['GSIS-HELP', 'GSIS HELP', $loan, false],
            'GSIS CPL' => ['GSIS-CPL', 'GSIS CPL', $loan, false],
            'GSIS Real Estate Loan' => ['GSIS-REL', 'GSIS Real Estate Loan', $loan, false],
            'GSIS EAL/Flexi' => ['GSIS-EAL', 'GSIS EAL/Flexi', $loan, false],
            'GPL' => ['GSIS-GPL', 'GPL', $loan, false],
            'CEAP' => ['CEAP', 'CEAP', $loan, false],
            'eCA' => ['ECA', 'eCA', $loan, false],
            'CSC PAL' => ['CSC-PAL', 'CSC PAL', $loan, false],
            'SAFE Loan' => ['SAFE', 'SAFE Loan', $loan, false],
            'Welfare Fund MPL' => ['WF-MPL', 'Welfare Fund MPL', $loan, false],
            'GSIS (column M, second line)' => ['WB-GSIS-M2', 'GSIS loan in column M, second line (HR to name it)', $loan, false],
        ];

        return $known[$label] ?? [
            'WB-'.strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label), '-')),
            $label,
            preg_match('/loan|MPL|GPL|CPL|PAL|HELP|EAL|CEAP|eCA/i', $label) ? $loan : $deduction,
            false,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $employees
     * @return Collection<int, array{0: ?User, 1: ?string}>
     */
    private function matchAll(Collection $employees): Collection
    {
        $users = User::query()->with('employee')->whereHas('employee')->get();

        return $employees->values()->map(fn (array $row) => $this->person($row['surname'], $row['first_name'], $users));
    }

    /**
     * IMIS's payroll for the month: the saved run if there is one, else one
     * generated inside a transaction that is rolled back.
     *
     * @return array{0: Collection<int, PayrollEntry>, 1: bool} entries by employee id; whether generated just now
     */
    private function entriesFor(string $month, User $actor): array
    {
        [$year, $number] = array_map('intval', explode('-', $month));
        $run = PayrollRun::query()->where('year', $year)->where('month', $number)->first();
        if ($run) {
            return [$run->entries()->with('lines')->get()->keyBy('employee_id'), false];
        }

        DB::beginTransaction();
        try {
            $run = $this->payrolls->create($month, null, $actor);

            return [$run->entries()->with('lines')->get()->keyBy('employee_id'), true];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{0: ?User, 1: ?string}
     */
    private function person(string $surname, string $first, Collection $users): array
    {
        $surnameKey = $this->squash($surname);
        $words = $this->words($first);

        $candidates = $users->filter(function (User $user) use ($surnameKey, $words) {
            $surnames = array_filter([$this->squash($user->last_name), ...array_map($this->squash(...), explode('-', (string) $user->last_name))]);

            return in_array($surnameKey, $surnames, true) && array_intersect($words, $this->words($user->first_name)) !== [];
        })->values();

        if ($candidates->count() > 1) {
            $inService = $candidates->filter(fn (User $user) => ! $user->employee->isSeparated())->values();

            return $inService->count() === 1
                ? [$inService->first(), 'Matched more than one account; took the one in service.']
                : [null, 'More than one account matches: '.$candidates->map(fn (User $user) => "#{$user->id}")->implode(', ').'.'];
        }

        return $candidates->isEmpty() ? [null, 'No IMIS employee with this name.'] : [$candidates->first(), null];
    }

    /** @return array<int, string> letters only, without initials */
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

    private function number(?string $value): ?float
    {
        $value = trim((string) $value);

        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }
}
