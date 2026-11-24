<?php

use App\Http\Controllers\DtrVerificationController;
use App\Http\Controllers\PrivacyNoticeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public landing page: the login screen is the front door, as in the
// legacy IMIS. Signed-in users go straight to their dashboard.
Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))
    ->name('home');

// Admin-2: the privacy notice, open to everyone (linked from the sign-in page).
Route::get('privacy', PrivacyNoticeController::class)->name('privacy');

// DTR: what a printed DTR's QR code opens. Open to everyone (it shows only a
// status, the name, the period and the signing time) and rate-limited.
Route::get('dtr/verify/{code}', DtrVerificationController::class)->middleware('throttle:30,1')->name('dtr.verify');

require __DIR__.'/auth.php';
require __DIR__.'/portal.php';
require __DIR__.'/admin.php';
