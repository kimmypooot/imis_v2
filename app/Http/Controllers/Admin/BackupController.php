<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Support\Backup\BackupStore;
use App\Support\Backup\DatabaseDumper;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The legacy "Backup Database" page, plus the backups kept on the server
 * (made nightly by `imis:backup`, or here with "Back up now"). A backup
 * holds every record in IMIS (including password hashes), so it is
 * limited to Super Administrators — even holding `backups.create` isn't
 * enough on its own. Downloading requires the current password again, is
 * rate-limited (route), and every download, backup and deletion is
 * audited.
 */
class BackupController extends Controller
{
    public function index(Request $request, DatabaseDumper $dumper, BackupStore $store): Response
    {
        $this->ensureAllowed($request);

        return Inertia::render('Admin/Backup/Index', [
            'tables' => $dumper->tables(),
            'connection' => [
                'driver' => config('database.default'),
                'database' => config('database.connections.'.config('database.default').'.database'),
            ],
            'backups' => collect($store->all())->map(fn (array $backup) => [
                ...$backup,
                'created_at' => $backup['created_at']->toIso8601String(),
            ])->values(),
            'schedule' => [
                'enabled' => (bool) Settings::get('backup_schedule_enabled'),
                'keep' => (int) Settings::get('backup_keep_count'),
            ],
        ]);
    }

    public function download(Request $request, DatabaseDumper $dumper): StreamedResponse
    {
        $this->ensureAllowed($request);
        $this->confirmPassword($request);

        $filename = 'imis-backup-'.now()->format('Y-m-d-His').'.sql';

        $this->audit()->record(AuditEvent::DatabaseBackupDownloaded, "Downloaded a database backup ({$filename})", null, ['file' => $filename]);

        return response()->streamDownload(
            fn () => $dumper->dump(function (string $chunk) {
                echo $chunk;
            }),
            $filename,
            ['Content-Type' => 'application/sql; charset=UTF-8'],
        );
    }

    /**
     * "Back up now": saves a backup on the server, then applies the
     * keep-count like the nightly run does.
     */
    public function store(Request $request, BackupStore $store): RedirectResponse
    {
        $this->ensureAllowed($request);

        try {
            $backup = $store->create(automatic: false);
        } catch (Throwable $e) {
            report($e);
            $this->audit()->record(AuditEvent::DatabaseBackupFailed, 'Database backup failed', null, ['error' => str($e->getMessage())->limit(200)->value()]);

            return back()->with('error', 'The backup could not be saved. Check free disk space on System Health.');
        }

        $pruned = $store->prune();

        $this->audit()->record(AuditEvent::DatabaseBackupCreated, "Saved a database backup on the server ({$backup['name']})", null, [
            'file' => $backup['name'], 'size' => $backup['size'], 'pruned' => $pruned,
        ]);

        return back()->with('status', "Backup saved ({$backup['name']}).");
    }

    public function downloadStored(Request $request, BackupStore $store, string $backup): BinaryFileResponse
    {
        $this->ensureAllowed($request);
        abort_unless($store->exists($backup), 404);
        $this->confirmPassword($request);

        $this->audit()->record(AuditEvent::DatabaseBackupDownloaded, "Downloaded a stored database backup ({$backup})", null, ['file' => $backup]);

        return response()->download($store->path($backup), $backup, [
            'Content-Type' => str_ends_with($backup, '.gz') ? 'application/gzip' : 'application/sql; charset=UTF-8',
        ]);
    }

    public function destroy(Request $request, BackupStore $store, string $backup): RedirectResponse
    {
        $this->ensureAllowed($request);
        abort_unless($store->delete($backup), 404);

        $this->audit()->record(AuditEvent::DatabaseBackupDeleted, "Deleted a stored database backup ({$backup})", null, ['file' => $backup]);

        return back()->with('status', 'Backup deleted.');
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(
            ['password' => ['required', 'string', 'current_password']],
            ['password.current_password' => 'That password is incorrect.'],
        );
    }

    private function ensureAllowed(Request $request): void
    {
        abort_unless(
            $request->user()->hasRole(RoleName::SuperAdmin) && $request->user()->hasPermission(PermissionName::BackupsCreate->value),
            403,
            'Only a Super Administrator can back up the database.',
        );
    }
}
