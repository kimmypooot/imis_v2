<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Plantilla\PlantillaOfPersonnel;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Updates IMIS from the HR Division's certified Plantilla of Personnel
 * workbook (.xlsx): see PlantillaOfPersonnel for what is and isn't taken.
 *
 * Shows every row's changes, warnings and errors unless run with --apply.
 * Re-running is safe: only what differs from IMIS is a change.
 */
class ImportPlantilla extends Command
{
    protected $signature = 'imis:import-plantilla
        {file : The Plantilla of Personnel workbook (.xlsx)}
        {--apply : Save the changes (without it, only shows what would change)}';

    protected $description = 'Update plantilla items, appointments and staff details from the certified Plantilla of Personnel workbook';

    public function handle(PlantillaOfPersonnel $sheet, AuditLogger $audit): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("No file at {$file}.");

            return self::FAILURE;
        }

        try {
            $checked = $sheet->check($sheet->read($file));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($checked as $row) {
            $this->newLine();
            $this->line(sprintf('<options=bold>Line %d · %s · %s · %s</>', $row['line'], $row['item'] ?: '—', $row['position'], $row['holder'] ?? '?'));
            foreach ($row['changes'] as $change) {
                $this->line("  <fg=green>{$change['field']}:</> {$change['from']} -> {$change['to']}");
            }
            foreach ($row['warnings'] as $warning) {
                $this->line("  <fg=yellow>! {$warning}</>");
            }
            foreach ($row['errors'] as $error) {
                $this->line("  <fg=red>✗ {$error}</>");
            }
            if ($row['changes'] === [] && $row['errors'] === []) {
                $this->line('  No changes.');
            }
        }

        $missing = $sheet->notOnSheet($checked);
        if ($missing->isNotEmpty()) {
            $this->newLine();
            $this->warn("In service in IMIS but not on the sheet ({$missing->count()}): ".$missing->map(fn ($user) => "{$user->name} ({$user->position})")->implode('; '));
        }

        $ready = $checked->where('ready', true)->count();
        $this->newLine();
        $this->line(sprintf('%d rows: %d with changes to save, %d with errors, %d unchanged.',
            $checked->count(), $ready, $checked->filter(fn ($row) => $row['errors'] !== [])->count(),
            $checked->filter(fn ($row) => $row['errors'] === [] && $row['changes'] === [])->count()));

        if (! $this->option('apply')) {
            $this->info('Nothing was saved; run with --apply to save the rows with changes.');

            return self::SUCCESS;
        }
        if ($ready === 0) {
            $this->info('Nothing to save.');

            return self::SUCCESS;
        }

        $touched = $sheet->apply($checked);

        $audit->recordFor(null, AuditEvent::PlantillaImported, "Updated IMIS from the Plantilla of Personnel workbook ({$ready} rows)", null, [
            'file' => basename($file),
            ...$touched,
        ]);

        $this->info(sprintf('Saved: %d items created, %d items updated, %d appointments corrected, %d employee records and %d accounts updated.',
            count($touched['items_created']), count($touched['items_updated']), count($touched['appointments_corrected']),
            count($touched['employees_updated']), count($touched['accounts_updated'])));

        return self::SUCCESS;
    }
}
