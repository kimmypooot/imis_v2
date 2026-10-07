<?php

namespace App\Support\System;

use App\Support\Backup\BackupStore;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The deployment health check behind Admin → System Health and
 * `imis:health`: is everything the IMIS depends on in place and working?
 * Each check is "ok", "warn" (works, but needs attention) or "fail"
 * (something is broken). Never shows a secret or a personal record.
 */
class HealthChecks
{
    /** The scheduler counts as stopped when its most frequent job is this late. */
    private const SCHEDULER_GRACE_MINUTES = 15;

    /** A nightly backup older than this is late; older than FAIL, missed for days. */
    private const BACKUP_WARN_HOURS = 26;

    private const BACKUP_FAIL_HOURS = 74;

    public function __construct(
        private readonly ScheduleMonitor $schedule,
        private readonly BackupStore $backups,
    ) {}

    /**
     * @return array<int, array{group: string, label: string, status: string, detail: string}>
     */
    public function run(): array
    {
        $checks = [];
        $add = function (string $group, string $label, string $status, string $detail) use (&$checks) {
            $checks[] = compact('group', 'label', 'status', 'detail');
        };
        $attempt = function (string $group, string $label, callable $check) use ($add) {
            try {
                [$status, $detail] = $check();
            } catch (Throwable $e) {
                [$status, $detail] = ['fail', Str::limit($e->getMessage(), 160)];
            }
            $add($group, $label, $status, $detail);
        };

        $production = app()->isProduction();

        // Application.
        $add('Application', 'Encryption key (APP_KEY)', filled(config('app.key')) ? 'ok' : 'fail',
            filled(config('app.key')) ? 'Set. Keep a backup: it decrypts PDS identity numbers and signed submissions.' : 'Not set');
        $add('Application', 'Debug mode', config('app.debug') ? ($production ? 'fail' : 'warn') : 'ok',
            config('app.debug') ? ($production ? 'On in production: errors would show internals' : 'On (fine for development only)') : 'Off');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('Application', 'Site address uses HTTPS', $https || ! $production ? 'ok' : 'warn',
            $https ? 'Yes' : ($production ? 'APP_URL is not https://' : 'Not required outside production'));

        // Database.
        $attempt('Database', 'Database connection', function () {
            $started = microtime(true);
            DB::select('select 1');

            return ['ok', sprintf('%s, %d ms', DB::connection()->getDriverName(), (microtime(true) - $started) * 1000)];
        });
        $attempt('Database', 'Migrations', function () {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

            return $pending ? ['fail', "{$pending} not run — php artisan migrate"] : ['ok', 'Up to date'];
        });

        // Cache, storage, queue, mail.
        $attempt('Services', 'Cache', function () {
            $key = 'system:health:'.Str::random(8);
            Cache::put($key, 'ok', 60);
            $ok = Cache::pull($key) === 'ok';

            return [$ok ? 'ok' : 'fail', $ok ? 'Read and write work ('.config('cache.default').')' : 'Could not read back a value'];
        });
        $attempt('Services', 'Private file storage', function () {
            $path = 'health-check-'.Str::random(8).'.txt';
            Storage::disk('local')->put($path, 'ok');
            $ok = Storage::disk('local')->get($path) === 'ok';
            Storage::disk('local')->delete($path);

            return [$ok ? 'ok' : 'fail', $ok ? 'Writable (201 files, PDS photos, certificates)' : 'Could not write'];
        });
        $attempt('Services', 'Free disk space', function () {
            $free = (float) @disk_free_space(storage_path());
            $gb = $free / 1024 ** 3;

            return [$gb < 0.5 ? 'fail' : ($gb < 2 ? 'warn' : 'ok'), sprintf('%.1f GB free', $gb)];
        });
        $attempt('Services', 'Failed background jobs', function () {
            if (! Schema::hasTable('failed_jobs')) {
                return ['ok', 'None'];
            }
            $failed = DB::table('failed_jobs')->count();

            return [$failed ? 'warn' : 'ok', $failed ? "{$failed} failed — php artisan queue:failed" : 'None'];
        });
        $mailer = (string) config('mail.default');
        $add('Services', 'Outgoing mail', in_array($mailer, ['log', 'array'], true) ? ($production ? 'fail' : 'warn') : 'ok',
            in_array($mailer, ['log', 'array'], true) ? "Mailer is \"{$mailer}\": password-reset emails are not sent" : "Mailer: {$mailer}");

        // Backups kept on the server (imis:backup).
        $attempt('Backups', 'Automatic backups', fn () => $this->backupStatus($production));

        // Scheduler.
        $attempt('Scheduler', 'Scheduler (cron)', function () {
            $tasks = $this->schedule->tasks();
            $runs = collect($tasks)->pluck('last_run')->filter();
            if ($runs->isEmpty()) {
                return ['fail', 'No scheduled job has ever run — add `php artisan schedule:run` to cron (every minute)'];
            }
            $latest = $runs->max();
            $late = $latest->lt(now()->subMinutes(self::SCHEDULER_GRACE_MINUTES));

            return [$late ? 'fail' : 'ok', 'Last ran '.$latest->diffForHumans()];
        });
        foreach ($this->safeTasks() as $task) {
            $status = match (true) {
                $task['last_run'] === null => 'warn',
                $task['succeeded'] === false => 'fail',
                $task['last_run']->lt($task['due']->copy()->subMinutes(5)) => 'warn',
                default => 'ok',
            };
            $detail = $task['last_run'] === null
                ? 'No run recorded yet'
                : ($task['succeeded'] === false ? 'Last run failed ' : 'Last ran ').$task['last_run']->diffForHumans()
                    .($status === 'warn' ? ' (was due '.$task['due']->diffForHumans().')' : '');
            $add('Scheduler', $task['name'], $status, $detail);
        }

        // Legacy IMIS bridge.
        if (config('legacy.bridge')) {
            $attempt('Legacy IMIS', 'Legacy database', function () {
                DB::connection(config('legacy.connection'))->select('select 1');

                return ['ok', 'Reachable; account changes are mirrored'];
            });
        } else {
            $add('Legacy IMIS', 'Legacy bridge', 'ok', 'Off (LEGACY_BRIDGE): accounts are not mirrored to the legacy IMIS');
        }

        return $checks;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function backupStatus(bool $production): array
    {
        $latest = $this->backups->latest();
        $last = $latest ? 'Last backup '.$latest['created_at']->diffForHumans() : 'No backup saved on the server yet';

        if (! Settings::get('backup_schedule_enabled')) {
            return [$production ? 'warn' : 'ok', "Nightly backups are off (System Settings). {$last}."];
        }
        if ($latest === null) {
            return ['warn', $last.'; the first runs tonight at 02:00.'];
        }

        $hours = $latest['created_at']->diffInHours(now());

        return [match (true) {
            $hours > self::BACKUP_FAIL_HOURS => 'fail',
            $hours > self::BACKUP_WARN_HOURS => 'warn',
            default => 'ok',
        }, $last.'.'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function safeTasks(): array
    {
        try {
            return $this->schedule->tasks();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, array{status: string}>  $checks
     */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
    }
}
