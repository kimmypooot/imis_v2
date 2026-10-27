<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server guard for the Recruitment Portal's endpoints: the bearer
 * token must equal RECRUITMENT_API_TOKEN. With no token configured the
 * endpoints are off.
 */
class AuthenticateRecruitmentPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('recruitment.api_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
