<?php

return [

    // Shared secret PROCTAD sends as a bearer token to read the staff who hold
    // a PROCTAD role (its Users page). Empty = off.
    'api_token' => env('PROCTAD_API_TOKEN'),

    // Short name of PROCTAD's entry in the application directory.
    'application' => env('PROCTAD_APPLICATION', 'PROCTAD'),

];
