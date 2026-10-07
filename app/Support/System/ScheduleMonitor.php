<?php

namespace App\Support\System;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers when each scheduled task last finished (or failed), so the
 * health check can tell whether `schedule:run` is actually in cron and
 * each job is keeping to its timetable. Recorded from the scheduler's
 * own events (see AppServiceProvider); keyed by the task's mutex name,
 * which is stable for a given command and timetable.
 */
class ScheduleMonitor
{
    private const KEY = 'system:schedule-runs';

    public function record(Event $task, bool $succeeded): void
    {
        $runs = Cache::get(self::KEY, []);
        $runs[$task->mutexName()] = ['at' => now()->toIso8601String(), 'ok' => $succeeded];

        Cache::forever(self::KEY, $runs);
    }

    /**
     * Every scheduled task with its timetable, when it was last due and
     * when it last ran.
     *
     * @return array<int, array{name: string, expression: string, due: Carbon, last_run: ?Carbon, succeeded: ?bool}>
     */
    public function tasks(): array
    {
        // routes/console.php (where the schedule lives) is only loaded by
        // the console kernel; this makes the schedule known on web requests.
        app(ConsoleKernel::class)->bootstrap();

        $runs = Cache::get(self::KEY, []);

        return collect(app(Schedule::class)->events())->map(function (Event $task) use ($runs) {
            $run = $runs[$task->mutexName()] ?? null;
            $due = (new CronExpression($task->expression))->getPreviousRunDate(now($task->timezone), 0, true);

            return [
                'name' => $this->name($task),
                'expression' => $task->expression,
                'due' => Carbon::instance($due),
                'last_run' => $run ? Carbon::parse($run['at']) : null,
                'succeeded' => $run['ok'] ?? null,
            ];
        })->values()->all();
    }

    private function name(Event $task): string
    {
        if ($task->description) {
            return $task->description;
        }

        // "'/path/php' 'artisan' imis:expire-sessions" → "imis:expire-sessions"
        return trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $task->command)) ?: 'Scheduled task';
    }
}
