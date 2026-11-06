<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\SystemAccessLog;
use App\Models\User;
use App\Support\Security\UserAgents;
use App\Support\Tables\CsvExport;
use App\Support\Tables\ServerTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The legacy read-only log pages: Login History, System Access Logs, and
 * Security Logs. Large tables, so search/sort/paging run on the server
 * (ServerTable) and CSV exports stream every matching row (CsvExport).
 * All need `audit_logs.view` (the same as the Admin Audit Log).
 */
class ActivityLogController extends Controller
{
    /**
     * Failed, blocked, and locked-out sign-ins, with a severity like the
     * legacy imis_security_audit_logs.
     */
    private const SECURITY_EVENTS = [
        'auth.login_failed' => 'warning',
        'auth.login_blocked' => 'warning',
        'auth.lockout' => 'critical',
        'auth.session_ended' => 'info',
    ];

    /**
     * The accounts behind a page of log rows, so the table can show a name
     * and photo instead of a bare username.
     *
     * @param  IlluminateSupportCollection<int, string|null>  $usernames
     * @return IlluminateSupportCollection<string, User>
     */
    private function peopleByUsername($usernames)
    {
        return User::query()->whereIn('username', $usernames->filter()->unique()->all())->get()->keyBy('username');
    }

    // ── Login History ────────────────────────────────────────────────

    public function logins(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $page = ServerTable::paginate($this->loginQuery($request), $request);
        $alive = LoginHistory::liveSessionIds(collect($page->items())->pluck('session_id')->filter()->all());
        $people = $this->peopleByUsername(collect($page->items())->pluck('username'));

        return Inertia::render('Admin/Logs/LoginHistory', [
            'logins' => $page->through(fn (LoginHistory $login) => [
                'id' => $login->id,
                'username' => $login->username,
                'name' => $people->get($login->username)?->name,
                'profile_photo_url' => $people->get($login->username)?->profile_photo_url,
                'user_id' => $login->user_id,
                'ip_address' => $login->ip_address,
                'logged_in_at' => $login->logged_in_at->toIso8601String(),
                'logged_out_at' => $login->logged_out_at?->toIso8601String(),
                'status' => $login->statusFor(in_array($login->session_id, $alive, true)),
                'browser' => UserAgents::describe($login->user_agent),
                'user_agent' => $login->user_agent,
            ]),
            'query' => ServerTable::query($request, ['from', 'to']),
        ]);
    }

    public function exportLogins(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        return CsvExport::stream(
            'imis-login-history',
            ['Username', 'IP address', 'Login time', 'Logout time', 'Ended by', 'User agent'],
            $this->loginQuery($request),
            fn (LoginHistory $login) => [
                $login->username, $login->ip_address, $login->logged_in_at, $login->logged_out_at, $login->ended_by, $login->user_agent,
            ],
        );
    }

    private function loginQuery(Request $request)
    {
        return ServerTable::apply(
            $this->dateRange(LoginHistory::query(), $request, 'logged_in_at'),
            $request,
            ['username', 'ip_address', 'user_agent'],
            ['username' => 'username', 'ip_address' => 'ip_address', 'logged_in_at' => 'logged_in_at', 'logged_out_at' => 'logged_out_at'],
            'logged_in_at',
        );
    }

    // ── System Access Logs ───────────────────────────────────────────

    public function access(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $page = ServerTable::paginate($this->accessQuery($request), $request);
        $people = $this->peopleByUsername(collect($page->items())->pluck('username'));

        return Inertia::render('Admin/Logs/AccessLogs', [
            'logs' => $page->through(fn (SystemAccessLog $log) => [
                'id' => $log->id,
                'username' => $log->username,
                'name' => $people->get($log->username)?->name,
                'profile_photo_url' => $people->get($log->username)?->profile_photo_url,
                'application_name' => $log->application_name,
                'role_name' => $log->role_name,
                'ip_address' => $log->ip_address,
                'accessed_at' => $log->accessed_at->toIso8601String(),
                'browser' => UserAgents::describe($log->user_agent),
                'user_agent' => $log->user_agent,
            ]),
            'query' => ServerTable::query($request, ['from', 'to', 'application_id']),
            'applicationOptions' => Application::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Application $application) => ['value' => $application->id, 'label' => $application->name]),
        ]);
    }

    public function exportAccess(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        return CsvExport::stream(
            'imis-system-access-logs',
            ['Username', 'System', 'Role', 'IP address', 'Accessed at', 'User agent'],
            $this->accessQuery($request),
            fn (SystemAccessLog $log) => [$log->username, $log->application_name, $log->role_name, $log->ip_address, $log->accessed_at, $log->user_agent],
        );
    }

    private function accessQuery(Request $request)
    {
        $query = $this->dateRange(SystemAccessLog::query(), $request, 'accessed_at')
            ->when($request->integer('application_id') ?: null, fn ($query, $id) => $query->where('application_id', $id));

        return ServerTable::apply(
            $query,
            $request,
            ['username', 'application_name', 'role_name', 'ip_address'],
            ['username' => 'username', 'application_name' => 'application_name', 'role_name' => 'role_name', 'ip_address' => 'ip_address', 'accessed_at' => 'accessed_at'],
            'accessed_at',
        );
    }

    // ── Security Logs ────────────────────────────────────────────────

    public function security(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        return Inertia::render('Admin/Logs/SecurityLogs', [
            'events' => ServerTable::paginate($this->securityQuery($request), $request)->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event->value,
                'event_label' => $log->event->label(),
                'severity' => self::SECURITY_EVENTS[$log->event->value] ?? 'info',
                'actor_label' => $log->actor_label,
                'target_label' => $log->target_label,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            // Legacy "IP Address / Attempts" panel: who keeps failing.
            'topIps' => AuditLog::query()
                ->whereIn('event', ['auth.login_failed', 'auth.login_blocked', 'auth.lockout'])
                ->where('created_at', '>=', now()->subDays(7))
                ->whereNotNull('ip_address')
                ->select('ip_address', DB::raw('count(*) as attempts'), DB::raw('max(created_at) as last_seen'))
                ->groupBy('ip_address')
                ->orderByDesc('attempts')
                ->limit(10)
                ->get()
                ->map(fn ($row) => ['ip_address' => $row->ip_address, 'attempts' => (int) $row->attempts, 'last_seen' => (string) $row->last_seen]),
            'query' => ServerTable::query($request, ['from', 'to']),
        ]);
    }

    public function exportSecurity(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        return CsvExport::stream(
            'imis-security-logs',
            ['Event', 'Severity', 'User', 'IP address', 'Details', 'Timestamp'],
            $this->securityQuery($request),
            fn (AuditLog $log) => [
                $log->event->label(), self::SECURITY_EVENTS[$log->event->value] ?? 'info',
                $log->actor_label ?? $log->target_label, $log->ip_address, $log->description, $log->created_at,
            ],
        );
    }

    private function securityQuery(Request $request)
    {
        return ServerTable::apply(
            $this->dateRange(AuditLog::query()->whereIn('event', array_keys(self::SECURITY_EVENTS)), $request, 'created_at'),
            $request,
            ['description', 'ip_address', 'actor_label', 'target_label'],
            ['event' => 'event', 'ip_address' => 'ip_address', 'created_at' => 'created_at'],
            'created_at',
        );
    }

    private function dateRange($query, Request $request, string $column)
    {
        $parse = fn (?string $value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;

        return $query
            ->when($parse($request->query('from')), fn ($query, $from) => $query->where($column, '>=', "{$from} 00:00:00"))
            ->when($parse($request->query('to')), fn ($query, $to) => $query->where($column, '<=', "{$to} 23:59:59"));
    }
}
