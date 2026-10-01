<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level guard: `->middleware('role:super-admin')` or
 * `->middleware('role:super-admin,rsu-manager')` (any one matching).
 *
 * This only protects the route boundary. Controllers/actions that perform
 * sensitive operations must still authorize independently (via policies or
 * `$user->can()`), since a route can always be reordered or reused.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(
            $request->user() && $request->user()->hasAnyRole($roles),
            403,
        );

        return $next($request);
    }
}
