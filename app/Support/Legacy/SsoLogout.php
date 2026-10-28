<?php

namespace App\Support\Legacy;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single sign-out for Laravel systems under IMIS (docs/sso-logout.md).
 * Redeeming a hub token opens an `sso_sessions` row holding a random `sid`
 * the system keeps with its own session. When the person signs out of IMIS,
 * each such system is told to end the session with that `sid`.
 *
 * Best effort: a system that is down or slow never blocks signing out of
 * IMIS, and its session is left to its own idle limit.
 */
class SsoLogout
{
    /**
     * Opens a session for a redeemed token and returns the `sid` for the
     * system, or null when the token wasn't issued from an IMIS session.
     */
    public function open(object $token, string $url): ?string
    {
        if (blank($token->imis_session_id)) {
            return null;
        }

        $sid = Str::random(64);

        DB::table('sso_sessions')->insert([
            'user_id' => $token->user_id,
            'application_id' => $token->application_id,
            'imis_session_id' => $token->imis_session_id,
            'sid' => Crypt::encryptString($sid),
            'logout_url' => $this->logoutUrl($url),
            'created_at' => now(),
        ]);

        return $sid;
    }

    /**
     * Tells every system opened from this IMIS session to sign out.
     *
     * @return int how many systems answered with success
     */
    public function signOut(string $imisSessionId): int
    {
        $sessions = DB::table('sso_sessions')
            ->where('imis_session_id', $imisSessionId)
            ->whereNull('ended_at')
            ->get();

        if ($sessions->isEmpty()) {
            return 0;
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => $sessions->map(
                fn (object $session) => $pool->as((string) $session->id)
                    ->connectTimeout(2)->timeout(3)->acceptJson()
                    ->withoutRedirecting()
                    ->post($session->logout_url, ['sid' => Crypt::decryptString($session->sid)])
            )->all());
        } catch (Throwable) {
            $responses = [];
        }

        DB::table('sso_sessions')->whereIn('id', $sessions->pluck('id'))->update(['ended_at' => now()]);

        return collect($responses)->filter(fn ($response) => ! $response instanceof Throwable && $response->successful())->count();
    }

    /** Deletes sessions ended over a day ago, or opened over 30 days ago; run with the token prune. */
    public function prune(): int
    {
        return DB::table('sso_sessions')
            ->where(fn ($query) => $query->where('ended_at', '<', now()->subDay())->orWhere('created_at', '<', now()->subDays(30)))
            ->delete();
    }

    /** The system's logout address: its origin plus `legacy.sso.logout_path`. */
    private function logoutUrl(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/'.ltrim((string) config('legacy.sso.logout_path'), '/');
    }
}
