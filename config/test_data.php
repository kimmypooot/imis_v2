<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Resetting test data (System Settings → Danger zone)
    |--------------------------------------------------------------------------
    | While IMIS 3 is in development, a Super Administrator can delete the
    | test activity (leave, SALN, notices, payroll runs, logs, …) and keep
    | the people, their records and the setup. Off in production unless
    | this is set to true on purpose.
    */
    'reset_allowed' => (bool) env('IMIS_ALLOW_TEST_DATA_RESET', env('APP_ENV', 'production') !== 'production'),
];
