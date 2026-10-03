<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Support\Backup\DatabaseDumper;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The legacy "Backup Database" page. A backup holds every record in IMIS
 * (including password hashes), so it is limited to Super Administrators —
 * even holding `backups.create` isn't enough on its own — requires the
 * current password again, is rate-limited (route), and every download is
 * audited.
 */
class BackupController extends Controller
{
    public function index(Request $request, DatabaseDumper $dumper): Response
    {
        $this->ensureAllowed($request);

        return Inertia::render('Admin/Backup/Index', [
            'tables' => $dumper->tables(),
            'connection' => [
                'driver' => config('database.default'),
                'database' => config('database.connections.'.config('database.default').'.database'),
            ],
        ]);
    }

    public function download(Request $request, DatabaseDumper $dumper): StreamedResponse
    {
        $this->ensureAllowed($request);

        $request->validate(
            ['password' => ['required', 'string', 'current_password']],
            ['password.current_password' => 'That password is incorrect.'],
        );

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

    private function ensureAllowed(Request $request): void
    {
        abort_unless(
            $request->user()->hasRole(RoleName::SuperAdmin) && $request->user()->hasPermission(PermissionName::BackupsCreate->value),
            403,
            'Only a Super Administrator can back up the database.',
        );
    }
}
