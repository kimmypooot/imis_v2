<?php

use App\Http\Controllers\Api\SsoValidateController;
use Illuminate\Support\Facades\Route;

// Server-to-server endpoints (stateless `api` middleware group: no
// session, no CSRF). Registered without a prefix so each path is exactly
// what its caller expects.

// Legacy subsystems redeem a launch sign-in token (LegacySso). Every call
// comes from the subsystems' own server, so the limit is per that IP.
Route::post(config('legacy.sso.validate_path'), SsoValidateController::class)
    ->middleware('throttle:300,1')
    ->name('sso.validate');
