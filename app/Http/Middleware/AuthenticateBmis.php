<?php

namespace App\Http\Middleware;

use App\Enums\ApiIntegration;
use App\Support\Integrations\ApiTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server guard for BMIS's employee list: the bearer token must be
 * a live token from the Integration Tokens page or the legacy BMIS_API_TOKEN.
 */
class AuthenticateBmis
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(ApiTokens::class)->authorize(ApiIntegration::Bmis, $request->bearerToken(), $request->ip())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
