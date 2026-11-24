<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\HR\Payroll\PayrollWorkbook;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Sets the accounting office's monthly payroll workbook beside what IMIS
 * would pay, per employee. Read-only: IMIS's payroll for the month is
 * generated inside a transaction that is rolled back (or read, if the
 * month's payroll already exists), and nothing is imported.
 */
class CheckPayrollWorkbook extends Command
{
    protected $signature = 'imis:check-payroll-workbook
        {file : The "Final Payroll" workbook (.xlsx)}
        {--month= : The payroll month (YYYY-MM)}
        {--simulate : Load the deductions and loans from the workbook onto the profiles and generate the payroll afresh, then roll it all back}
        {--csv= : Also write the per-employee comparison to this CSV file}';

    protected $description = 'Compare the payroll workbook with what IMIS would pay (read-only)';

    public function handle(PayrollWorkbook $workbook): int
    {
        $file = (string) $this->argument('file');
        $month = (string) $this->option('month');
        if (! is_file($file)) {
            $this->error("No file at {$file}.");

            return self::FAILURE;
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('--month must look like 2026-11.');

            return self::FAILURE;
        }
        $actor = User::query()->orderBy('id')->first();
        if (! $actor) {
            $this->error('There are no users.');

            return self::FAILURE;
        }

        try {
            $rows = $workbook->compare($workbook->employees($workbook->read($file)), $month, $actor, (bool) $this->option('simulate'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $matched = $rows->filter(fn (array $row) => $row['imis'] !== null);
        $this->info(sprintf('%d employees in the workbook; %d matched to an IMIS payroll entry for %s.', $rows->count(), $matched->count(), $month));

        $generated = $matched->first()['imis']['generated'] ?? null;
        if ($generated !== null) {
            $this->line($generated ? 'IMIS side: a payroll generated just now and rolled back.' : "IMIS side: the payroll already saved for {$month} (check it is not a test run).");
        }

        $unmatched = $rows->filter(fn (array $row) => $row['imis'] === null);
        foreach ($unmatched as $row) {
            $this->line(sprintf('  <fg=yellow>! %s, %s (row %d):</> %s', $row['surname'], $row['first_name'], $row['row'], $row['note'] ?? 'unmatched'));
        }

        $this->newLine();
        $this->line('<options=bold>Basic salary, PERA and mandatory shares (IMIS minus workbook)</>');
        $this->table(['Employee', 'Basic', 'PERA', 'GSIS', 'PhilHealth', 'Pag-IBIG', 'Net variance'], $matched->map(fn (array $row) => [
            mb_substr("{$row['surname']}, {$row['first_name']}", 0, 34),
            $this->delta($row['imis']['basic'], $row['basic']),
            $this->delta($row['imis']['pera'], $row['pera']),
            $this->delta($row['imis']['gsis_ps'], $row['gsis_ps']),
            $this->delta($row['imis']['phic_ps'], $row['phic_ps']),
            $this->delta($row['imis']['hdmf_ps'], $row['hdmf_ps']),
            $this->delta($row['imis']['net_with_workbook_extras'], $row['net_recomputed']),
        ])->all());

        $this->newLine();
        foreach (['basic' => 'Basic salary', 'pera' => 'PERA', 'gsis_ps' => 'GSIS personal share', 'phic_ps' => 'PhilHealth', 'hdmf_ps' => 'Pag-IBIG'] as $key => $label) {
            $same = $matched->filter(fn (array $row) => abs($row['imis'][$key] - $row[$key]) <= 0.02)->count();
            $below = $matched->filter(fn (array $row) => $row['imis'][$key] - $row[$key] < -0.02)->count();
            $this->line(sprintf('  %-20s agrees for %d of %d (IMIS lower for %d, higher for %d)', $label, $same, $matched->count(), $below, $matched->count() - $same - $below));
        }
        $flat = $matched->filter(fn (array $row) => abs($row['hdmf_ps'] - 200) < 0.01)->count();
        $this->line(sprintf('  The workbook deducts exactly P200 Pag-IBIG for %d of %d; the rest is a higher contribution the employee chose.', $flat, $matched->count()));

        $clean = $matched->filter(fn (array $row) => abs($row['net_variance']) < 0.01)->count();
        $this->line(sprintf('Net pay agrees (to the centavo) for %d of %d matched employees, taking the workbook\'s tax, loans and welfare fund as given.', $clean, $matched->count()));

        $strayed = $rows->filter(fn (array $row) => $row['net_in_workbook'] !== null && abs($row['net_in_workbook'] - $row['net_recomputed']) >= 0.01);
        $this->newLine();
        $this->line(sprintf('<options=bold>Workbook rows whose own net differs from the sum of their columns: %d</>', $strayed->count()));
        foreach ($strayed as $row) {
            $this->line(sprintf('  row %d %s: workbook net %s, columns add up to %s', $row['row'], $row['surname'], number_format($row['net_in_workbook'], 2), number_format($row['net_recomputed'], 2)));
        }

        $totals = [];
        foreach ($rows as $row) {
            foreach ($row['other_deductions'] as $label => $amount) {
                $totals[$label] = [($totals[$label][0] ?? 0) + 1, ($totals[$label][1] ?? 0) + $amount];
            }
        }
        arsort($totals);
        $this->newLine();
        $this->line('<options=bold>Deductions IMIS has no automatic rule for (typed in the workbook)</>');
        $this->table(['Column', 'Employees', 'Total'], collect($totals)->map(fn (array $total, string $label) => [$label, $total[0], number_format($total[1], 2)])->values()->all());

        if ($csv = $this->option('csv')) {
            $handle = fopen((string) $csv, 'w');
            fputcsv($handle, ['Surname', 'First name', 'IMIS account', 'Workbook basic', 'IMIS basic', 'Workbook GSIS', 'IMIS GSIS', 'Workbook PhilHealth', 'IMIS PhilHealth', 'Workbook Pag-IBIG', 'IMIS Pag-IBIG', 'Other deductions', 'Workbook net', 'Workbook net (recomputed)', 'IMIS net', 'Net variance', 'Note']);
            foreach ($rows as $row) {
                $imis = $row['imis'];
                fputcsv($handle, [$row['surname'], $row['first_name'], $row['user'], $row['basic'], $imis['basic'] ?? '', $row['gsis_ps'], $imis['gsis_ps'] ?? '', $row['phic_ps'], $imis['phic_ps'] ?? '', $row['hdmf_ps'], $imis['hdmf_ps'] ?? '',
                    array_sum($row['other_deductions']), $row['net_in_workbook'], $row['net_recomputed'], $imis['net_with_workbook_extras'] ?? '', $row['net_variance'] ?? '', $row['note']]);
            }
            fclose($handle);
            $this->info("Comparison written to {$csv}.");
        }

        $this->info('Nothing was saved.');

        return self::SUCCESS;
    }

    private function delta(float $imis, float $workbook): string
    {
        $difference = round($imis - $workbook, 2);

        return abs($difference) < 0.01 ? '·' : number_format($difference, 2);
    }
}
