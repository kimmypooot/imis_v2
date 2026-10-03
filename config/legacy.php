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
