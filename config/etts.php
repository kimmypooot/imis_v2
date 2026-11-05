<?php

return [

    // Shared secret ETTS sends as a bearer token to read the people directory
    // (to suggest an addressee or signatory on a routing slip). Empty = off.
    'api_token' => env('ETTS_API_TOKEN'),

];
