<?php

use App\Support\Legacy\LegacySso;
use App\Support\System\ScheduledTasks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Closes the login history of sessions that idled out or were abandoned
// (legacy inc/session_cleanup.php). Needs `php artisan schedule:run` in cron.
Schedule::command('imis:expire-sessions')->everyFiveMinutes()->withoutOverlapping();

// HR-6: last month's leave accrual on the 1st (and the year start in January).
Schedule::command('imis:leave-accrual')->monthlyOn(1, '01:00')->withoutOverlapping();

// HR-1b: future-dated reassignments move the account's office on their date.
Schedule::command('imis:apply-reassignments')->dailyAt('00:10')->withoutOverlapping();

// Legacy bridge: catch up any account sync that failed, and drop used-up
// sign-in tokens. Both are no-ops while the bridge and SSO are off.
Schedule::command('imis:sync-legacy')->hourly()->withoutOverlapping();
Schedule::call(fn () => app(LegacySso::class)->prune())->daily()->name('prune-sso-tokens');

// System Settings → Automatic Backups: a backup kept on the server, then
// old ones deleted. Skipped while the setting is off. Office time (the
// app itself runs on UTC).
Schedule::command('imis:backup --if-enabled')->dailyAt('02:00')->timezone('Asia/Manila')->withoutOverlapping();

// System Settings → Log Retention: delete logs past their period.
Schedule::command('imis:prune-logs')->dailyAt('03:00')->timezone('Asia/Manila')->withoutOverlapping();

// Executive Dashboard: figures from IMIS's modules and connected systems'
// summary feeds, then the weekly summary for top management (skipped
// unless turned on in System Settings → Executive Dashboard).
Schedule::command('imis:collect-metrics')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('imis:executive-digest')->weeklyOn(1, '07:00')->timezone('Asia/Manila')->withoutOverlapping();

// Admin-3, account lifecycle: deactivate separated employees' accounts and,
// when System Settings → Security says so, accounts unused for too long.
Schedule::command('imis:account-lifecycle')->dailyAt('00:30')->timezone('Asia/Manila')->withoutOverlapping();

// HR-8: employees hear when a recurring deduction is nearly completed.
Schedule::command('imis:notify-loan-endings')->dailyAt('08:00')->timezone('Asia/Manila')->withoutOverlapping();

// Queued work (bell and email notifications, so a page never waits on the
// mail server). Needs only `schedule:run` in cron, like every task here: the
// worker handles what is waiting, then stops after at most 50 seconds, so the
// next minute's run never overlaps it. Failed jobs show in System Health.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --backoff=30')->everyMinute()->withoutOverlapping();

// Admin-3: a task paused on Admin → Scheduled Tasks is skipped until it
// is resumed. Keep this last, so it covers every task above.
foreach (Schedule::events() as $task) {
    $task->skip(fn () => app(ScheduledTasks::class)->isPaused($task));
}
