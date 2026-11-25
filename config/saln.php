<?php

return [
    /*
    | Entry and exit SALNs are only tracked for assumptions and separations
    | on or after this date; earlier ones were filed on paper.
    */
    'go_live' => env('SALN_GO_LIVE', '2026-12-01'),
];
