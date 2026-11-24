<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Backup\BackupStore;
use App\Support\HR\Payroll\PayrollWorkbook;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads the deductions and loans from the accounting office's monthly
 * payroll workbook onto employees' pay profiles (see PayrollWorkbook).
 * Previews unless run with --apply, which takes a database backup first.
 * Run imis:check-payroll-workbook beforehand to see what IMIS would pay.
 */
class ImportPayrollWorkbook extends Command
{
    protected $signature = 'imis:import-payroll-workbook
        {file : The "Final Payroll" workbook (.xlsx)}
        {--month= : The month the amounts are for (YYYY-MM)}
        {--apply : Save the pay items and loans (without it, only shows what would be saved)}
        {--force : With --apply, do not ask for confirmation}';

    protected $description = 'Load deductions and loans from the payroll workbook onto pay profiles';

    public function handle(PayrollWorkbook $workbook, BackupStore $backups, AuditLogger $audit): int
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

        try {
            $employees = $workbook->employees($workbook->read($file));
            $preview = $workbook->apply($employees, $month, false);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%d employees in the workbook; %d matched to an IMIS account.', $employees->count(), $preview['people']));
        $this->line(sprintf('Would save %d pay items and %d loans, and create %d new pay components.', $preview['items'], $preview['loans'], $preview['components']));
        foreach ($preview['skipped'] as $skipped) {
            $this->line("  <fg=yellow>! {$skipped}</>");
        }
        $this->line('Loans are saved with a placeholder total payable (the workbook has no balances): HR must enter each real balance.');

        if (! $this->option('apply')) {
            $this->info('Nothing was saved; run with --apply to save.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Save these to the pay profiles for {$month}?", false)) {
            $this->info('Nothing was saved.');

            return self::SUCCESS;
        }

        $backup = $backups->create(false);
        $this->line("Backup taken: {$backup['name']}");

        $saved = $workbook->apply($employees, $month);
        $audit->recordFor(null, AuditEvent::PayrollWorkbookImported, "Loaded pay items and loans from the payroll workbook for {$month} ({$saved['people']} people)", null, [
            'file' => basename($file),
            'month' => $month,
            'people' => $saved['people'],
            'items' => $saved['items'],
            'loans' => $saved['loans'],
            'components_created' => $saved['components'],
            'skipped' => count($saved['skipped']),
        ]);

        $this->info(sprintf('Saved: %d pay items and %d loans for %d people; %d new components.', $saved['items'], $saved['loans'], $saved['people'], $saved['components']));

        return self::SUCCESS;
    }
}
