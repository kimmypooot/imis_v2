<?php

namespace App\Http\Middleware;

use App\Support\Security\LoginHistoryRecorder;
use App\Support\Security\SessionLimits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out once their sign-in passes the absolute cap (legacy
 * SESSION_ABSOLUTE_LIMIT). The idle timeout needs nothing here: Laravel
 * already drops a session that has been idle for `session.lifetime`.
 */
class EnforceSessionLimits
{
    public function __construct(
        private readonly SessionLimits $limits,
        private readonly LoginHistoryRecorder $history,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        // Signed in without going through the login form — i.e. by the
        // remember cookie after the previous session ended.
        if (! $this->limits->started($session)) {
            $this->limits->start($session, Auth::viaRemember());
        }

        if (! $this->limits->expired($session)) {
            return $next($request);
        }

        $this->history->timedOut($session->getId(), $user->id);

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['alive' => false, 'reason' => 'limit'], 401);
        }

        return redirect()->guest(route('login'))
            ->with('status', 'Your session reached its time limit. Please sign in again.');
    }
}
