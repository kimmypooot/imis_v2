<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Leave\LeaveCardWorkbook;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Saves every month of each person's leave card (VL and SL earned, used,
 * balance, particulars) from HR's workbook, so employees can see the
 * month-by-month breakdown for years the credit ledger doesn't cover.
 * Display only: it never touches balances. Previews unless --apply, and
 * re-running updates the months in place.
 */
class ImportLeaveCardHistory extends Command
{
    protected $signature = 'imis:import-leave-card-history
        {file : The leave card workbook (.xlsx)}
        {--as-of= : Ignore months after this one (YYYY-MM)}
        {--apply : Save the months (without it, only shows what was found)}';

    protected $description = 'Save the monthly leave card history (VL and SL) from the leave card workbook';

    public function handle(LeaveCardWorkbook $workbook, AuditLogger $audit): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("No file at {$file}.");

            return self::FAILURE;
        }
        $asOf = $this->option('as-of') ?: null;
        if ($asOf !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $asOf)) {
            $this->error('--as-of must look like 2026-10.');

            return self::FAILURE;
        }

        try {
            $checked = $workbook->history($workbook->read($file), $asOf);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($checked as $result) {
            $this->newLine();
            $this->line(sprintf('<options=bold>%s · %s</>', $result['sheet'], $result['holder'] ?? $result['name']));
            if ($result['months'] !== []) {
                $first = $result['months'][0];
                $last = end($result['months']);
                $this->line(sprintf('  %d months, %d-%02d to %d-%02d', count($result['months']), $first['year'], $first['month'], $last['year'], $last['month']));
            }
            foreach ($result['warnings'] as $warning) {
                $this->line("  <fg=yellow>! {$warning}</>");
            }
            foreach ($result['errors'] as $error) {
                $this->line("  <fg=red>✗ {$error}</>");
            }
        }

        $ready = $checked->where('ready', true);
        $months = $ready->sum(fn ($result) => count($result['months']));
        $this->newLine();
        $this->line(sprintf('%d sheets: %d ready (%d months), %d with errors.', $checked->count(), $ready->count(), $months, $checked->filter(fn ($row) => $row['errors'] !== [])->count()));

        if (! $this->option('apply')) {
            $this->info('Nothing was saved; run with --apply to save the months.');

            return self::SUCCESS;
        }
        if ($ready->isEmpty()) {
            $this->info('Nothing to save.');

            return self::SUCCESS;
        }

        $saved = $workbook->applyHistory($checked);
        $audit->recordFor(null, AuditEvent::LeaveCardsImported, "Saved leave card history from the workbook ({$ready->count()} people)", null, [
            'file' => basename($file),
            'people' => $ready->count(),
            'months' => $saved,
            'history' => true,
        ]);

        $this->info("Saved: {$saved} months for {$ready->count()} people.");

        return self::SUCCESS;
    }
}
