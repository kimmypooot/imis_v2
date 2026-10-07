<?php

use App\Http\Controllers\PrivacyNoticeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// No public landing page: the login screen is the front door, as in the
// legacy IMIS. Signed-in users go straight to their dashboard.
Route::get('/', fn (Request $request) => redirect()->route($request->user() ? 'dashboard' : 'login'))
    ->name('home');

// Admin-2: the privacy notice, open to everyone (linked from the sign-in page).
Route::get('privacy', PrivacyNoticeController::class)->name('privacy');

require __DIR__.'/auth.php';
require __DIR__.'/portal.php';
require __DIR__.'/admin.php';
