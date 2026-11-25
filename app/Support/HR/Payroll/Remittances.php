<?php

namespace App\Support\HR\Payroll;

use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Support\Collection;

/**
 * HR-8: what a month's payroll owes the agencies it deducts for: GSIS,
 * PhilHealth, Pag-IBIG, the Welfare Fund, and the BIR. One list per
 * contribution or loan, ready to print or export as CSV for the payment
 * and the agency's own forms. These are schedules, not the agencies'
 * upload files (each has its own format).
 *
 * The employer's shares are not payroll lines, so they are worked out here
 * from the same rules the deductions follow: GSIS 12% of the basic salary
 * plus the ₱100 ECC; PhilHealth the other half of the 5% premium (the
 * employee takes the lower half when it splits at a half-centavo);
 * Pag-IBIG the same 2% on at most ₱10,000 as the employee.
 */
class Remittances
{
    /** GSIS employer share of the basic salary, in percent (RA 8291). */
    public const GSIS_EMPLOYER_RATE = 12;

    /** Employees' Compensation Commission, a flat monthly amount per member. */
    public const GSIS_ECC = 100;

    /**
     * @return array<string, array{title: string, note: ?string, columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>}>
     */
    public function forRun(PayrollRun $run): array
    {
        $entries = $run->entries()->whereNull('cancelled_at')->with('lines.component', 'lines.loan')->orderBy('name')->get();
        $components = PayComponent::query()->whereIn('code', ['PHIC', 'HDMF'])->get()->keyBy('code');

        return [
            'gsis' => $this->contributions('GSIS contributions', null, $entries, 'GSIS-PS', fn (PayrollEntry $entry, float $employee) => [
                'employer' => round((float) $entry->basic_salary * self::GSIS_EMPLOYER_RATE / 100, 2),
                'ecc' => (float) self::GSIS_ECC,
            ]),
            'gsis_loans' => $this->deductions('GSIS loans and insurance', $entries, fn (string $code, PayrollLine $line) => str_starts_with($code, 'GSIS-') && $code !== 'GSIS-PS'),
            'philhealth' => $this->contributions('PhilHealth premiums', null, $entries, 'PHIC', fn (PayrollEntry $entry, float $employee) => [
                'employer' => $this->philhealthEmployer($entry, $employee, $components->get('PHIC')),
            ]),
            'pagibig' => $this->contributions('Pag-IBIG contributions', 'Includes any contribution above the ₱200 minimum that the employee elected.', $entries, 'HDMF', fn (PayrollEntry $entry, float $employee) => [
                'employer' => $this->pagibigEmployer($entry, $components->get('HDMF')),
            ]),
            'pagibig_loans' => $this->deductions('Pag-IBIG loans and savings', $entries, fn (string $code, PayrollLine $line) => str_starts_with($code, 'HDMF-')),
            'welfare' => $this->deductions('Welfare Fund', $entries, fn (string $code, PayrollLine $line) => $code === 'WF' || str_starts_with($code, 'WF-')),
            'tax' => $this->deductions('BIR withholding tax', $entries, fn (string $code, PayrollLine $line) => $code === 'WTAX'),
        ];
    }

    /**
     * The employer's shares for one person, given the employee's own
     * (nothing is owed where the employee pays nothing): GSIS with its ECC,
     * PhilHealth and Pag-IBIG. They sit beside the net pay on the General
     * Payroll but are not payroll lines.
     *
     * @return array{gsis: float, ecc: float, phic: float, hdmf: float}
     */
    public function employerShares(PayrollEntry $entry, float $gsis, float $phic, float $hdmf): array
    {
        $components = PayComponent::query()->whereIn('code', ['PHIC', 'HDMF'])->get()->keyBy('code');

        return [
            'gsis' => $gsis > 0 ? round((float) $entry->basic_salary * self::GSIS_EMPLOYER_RATE / 100, 2) : 0.0,
            'ecc' => $gsis > 0 ? (float) self::GSIS_ECC : 0.0,
            'phic' => $phic > 0 ? $this->philhealthEmployer($entry, $phic, $components->get('PHIC')) : 0.0,
            'hdmf' => $hdmf > 0 ? $this->pagibigEmployer($entry, $components->get('HDMF')) : 0.0,
        ];
    }

    /**
     * One row per person with the employee's share and the employer's.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     * @param  callable(PayrollEntry, float): array<string, float>  $employerShares
     * @return array{title: string, note: ?string, columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>}
     */
    private function contributions(string $title, ?string $note, Collection $entries, string $code, callable $employerShares): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $employee = round($entry->lines->filter(fn (PayrollLine $line) => $line->component?->code === $code)->sum(fn (PayrollLine $line) => (float) $line->amount), 2);
            if ($employee <= 0) {
                continue;
            }
            $shares = $employerShares($entry, $employee);
            $row = ['name' => $entry->name, 'basic' => (float) $entry->basic_salary, 'employee' => $employee, 'employer' => $shares['employer']];
            if (isset($shares['ecc'])) {
                $row['ecc'] = $shares['ecc'];
            }
            $row['total'] = round($employee + $shares['employer'] + ($shares['ecc'] ?? 0), 2);
            $rows[] = $row;
        }

        $columns = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'basic', 'label' => 'Basic salary', 'money' => true],
            ['key' => 'employee', 'label' => 'Employee share', 'money' => true],
            ['key' => 'employer', 'label' => 'Employer share', 'money' => true],
        ];
        if (collect($rows)->contains(fn (array $row) => isset($row['ecc']))) {
            $columns[] = ['key' => 'ecc', 'label' => 'ECC', 'money' => true];
        }
        $columns[] = ['key' => 'total', 'label' => 'Total to remit', 'money' => true];

        return ['title' => $title, 'note' => $note, 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * One row per deduction line: who, which loan or item, the amount.
     *
     * @param  Collection<int, PayrollEntry>  $entries
     * @param  callable(string, PayrollLine): bool  $belongs  whether a line (by its component code) is for this agency
     * @return array{title: string, note: ?string, columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>}
     */
    private function deductions(string $title, Collection $entries, callable $belongs): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines->where('kind', 'deduction') as $line) {
                $code = (string) $line->component?->code;
                if ($code !== '' && (float) $line->amount > 0 && $belongs($code, $line)) {
                    $rows[] = ['name' => $entry->name, 'item' => $line->component->name, 'reference' => $line->loan?->reference, 'amount' => (float) $line->amount];
                }
            }
        }
        usort($rows, fn (array $a, array $b) => [$a['item'], $a['name']] <=> [$b['item'], $b['name']]);

        return ['title' => $title, 'note' => null, 'columns' => [
            ['key' => 'item', 'label' => 'Loan or item'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'amount', 'label' => 'Amount', 'money' => true],
        ], 'rows' => $rows];
    }

    /** The premium is 2 × the employee's rate on the salary within the floor and ceiling; the employer pays what the employee does not. */
    private function philhealthEmployer(PayrollEntry $entry, float $employee, ?PayComponent $component): float
    {
        if ($component === null || $component->rate === null) {
            return $employee;
        }
        $base = min(max((float) $entry->basic_salary, (float) ($component->base_min ?? 0)), $component->base_max !== null ? (float) $component->base_max : PHP_FLOAT_MAX);
        $premium = round($base * (float) $component->rate * 2 / 100, 2);

        return round($premium - $employee, 2);
    }

    /** Pag-IBIG's employer share is the same 2% on at most ₱10,000 whatever the employee elects. */
    private function pagibigEmployer(PayrollEntry $entry, ?PayComponent $component): float
    {
        if ($component === null || $component->rate === null) {
            return 0.0;
        }
        $base = min((float) $entry->basic_salary, $component->base_max !== null ? (float) $component->base_max : PHP_FLOAT_MAX);

        return round($base * (float) $component->rate / 100, 2);
    }
}
