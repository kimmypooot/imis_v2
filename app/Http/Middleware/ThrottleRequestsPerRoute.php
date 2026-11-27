<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with a separate count for each route. Laravel's own
 * middleware counts a signed-in user's requests under one key for every
 * route, so ordinary use of one limited page (e.g. 60 a minute) used up a
 * strict limit elsewhere (e.g. Reset test data, 3 a minute) and that page
 * answered 429 on the first try. Named limiters (`throttle:login`) are
 * unaffected: they build their own keys.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request): string
    {
        return sha1(parent::resolveRequestSignature($request).'|'.$this->routeIdentity($request));
    }

    private function routeIdentity(Request $request): string
    {
        $route = $request->route();

        return $route === null ? '' : $request->method().' '.$route->uri();
    }
}
