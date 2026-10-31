<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server guard for IPCRS's employee list: the bearer token must
 * equal IPCRS_API_TOKEN. With no token configured the endpoint is off.
 */
class AuthenticateIpcrs
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ipcrs.api_token');

        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
