<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * System Settings → Security → "Allow fingerprint / face sign-in". While it
 * is off, the passkey routes (laravel/passkeys) refuse to sign anyone in or
 * add a device. Removing a device stays open, so people can tidy up after the
 * feature is switched off.
 */
class EnsurePasskeysEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SecurityPolicy::passkeysEnabled() || $request->routeIs('passkey.destroy')) {
            return $next($request);
        }

        $message = 'Fingerprint and face sign-in is turned off. Please use your password.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : abort(403, $message);
    }
}
