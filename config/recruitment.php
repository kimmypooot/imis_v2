<?php

return [

    // Shared secret the Recruitment Portal sends as a bearer token to read
    // the vacant plantilla items and report its selections. Empty = off.
    'api_token' => env('RECRUITMENT_API_TOKEN'),

    // Short name of the Online Recruitment System's entry in the application directory.
    'application' => env('RECRUITMENT_APPLICATION', 'ORS'),

];
