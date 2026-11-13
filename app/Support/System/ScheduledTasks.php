<?php

namespace App\Support\System;

use App\Models\ScheduledTaskPause;
use App\Models\User;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Admin-3, Admin → Scheduled Tasks: every task in routes/console.php in
 * plain words, with its timetable, last and next run, and controls —
 * pause (the scheduler skips it until resumed) and run now. Every task
 * here is safe to run again early: each one skips work already done
 * (e.g. leave accrual never credits a month twice).
 *
 * Runs started here aren't scheduler runs, so they don't count for
 * System Health; they're remembered separately with who started them.
 */
class ScheduledTasks
{
    private const MANUAL_KEY = 'system:schedule-manual-runs';

    /** What each task does, by its command (or name, for closures). */
    private const DESCRIPTIONS = [
        'imis:expire-sessions' => 'Closes sign-in sessions that idled out or were abandoned.',
        'imis:leave-accrual' => 'Credits last month\'s vacation and sick leave (and the yearly leave in January).',
        'imis:apply-reassignments' => 'Moves people to their new office on the day a reassignment takes effect.',
        'imis:sync-legacy' => 'Re-sends account changes the legacy IMIS missed. Does nothing while the bridge is off.',
        'prune-sso-tokens' => 'Deletes used and expired single sign-on tokens.',
        'imis:backup' => 'Backs up the database on the server. Skipped while automatic backups are off.',
        'imis:prune-logs' => 'Deletes logs older than their retention period.',
        'imis:collect-metrics' => 'Collects the Executive Dashboard figures from IMIS and connected systems.',
        'imis:account-lifecycle' => 'Deactivates the accounts of separated employees, and accounts unused past the limit in System Settings → Security.',
        'imis:notify-loan-endings' => 'Tells employees when a recurring deduction (loan) is nearly completed.',
        'queue:work' => 'Sends the emails and notifications waiting in the queue, so pages stay quick. Pausing it holds those emails until it is resumed.',
        'imis:executive-digest' => 'Sends top management the weekly summary. Skipped while it is turned off.',
    ];

    public function __construct(private readonly ScheduleMonitor $monitor) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $pauses = ScheduledTaskPause::query()->with('pauser:id,name')->get()->keyBy('task');
        $manual = Cache::get(self::MANUAL_KEY, []);
        $events = collect($this->events())->keyBy(fn (Event $event) => ScheduleMonitor::nameOf($event));

        return collect($this->monitor->tasks())->map(function (array $task) use ($pauses, $manual, $events) {
            $event = $events[$task['name']];
            $pause = $pauses->get($task['name']);

            return [
                'name' => $task['name'],
                'description' => self::DESCRIPTIONS[Str::before($task['name'], ' ')] ?? null,
                'expression' => $task['expression'],
                'when' => $this->when($task['expression'], $event->timezone),
                'last_run' => $task['last_run']?->toIso8601String(),
                'succeeded' => $task['succeeded'],
                'next_run' => Carbon::instance((new CronExpression($task['expression']))->getNextRunDate(now($event->timezone)))->toIso8601String(),
                'paused' => $pause ? [
                    'at' => $pause->created_at->toIso8601String(),
                    'by' => $pause->pauser?->name,
                    'reason' => $pause->reason,
                ] : null,
                'manual' => $manual[$task['name']] ?? null,
            ];
        })->all();
    }

    /**
     * The timetable in words, in office time (Asia/Manila): "Every 5
     * minutes", "Daily at 2:00 AM", "Mondays at 7:00 AM", "Monthly on
     * the 1st at 1:00 AM". Anything else shows the cron expression.
     */
    private function when(string $expression, mixed $timezone): string
    {
        [$minute, $hour, $day, $month, $weekday] = explode(' ', $expression) + array_fill(0, 5, '*');

        if (preg_match('/^\*\/(\d+)$/', $minute, $every) && $hour === '*' && "{$day}{$month}{$weekday}" === '***') {
            return "Every {$every[1]} minutes";
        }
        if (ctype_digit($minute) && $hour === '*' && "{$day}{$month}{$weekday}" === '***') {
            return $minute === '0' ? 'Every hour' : "Every hour at :{$minute}";
        }
        if (! ctype_digit($minute) || ! ctype_digit($hour) || $month !== '*') {
            return $expression;
        }

        // The hour and minute are in the task's timezone; say them in office
        // time, which can move the day (Sunday 23:00 UTC is Monday 7:00 AM).
        $there = Carbon::createFromTime((int) $hour, (int) $minute, 0, $timezone ?: config('app.timezone'));
        $at = $there->copy()->timezone('Asia/Manila');
        $time = $at->format('g:i A');
        $dayShift = $at->toDateString() <=> $there->toDateString();
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return match (true) {
            $day === '*' && $weekday === '*' => "Daily at {$time}",
            $day === '*' && ctype_digit($weekday) => $weekdays[((int) $weekday + $dayShift + 7) % 7]."s at {$time}",
            ctype_digit($day) && $weekday === '*' => 'Monthly on the '.Carbon::create(2000, 1, (int) $day)->format('jS')." at {$time}",
            default => $expression,
        };
    }

    public function isPaused(Event $event): bool
    {
        return ScheduledTaskPause::query()->where('task', ScheduleMonitor::nameOf($event))->exists();
    }

    public function pause(string $name, User $by, ?string $reason): void
    {
        $this->find($name);
        ScheduledTaskPause::query()->updateOrCreate(['task' => $name], ['paused_by' => $by->id, 'reason' => $reason]);
    }

    public function resume(string $name): void
    {
        ScheduledTaskPause::query()->where('task', $name)->delete();
    }

    /**
     * Runs a task now, in this request, and remembers the outcome.
     *
     * @return array{at: string, ok: bool, by: string, output: string}
     */
    public function run(string $name, User $by): array
    {
        $event = $this->find($name);
        set_time_limit(0);

        try {
            if ($event instanceof CallbackEvent) {
                $event->run(app());
                [$ok, $output] = [true, 'Done.'];
            } else {
                $ok = Artisan::call($name) === 0;
                $output = trim(Artisan::output()) ?: ($ok ? 'Done.' : 'Failed.');
            }
        } catch (Throwable $e) {
            report($e);
            [$ok, $output] = [false, $e->getMessage()];
        }

        $result = ['at' => now()->toIso8601String(), 'ok' => $ok, 'by' => $by->name, 'output' => Str::limit($output, 2000)];
        Cache::forever(self::MANUAL_KEY, [...Cache::get(self::MANUAL_KEY, []), $name => $result]);

        return $result;
    }

    /**
     * @throws InvalidArgumentException for a task that isn't scheduled
     */
    private function find(string $name): Event
    {
        foreach ($this->events() as $event) {
            if (ScheduleMonitor::nameOf($event) === $name) {
                return $event;
            }
        }

        throw new InvalidArgumentException("No scheduled task named [{$name}].");
    }

    /**
     * @return array<int, Event>
     */
    private function events(): array
    {
        app(ConsoleKernel::class)->bootstrap();

        return app(Schedule::class)->events();
    }
}
