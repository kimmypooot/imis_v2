<?php

use App\Http\Controllers\Api\IctSrtsController;
use App\Http\Controllers\Api\IpcrsController;
use App\Http\Controllers\Api\JportalController;
use App\Http\Controllers\Api\RecruitmentController;
use App\Http\Controllers\Api\SsoValidateController;
use App\Http\Middleware\AuthenticateIctSrts;
use App\Http\Middleware\AuthenticateIpcrs;
use App\Http\Middleware\AuthenticateJportal;
use App\Http\Middleware\AuthenticateRecruitmentPortal;
use Illuminate\Support\Facades\Route;

// Server-to-server endpoints (stateless `api` middleware group: no
// session, no CSRF). Registered without a prefix so each path is exactly
// what its caller expects.

// Legacy subsystems redeem a launch sign-in token (LegacySso). Every call
// comes from the subsystems' own server, so the limit is per that IP.
Route::post(config('legacy.sso.validate_path'), SsoValidateController::class)
    ->middleware('throttle:300,1')
    ->name('sso.validate');

// The Recruitment Portal: vacant plantilla items out, selections in.
Route::middleware([AuthenticateRecruitmentPortal::class, 'throttle:120,1'])->prefix('api/v1/recruitment')->group(function () {
    Route::get('vacant-positions', [RecruitmentController::class, 'vacantPositions'])->name('recruitment.vacant');
    Route::post('items/{item}/selection', [RecruitmentController::class, 'selection'])->name('recruitment.selection');
});

// JPortal's public office directory (portal.cscro8.com/directory).
Route::middleware([AuthenticateJportal::class, 'throttle:120,1'])->prefix('api/v1/jportal')->group(function () {
    Route::get('directory', [JportalController::class, 'directory'])->name('jportal.directory');
});

// ICT SRTS: the current employees, for its ICT Group and form signatories.
Route::middleware([AuthenticateIctSrts::class, 'throttle:60,1'])->prefix('api/v1/ictsrts')->group(function () {
    Route::get('employees', [IctSrtsController::class, 'employees'])->name('ictsrts.employees');
});

// IPCRS: the current employees, to name on a form and cascade rows to.
Route::middleware([AuthenticateIpcrs::class, 'throttle:60,1'])->prefix('api/v1/ipcrs')->group(function () {
    Route::get('employees', [IpcrsController::class, 'employees'])->name('ipcrs.employees');
});
