<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Legacy\LegacyLeaveImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HR-7: carries the legacy LMS (`lms_*` tables) into HR-6 — leave
 * settings, holidays, officers-in-charge, the credit ledger, decided
 * applications, flexible work and monetization. See
 * LegacyLeaveImporter for the rules. Run `imis:import-legacy` and HR's
 * "Set up records for staff" first; then `--dry-run` and read the report
 * before the real run. Re-running is safe.
 */
class ImportLegacyLeave extends Command
{
    protected $signature = 'imis:import-legacy-leave
        {--connection= : Database connection holding the lms_* tables (default: the legacy connection)}
        {--dry-run : Show what would be imported without saving anything}';

    protected $description = 'Import leave credits, applications, flexible work and monetization from the legacy LMS';

    public function handle(AuditLogger $audit): int
    {
        if (! User::query()->whereNotNull('legacy_id')->exists()) {
            $this->error('No account has a legacy id yet. Run `php artisan imis:import-legacy` first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $legacy = DB::connection($this->option('connection') ?: config('legacy.connection'));
            $result = (new LegacyLeaveImporter($legacy))->run($dryRun);
        } catch (Throwable $e) {
            $this->error('Import failed, nothing was saved: '.$e->getMessage());

            return self::FAILURE;
        }

        ksort($result['counts']);
        $this->table(['What', 'Count'], collect($result['counts'])->map(fn ($count, $what) => [$what, $count])->values()->all());

        foreach ($result['notes'] as $heading => $items) {
            $this->newLine();
            $this->warn(ucfirst($heading).' ('.count($items).'):');
            foreach (array_slice($items, 0, 50) as $item) {
                $this->line("  - {$item}");
            }
            if (count($items) > 50) {
                $this->line('  … and '.(count($items) - 50).' more');
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run: nothing was saved.');

            return self::SUCCESS;
        }

        $audit->recordFor(null, AuditEvent::LeaveImportedFromLegacy, 'Imported leave records from the legacy LMS', null, $result['counts']);
        $this->info('Done. Check the leave cards of a few people against the legacy LMS before retiring it.');

        return self::SUCCESS;
    }
}
