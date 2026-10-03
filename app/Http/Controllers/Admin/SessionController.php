<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Security\LoginHistoryRecorder;
use App\Support\Security\UserAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legacy "Active Sessions" page: everyone signed in right now (from
 * the database session store), with their sign-in time from login
 * history, and a "Force logout" per session.
 */
class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission(PermissionName::SessionsView->value), 403);
        abort_unless(config('session.driver') === 'database', 404, 'Active sessions need the database session driver.');

        $sessions = DB::table(config('session.table', 'sessions'))
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity']);

        $users = User::query()->whereKey($sessions->pluck('user_id')->unique()->all())->get(['id', 'name', 'username', 'profile_photo_path'])->keyBy('id');
        $logins = LoginHistory::query()->whereIn('session_id', $sessions->pluck('id')->all())->pluck('logged_in_at', 'session_id');
        $current = $request->session()->getId();

        return Inertia::render('Admin/Sessions/Index', [
            'sessions' => $sessions->map(fn (object $session) => [
                // The session id itself is a credential: only a hash leaves the server.
                'key' => hash('sha256', $session->id),
                'user' => $users->get($session->user_id)?->only(['id', 'name', 'username']),
                'profile_photo_url' => $users->get($session->user_id)?->profile_photo_url,
                'ip_address' => $session->ip_address,
                'browser' => UserAgents::describe($session->user_agent),
                'user_agent' => $session->user_agent,
                'logged_in_at' => isset($logins[$session->id]) ? $logins[$session->id]->toIso8601String() : null,
                'last_activity' => now()->setTimestamp($session->last_activity)->toIso8601String(),
                'is_current' => $session->id === $current,
            ])->values(),
            'canRevoke' => $request->user()->hasPermission(PermissionName::SessionsRevoke->value),
        ]);
    }

    /**
     * Ends someone's session right away. Addressed by the hash the page was
     * given, so a raw session id never travels through the browser.
     */
    public function destroy(Request $request, string $key, LoginHistoryRecorder $recorder): RedirectResponse
    {
        abort_unless($request->user()->hasPermission(PermissionName::SessionsRevoke->value), 403);

        $sessionId = DB::table(config('session.table', 'sessions'))
            ->whereNotNull('user_id')
            ->pluck('id')
            ->first(fn (string $id) => hash_equals(hash('sha256', $id), $key));

        abort_if($sessionId === null, 404);

        if ($sessionId === $request->session()->getId()) {
            return back()->with('error', 'That is your own session — use Sign out instead.');
        }

        // Same protected-account rule as everywhere else: only a Super
        // Administrator may end a Super Administrator's session.
        $owner = User::query()->find(DB::table(config('session.table', 'sessions'))->where('id', $sessionId)->value('user_id'));
        abort_if($owner?->hasRole(RoleName::SuperAdmin) && ! $request->user()->hasRole(RoleName::SuperAdmin), 403);

        $userId = $recorder->forceEnd($sessionId);
        $user = User::query()->find($userId);

        $this->audit()->record(AuditEvent::SessionEnded, "Ended a session of {$user?->name}", $user);

        return back()->with('status', "{$user?->name}'s session was ended.");
    }
}
