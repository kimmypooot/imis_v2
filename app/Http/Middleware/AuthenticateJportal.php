<?php

namespace App\Http\Middleware;

use App\Enums\ApiIntegration;
use App\Support\Integrations\ApiTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server guard for JPortal's directory feed: the bearer token must
 * be a live token from the Integration Tokens page or the legacy JPORTAL_API_TOKEN.
 */
class AuthenticateJportal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(ApiTokens::class)->authorize(ApiIntegration::Jportal, $request->bearerToken(), $request->ip())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
