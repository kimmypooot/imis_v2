<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server guard for JPortal's directory feed: the bearer token
 * must equal JPORTAL_API_TOKEN. With no token configured the endpoint is
 * off.
 */
class AuthenticateJportal
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('jportal.api_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
