<?php

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\OrganizationalUnitController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalSearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\WhatsNewController;
use Illuminate\Support\Facades\Route;

/*
 * The authenticated IMIS Portal — the central platform for discovering
 * applications, services, and organizational units. Guests are sent to
 * /login by the `auth` middleware; authenticated users hitting `/` are
 * redirected here (see routes/web.php).
 */
Route::middleware('auth')->group(function () {
    Route::get('dashboard', [PortalController::class, 'index'])->name('dashboard');

    Route::get('search', [PortalSearchController::class, 'index'])->name('search');

    Route::get('whats-new', [WhatsNewController::class, 'index'])->name('whats-new');
    Route::post('whats-new/seen', [WhatsNewController::class, 'seen'])->name('whats-new.seen');

    // Self-service account actions (the legacy profile menu).
    Route::get('account/password', [PasswordController::class, 'edit'])->name('account.password.edit');
    Route::put('account/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('account.password.update');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('announcements/{announcement:slug}', [AnnouncementController::class, 'show'])->name('announcements.show');

    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');

    Route::get('organizational-units', [OrganizationalUnitController::class, 'index'])->name('organizational-units.index');
    Route::get('organizational-units/{organizationalUnit}', [OrganizationalUnitController::class, 'show'])->name('organizational-units.show');
});
