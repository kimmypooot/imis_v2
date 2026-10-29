<?php

return [

    // Shared secret ICT SRTS sends as a bearer token to read the list of
    // current employees (for form signatories). Empty = off.
    'api_token' => env('ICTSRTS_API_TOKEN'),

    // Short name of the committee that is the Information Technology Group.
    // Its active members (today) are ITG members in ICT SRTS; the super-admin
    // manages the group by managing that committee.
    'itg_committee' => env('ICTSRTS_ITG_COMMITTEE', 'ITG'),

];
