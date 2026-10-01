<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level guard: `->middleware('permission:users.view')` or
 * `->middleware('permission:users.view,users.update')` (any one matching).
 *
 * This only protects the route boundary. Controllers/actions that perform
 * sensitive operations must still authorize independently (via policies or
 * `$user->can()`), since a route can always be reordered or reused.
 */
class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless(
            $request->user() && $request->user()->hasAnyPermission($permissions),
            403,
        );

        return $next($request);
    }
}
