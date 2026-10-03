<?php

namespace App\Support\Security;

/**
 * A short, human label for a User-Agent string ("Chrome on Windows"), for
 * the session and log tables — the legacy "Browser" column. Deliberately
 * simple: no dependency, and the full string is still shown on hover.
 */
class UserAgents
{
    public static function describe(?string $agent): string
    {
        if (! $agent) {
            return 'Unknown';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform ? "{$browser} on {$platform}" : $browser;
    }
}
