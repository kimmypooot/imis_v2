<?php

return [

    // Shared secret BMIS (../new-bmis) sends as a bearer token to read the list of
    // current employees for its Employee manager and organization chart. Empty = off.
    'api_token' => env('BMIS_API_TOKEN'),

];
