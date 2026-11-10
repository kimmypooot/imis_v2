<?php

return [

    // Shared secret CARES sends as a bearer token to read the people directory
    // (action officers, and the staff and heads that sign its reports). Empty = off.
    'api_token' => env('CARES_API_TOKEN'),

];
