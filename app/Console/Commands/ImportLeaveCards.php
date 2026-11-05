<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Leave\LeaveCardWorkbook;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Posts opening VL and SL balances from HR's leave-card workbook: see
 * LeaveCardWorkbook. Previews every sheet unless run with --apply.
 */
class ImportLeaveCards extends Command
{
    protected $signature = 'imis:import-leave-cards
        {file : The leave card workbook (.xlsx)}
        {--as-of= : Ignore months after this one (YYYY-MM), e.g. 2026-10}
        {--apply : Save the opening balances (without it, only shows them)}';

    protected $description = 'Post opening VL and SL balances from the leave card workbook';

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
            $checked = $workbook->check($workbook->read($file), $asOf);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($checked as $result) {
            $this->newLine();
            $this->line(sprintf('<options=bold>%s · %s</>', $result['sheet'], $result['holder'] ?? $result['name']));
            if ($result['date'] !== null) {
                $this->line(sprintf('  Card balance at %s: VL %s, SL %s', $result['date'], $result['vl'] ?? '—', $result['sl'] ?? '—'));
            }
            foreach ($result['entries'] as $entry) {
                $this->line("  <fg=green>Opening {$entry['type']->code}:</> {$entry['amount']}");
            }
            foreach ($result['warnings'] as $warning) {
                $this->line("  <fg=yellow>! {$warning}</>");
            }
            foreach ($result['errors'] as $error) {
                $this->line("  <fg=red>✗ {$error}</>");
            }
        }

        $ready = $checked->where('ready', true);
        $this->newLine();
        $this->line(sprintf('%d sheets: %d ready to post, %d with errors, %d with nothing to post.',
            $checked->count(), $ready->count(), $checked->filter(fn ($row) => $row['errors'] !== [])->count(),
            $checked->filter(fn ($row) => $row['errors'] === [] && ! $row['ready'])->count()));

        if (! $this->option('apply')) {
            $this->info('Nothing was saved; run with --apply to post the opening balances.');

            return self::SUCCESS;
        }
        if ($ready->isEmpty()) {
            $this->info('Nothing to save.');

            return self::SUCCESS;
        }

        $posted = $workbook->apply($checked, $file);
        $audit->recordFor(null, AuditEvent::LeaveCardsImported, "Posted opening leave balances from the leave card workbook ({$ready->count()} people)", null, [
            'file' => basename($file),
            'people' => $ready->count(),
            'entries' => $posted,
            'as_of' => $asOf,
        ]);

        $this->info("Saved: {$posted} opening entries for {$ready->count()} people.");

        return self::SUCCESS;
    }
}
