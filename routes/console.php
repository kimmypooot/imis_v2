<?php

use App\Support\Legacy\LegacySso;
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
