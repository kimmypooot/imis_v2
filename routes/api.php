<?php

use App\Http\Controllers\Api\EttsController;
use App\Http\Controllers\Api\IctSrtsController;
use App\Http\Controllers\Api\IpcrsController;
use App\Http\Controllers\Api\JportalController;
use App\Http\Controllers\Api\ProctadController;
use App\Http\Controllers\Api\RecruitmentController;
use App\Http\Controllers\Api\RecruitmentEmployeeController;
use App\Http\Controllers\Api\SsoValidateController;
use App\Http\Middleware\AuthenticateEtts;
use App\Http\Middleware\AuthenticateIctSrts;
use App\Http\Middleware\AuthenticateIpcrs;
use App\Http\Middleware\AuthenticateJportal;
use App\Http\Middleware\AuthenticateProctad;
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
    // The staff who hold an Admin or HRMPSB role here, for the portal's Users page.
    Route::get('users', [RecruitmentController::class, 'users'])->name('recruitment.users');
    Route::post('items/{item}/selection', [RecruitmentController::class, 'selection'])->name('recruitment.selection');
    // An employee who applies: their verified PDS and the few 201-file documents an application uses.
    Route::get('employees/{user}/pds', [RecruitmentEmployeeController::class, 'pds'])->name('recruitment.employee.pds');
    Route::get('employees/{user}/documents', [RecruitmentEmployeeController::class, 'documents'])->name('recruitment.employee.documents');
    Route::get('employees/{user}/documents/{document}/file', [RecruitmentEmployeeController::class, 'document'])
        ->whereNumber('document')->name('recruitment.employee.document');
});

// JPortal's public office directory (portal.cscro8.com/directory).
Route::middleware([AuthenticateJportal::class, 'throttle:120,1'])->prefix('api/v1/jportal')->group(function () {
    Route::get('directory', [JportalController::class, 'directory'])->name('jportal.directory');
});

// ICT SRTS: the current employees, for its ICT Group and form signatories.
Route::middleware([AuthenticateIctSrts::class, 'throttle:60,1'])->prefix('api/v1/ictsrts')->group(function () {
    Route::get('employees', [IctSrtsController::class, 'employees'])->name('ictsrts.employees');
});

// ETTS: the current employees, to suggest as a routing slip addressee or signatory.
Route::middleware([AuthenticateEtts::class, 'throttle:60,1'])->prefix('api/v1/etts')->group(function () {
    Route::get('employees', [EttsController::class, 'employees'])->name('etts.employees');
});

// IPCRS: the current employees, to name on a form and cascade rows to.
Route::middleware([AuthenticateIpcrs::class, 'throttle:60,1'])->prefix('api/v1/ipcrs')->group(function () {
    Route::get('employees', [IpcrsController::class, 'employees'])->name('ipcrs.employees');
});

// PROCTAD: the staff who hold a PROCTAD role, for its Users page.
Route::middleware([AuthenticateProctad::class, 'throttle:60,1'])->prefix('api/v1/proctad')->group(function () {
    Route::get('users', [ProctadController::class, 'users'])->name('proctad.users');
});
