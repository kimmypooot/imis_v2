<?php

return [

    // Shared secret IPCRS sends as a bearer token to read the list of current
    // employees (to name people on a form and cascade rows to them). Empty = off.
    'api_token' => env('IPCRS_API_TOKEN'),

    // Short name of the committee that is the Performance Management Team (CSC MC No. 06, s. 2012).
    // Its active members (today) can see every form and rating in IPCRS; the super-admin manages the
    // team by managing that committee.
    'pmt_committee' => env('IPCRS_PMT_COMMITTEE', 'PMT'),

];
