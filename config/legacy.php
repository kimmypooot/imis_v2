<?php

/*
|--------------------------------------------------------------------------
| Legacy IMIS bridge
|--------------------------------------------------------------------------
|
| While the old PHP subsystems (OTRS, ERIS, CTS, …) still run, they read
| accounts and system access from the legacy database and accept a
| sign-in from IMIS through a one-time `?sso_token=` (legacy
| includes/session.php). See App\Support\Legacy\LegacyBridge and
| App\Support\Legacy\LegacySso.
|
*/

return [

    // Mirror accounts and system access into the legacy tables
    // (users_cscro8, imis_system_access). Needs the `legacy` connection
    // in config/database.php (LEGACY_DB_*).
    'bridge' => (bool) env('LEGACY_BRIDGE', false),

    'connection' => env('LEGACY_DB_CONNECTION', 'legacy'),

    'sso' => [
        // Hosts whose launch URLs get a one-time sign-in token appended
        // (comma-separated, e.g. imis.cscro8.com). Empty = SSO off. A token
        // is never added to a URL on any other host.
        'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEGACY_SSO_HOSTS', ''))))),

        // Laravel systems built to sit under IMIS (e.g. PROCTAD), as
        // host[:port] (comma-separated, e.g. proctad.cscro8.com). They get
        // the same one-time token but need no legacy id, and redeem it at
        // the same endpoint with their own `audience`, getting back their
        // role in that system. Empty = off.
        'hub_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('SSO_HUB_HOSTS', ''))))),

        // Where a Laravel system under IMIS ends its session when the person
        // signs out of IMIS (POST sid=…), relative to the system's origin.
        // See docs/sso-logout.md.
        'logout_path' => env('SSO_LOGOUT_PATH', 'sso/logout'),

        // How long a token stays redeemable, in seconds.
        'ttl' => (int) env('LEGACY_SSO_TTL', 60),

        // Where subsystems redeem a token (POST token=…[&path=…]).
        'validate_path' => env('LEGACY_SSO_VALIDATE_PATH', 'api/v1/sso/validate'),

        // Require the subsystem to say which page it is (`path`), and check
        // it is the system the token was issued for. Turn on once the
        // patched session.php (deploy/legacy-imis) is live.
        'require_path' => (bool) env('LEGACY_SSO_REQUIRE_PATH', false),
    ],

];
