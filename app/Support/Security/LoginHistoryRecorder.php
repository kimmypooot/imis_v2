<?php

namespace App\Support\Security;

use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Legacy\SsoLogout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes login history and ends sessions (legacy session tracking +
 * Active Sessions "force logout").
 *
 * Called from AuthenticatedSessionController *after* the session id is
 * regenerated, so the stored id is the one the session actually keeps.
 */
class LoginHistoryRecorder
{
    public function __construct(private readonly SsoLogout $systems) {}

    public function started(Request $request, User $user): void
    {
        LoginHistory::query()->forceCreate([
            'user_id' => $user->id,
            'username' => $user->username,
            'session_id' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 495, '') ?: null,
            'logged_in_at' => now(),
        ]);
    }

    /**
     * Closes the sign-in for this session — or, if the session id has
     * since rotated, the user's most recent open sign-in.
     */
    public function signedOut(string $sessionId, ?int $userId = null): void
    {
        $this->close($sessionId, $userId, 'user');
    }

    /** The sign-in passed its absolute time limit (EnforceSessionLimits). */
    public function timedOut(string $sessionId, ?int $userId = null): void
    {
        $this->close($sessionId, $userId, 'timeout');
    }

    /** Closes the sign-in and signs the person out of the systems opened from it (SsoLogout). */
    private function close(string $sessionId, ?int $userId, string $endedBy): void
    {
        $this->systems->signOut($sessionId);

        $ended = ['logged_out_at' => now(), 'ended_by' => $endedBy];

        $updated = LoginHistory::query()->where('session_id', $sessionId)->whereNull('logged_out_at')->update($ended);

        if ($updated === 0 && $userId !== null) {
            LoginHistory::query()->where('user_id', $userId)->whereNull('logged_out_at')->latest('logged_in_at')->limit(1)->update($ended);
        }
    }

    /**
     * Ends another user's session immediately: deletes it from the session
     * store and rotates their "remember me" token, so the browser can't
     * silently sign back in.
     */
    public function forceEnd(string $sessionId): ?int
    {
        $table = config('session.table', 'sessions');
        $userId = DB::table($table)->where('id', $sessionId)->value('user_id');

        if ($userId === null) {
            return null;
        }

        DB::table($table)->where('id', $sessionId)->delete();

        $this->systems->signOut($sessionId);

        User::query()->whereKey($userId)->first()?->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        LoginHistory::query()
            ->where('session_id', $sessionId)
            ->whereNull('logged_out_at')
            ->update(['logged_out_at' => now(), 'ended_by' => 'admin']);

        return (int) $userId;
    }
}
