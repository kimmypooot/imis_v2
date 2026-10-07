<?php

namespace App\Console\Commands;

use App\Notifications\ExecutiveDigest;
use App\Support\Executive\ExecutiveAudience;
use App\Support\Executive\ExecutiveDashboard;
use App\Support\Settings\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Exec-3: the weekly summary for Executive Dashboard viewers (email and
 * the bell): systems needing attention and figures off target. Each
 * recipient's copy is built for them, so links follow their permissions.
 * Scheduled Mondays 7:00 AM; skipped unless System Settings → Executive
 * Dashboard → Weekly summary is on (or with --force).
 */
class SendExecutiveDigest extends Command
{
    protected $signature = 'imis:executive-digest {--force : Send even when the weekly summary is turned off}';

    protected $description = 'Send the weekly Executive Dashboard summary to top management';

    public function handle(): int
    {
        if (! $this->option('force') && ! Settings::get('executive_weekly_digest')) {
            $this->info('The weekly summary is turned off (System Settings → Executive Dashboard).');

            return self::SUCCESS;
        }

        $audience = ExecutiveAudience::members();

        foreach ($audience as $user) {
            $built = app(ExecutiveDashboard::class, ['viewer' => $user])->build();
            Notification::send($user, new ExecutiveDigest($built));
        }

        $this->info("Sent to {$audience->count()} ".str('person')->plural($audience->count()).'.');

        return self::SUCCESS;
    }
}
