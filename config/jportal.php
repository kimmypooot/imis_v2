<?php

return [

    // Shared secret JPortal sends as a bearer token to read the public
    // office directory (regional support units, field and satellite
    // offices). Empty = off.
    'api_token' => env('JPORTAL_API_TOKEN'),

];
