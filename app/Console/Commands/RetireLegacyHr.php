<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\Status;
use App\Models\Application;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * HR-7 cutover: once the built-in HR module has replaced them, takes the
 * legacy PRISM, LMS and ldms launchers off the portal (inactive, so their
 * access grants stay on record and the step can be undone from System
 * management). Shows what it would do unless `--apply` is given.
 *
 * Only the portal entries change here; the legacy apps themselves keep
 * running until they're switched off on the legacy server.
 */
class RetireLegacyHr extends Command
{
    /** Launcher short names the HR module replaces. */
    public const SYSTEMS = ['PRISM', 'LMS', 'LDMS'];

    protected $signature = 'imis:retire-legacy-hr
        {--apply : Deactivate the launchers (otherwise only shows what would change)}';

    protected $description = 'Deactivate the PRISM, LMS and ldms portal launchers replaced by the HR module';

    public function handle(AuditLogger $audit): int
    {
        $applications = Application::query()->whereIn('short_name', self::SYSTEMS)->orderBy('name')->get();

        $missing = array_diff(self::SYSTEMS, $applications->pluck('short_name')->all());
        if ($missing !== []) {
            $this->warn('No launcher found for: '.implode(', ', $missing));
        }

        $this->table(['System', 'Name', 'Status now'], $applications->map(fn (Application $application) => [
            $application->short_name, $application->name, $application->status->value,
        ])->all());

        $active = $applications->where('status', Status::Active);
        if ($active->isEmpty()) {
            $this->info('Nothing to do: these launchers are already inactive.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->info("Would deactivate {$active->count()} launcher(s). Run again with --apply to do it.");

            return self::SUCCESS;
        }

        foreach ($active as $application) {
            $application->update(['status' => Status::Inactive]);
            $audit->recordChanges(AuditEvent::ApplicationStatusChanged, "Deactivated system {$application->name} (replaced by the HR module)", $application);
        }

        $this->info("Deactivated {$active->count()} launcher(s). Reactivate one from System management if needed.");

        return self::SUCCESS;
    }
}
