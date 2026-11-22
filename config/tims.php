<?php

return [

    // Shared secret TIMS (../csc-tims) sends as a bearer token to read the
    // employees it may add as staff (its "Add Staff Account" form). Empty = off.
    'api_token' => env('TIMS_API_TOKEN'),

    // Short name of TIMS's entry in the application directory, for the TIMS
    // role each employee already holds.
    'application' => env('TIMS_APPLICATION', 'TIMS'),

    // The offices TIMS staff come from: every field and satellite office, and
    // these regional units — the ORD (TIMS's Management) and the Human
    // Resource Division, which runs the trainings.
    'regional_units' => ['ORD', 'HRD'],

];
