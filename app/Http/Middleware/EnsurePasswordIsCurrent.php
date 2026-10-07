<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password expiry (System Settings → Security): once a signed-in user's
 * password is older than the set number of days, every page sends them
 * to Change Password until they pick a new one. Signing out and the
 * session heartbeat still work. The same happens after a Super
 * Administrator sets their password (`must_change_password`).
 */
class EnsurePasswordIsCurrent
{
    private const ALLOWED_ROUTES = ['account.password.edit', 'account.password.update', 'logout', 'session.heartbeat'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs(...self::ALLOWED_ROUTES) || ! SecurityPolicy::mustChangePassword($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            $message = $user->must_change_password
                ? 'Your password was set by an administrator. Please choose a new one.'
                : 'Your password has expired. Please choose a new one.';

            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('account.password.edit');
    }
}
