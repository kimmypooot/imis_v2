<?php

use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\ApplicationAccessController;
use App\Http\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Admin\ApplicationRoleController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ChangelogController;
use App\Http\Controllers\Admin\CommitteeController;
use App\Http\Controllers\Admin\CommitteeMemberController;
use App\Http\Controllers\Admin\OrganizationalUnitController as AdminOrganizationalUnitController;
use App\Http\Controllers\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthorizationDemoController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSystemAccessController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Administration surface. `/admin` is the minimal landing page gated by
 * the `access-admin` Gate ability (any of the core admin permissions);
 * every other route is additionally protected by its controller's policy
 * check ($this->authorize()) — the "permission"/"can" middleware here is
 * a route-boundary guard, not the sole protection.
 */
Route::middleware('auth')->group(function () {
    Route::get('admin', [AdminController::class, 'index'])
        ->middleware('can:access-admin')
        ->name('admin.index');

    Route::get('authorization-demo', [AuthorizationDemoController::class, 'show'])
        ->name('authorization.demo');

    // Development-only component library reference — not a business page.
    Route::get('design-system', fn () => Inertia::render('Dev/DesignSystem'))
        ->name('design-system');

    Route::middleware('permission:roles.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    });

    Route::middleware('permission:roles.create')->group(function () {
        Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    });

    Route::middleware('permission:roles.update')->group(function () {
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });

    Route::middleware('permission:permissions.manage')->group(function () {
        Route::put('roles/{role}/permissions', [RoleController::class, 'assignPermissions'])
            ->name('roles.assign-permissions');
    });

    Route::middleware('permission:permissions.view')->group(function () {
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
    });

    Route::middleware('permission:users.view')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
    });

    Route::middleware('permission:users.create')->group(function () {
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
    });

    Route::middleware('permission:users.view')->group(function () {
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    });

    Route::middleware('permission:users.update')->group(function () {
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/roles', [UserController::class, 'assignRoles'])->name('users.assign-roles');
        Route::post('users/{user}/send-password-reset', [UserController::class, 'sendPasswordReset'])
            ->name('users.send-password-reset');
    });

    Route::middleware('permission:users.delete')->group(function () {
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.update-status');
    });

    Route::middleware('permission:organizational_units.view')->group(function () {
        Route::get('admin/organizational-units', [AdminOrganizationalUnitController::class, 'index'])
            ->name('admin.organizational-units.index');
    });

    Route::middleware('permission:organizational_units.create')->group(function () {
        Route::get('admin/organizational-units/create', [AdminOrganizationalUnitController::class, 'create'])
            ->name('admin.organizational-units.create');
        Route::post('admin/organizational-units', [AdminOrganizationalUnitController::class, 'store'])
            ->name('admin.organizational-units.store');
    });

    Route::middleware('permission:organizational_units.update')->group(function () {
        Route::get('admin/organizational-units/{organizationalUnit}/edit', [AdminOrganizationalUnitController::class, 'edit'])
            ->name('admin.organizational-units.edit');
        Route::put('admin/organizational-units/{organizationalUnit}', [AdminOrganizationalUnitController::class, 'update'])
            ->name('admin.organizational-units.update');
    });

    Route::middleware('permission:organizational_units.archive')->group(function () {
        Route::patch('admin/organizational-units/{organizationalUnit}/status', [AdminOrganizationalUnitController::class, 'updateStatus'])
            ->name('admin.organizational-units.update-status');
    });

    Route::middleware('permission:services.view')->group(function () {
        Route::get('admin/services', [AdminServiceController::class, 'index'])->name('admin.services.index');
    });

    Route::middleware('permission:services.create')->group(function () {
        Route::get('admin/services/create', [AdminServiceController::class, 'create'])->name('admin.services.create');
        Route::post('admin/services', [AdminServiceController::class, 'store'])->name('admin.services.store');
    });

    Route::middleware('permission:services.update')->group(function () {
        Route::get('admin/services/{service}/edit', [AdminServiceController::class, 'edit'])->name('admin.services.edit');
        Route::put('admin/services/{service}', [AdminServiceController::class, 'update'])->name('admin.services.update');
    });

    Route::middleware('permission:services.archive')->group(function () {
        Route::patch('admin/services/{service}/status', [AdminServiceController::class, 'updateStatus'])
            ->name('admin.services.update-status');
    });

    Route::middleware('permission:applications.view')->group(function () {
        Route::get('admin/applications', [AdminApplicationController::class, 'index'])->name('admin.applications.index');
    });

    Route::middleware('permission:applications.create')->group(function () {
        Route::get('admin/applications/create', [AdminApplicationController::class, 'create'])->name('admin.applications.create');
        Route::post('admin/applications', [AdminApplicationController::class, 'store'])->name('admin.applications.store');
    });

    Route::middleware('permission:applications.update')->group(function () {
        Route::get('admin/applications/{application}/edit', [AdminApplicationController::class, 'edit'])->name('admin.applications.edit');
        Route::put('admin/applications/{application}', [AdminApplicationController::class, 'update'])->name('admin.applications.update');
    });

    Route::middleware('permission:applications.archive')->group(function () {
        Route::patch('admin/applications/{application}/status', [AdminApplicationController::class, 'updateStatus'])
            ->name('admin.applications.update-status');
    });

    Route::middleware('permission:applications.maintenance')->group(function () {
        Route::patch('admin/applications/{application}/maintenance', [AdminApplicationController::class, 'updateMaintenance'])
            ->name('admin.applications.update-maintenance');
    });

    // Per-system roles and who holds them. Defining a system's roles is
    // part of editing it; granting them to people is `manage_access`.
    Route::middleware('permission:applications.view')->group(function () {
        Route::get('admin/applications/{application}/access', [ApplicationAccessController::class, 'show'])
            ->name('admin.applications.access');
    });

    Route::middleware('permission:applications.update')->group(function () {
        Route::post('admin/applications/{application}/roles', [ApplicationRoleController::class, 'store'])
            ->name('admin.applications.roles.store');
        Route::put('admin/applications/{application}/roles/{applicationRole}', [ApplicationRoleController::class, 'update'])
            ->name('admin.applications.roles.update');
        Route::patch('admin/applications/{application}/roles/{applicationRole}/status', [ApplicationRoleController::class, 'updateStatus'])
            ->name('admin.applications.roles.update-status');
    });

    Route::middleware('permission:applications.manage_access')->group(function () {
        Route::post('admin/applications/{application}/access', [ApplicationAccessController::class, 'store'])
            ->name('admin.applications.access.store');
        Route::put('admin/applications/{application}/access/{access}', [ApplicationAccessController::class, 'update'])
            ->name('admin.applications.access.update');
        Route::delete('admin/applications/{application}/access/{access}', [ApplicationAccessController::class, 'destroy'])
            ->name('admin.applications.access.destroy');
        Route::put('users/{user}/system-access', [UserSystemAccessController::class, 'update'])
            ->name('users.system-access.update');
    });

    Route::middleware('permission:committees.view')->group(function () {
        Route::get('admin/committees', [CommitteeController::class, 'index'])->name('admin.committees.index');
    });

    Route::middleware('permission:committees.create')->group(function () {
        Route::get('admin/committees/create', [CommitteeController::class, 'create'])->name('admin.committees.create');
        Route::post('admin/committees', [CommitteeController::class, 'store'])->name('admin.committees.store');
    });

    Route::middleware('permission:committees.view')->group(function () {
        Route::get('admin/committees/{committee}', [CommitteeController::class, 'show'])->name('admin.committees.show');
    });

    Route::middleware('permission:committees.update')->group(function () {
        Route::get('admin/committees/{committee}/edit', [CommitteeController::class, 'edit'])->name('admin.committees.edit');
        Route::put('admin/committees/{committee}', [CommitteeController::class, 'update'])->name('admin.committees.update');
        Route::post('admin/committees/{committee}/members', [CommitteeMemberController::class, 'store'])
            ->name('admin.committees.members.store');
        Route::put('admin/committees/{committee}/members/{member}', [CommitteeMemberController::class, 'update'])
            ->name('admin.committees.members.update');
        Route::patch('admin/committees/{committee}/members/{member}/status', [CommitteeMemberController::class, 'updateStatus'])
            ->name('admin.committees.members.update-status');
    });

    Route::middleware('permission:committees.archive')->group(function () {
        Route::patch('admin/committees/{committee}/status', [CommitteeController::class, 'updateStatus'])
            ->name('admin.committees.update-status');
    });

    Route::middleware('permission:announcements.view')->group(function () {
        Route::get('admin/announcements', [AdminAnnouncementController::class, 'index'])->name('admin.announcements.index');
    });

    Route::middleware('permission:announcements.create')->group(function () {
        Route::get('admin/announcements/create', [AdminAnnouncementController::class, 'create'])->name('admin.announcements.create');
        Route::post('admin/announcements', [AdminAnnouncementController::class, 'store'])->name('admin.announcements.store');
    });

    Route::middleware('permission:announcements.view')->group(function () {
        Route::get('admin/announcements/{announcement}', [AdminAnnouncementController::class, 'show'])->name('admin.announcements.show');
    });

    Route::middleware('permission:announcements.update')->group(function () {
        Route::get('admin/announcements/{announcement}/edit', [AdminAnnouncementController::class, 'edit'])->name('admin.announcements.edit');
        Route::put('admin/announcements/{announcement}', [AdminAnnouncementController::class, 'update'])->name('admin.announcements.update');
        Route::patch('admin/announcements/{announcement}/featured', [AdminAnnouncementController::class, 'updateFeatured'])
            ->name('admin.announcements.update-featured');
    });

    // Publish/unpublish need `announcements.publish`, archive/restore need
    // `announcements.archive` — UpdateAnnouncementStatusRequest picks the
    // exact ability per transition; this guard only admits either.
    Route::middleware('permission:announcements.publish,announcements.archive')->group(function () {
        Route::patch('admin/announcements/{announcement}/status', [AdminAnnouncementController::class, 'updateStatus'])
            ->name('admin.announcements.update-status');
    });

    // Read-only: GET routes only. Audit entries are never edited or
    // deleted through the application, by anyone.
    Route::middleware('permission:audit_logs.view')->group(function () {
        Route::get('admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
        Route::get('admin/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('admin.audit-logs.show');
    });

    Route::middleware('permission:settings.view')->group(function () {
        Route::get('admin/settings', [SettingController::class, 'index'])->name('admin.settings.index');
    });

    Route::middleware('permission:changelogs.view')->group(function () {
        Route::get('admin/changelogs', [ChangelogController::class, 'index'])->name('admin.changelogs.index');
    });

    Route::middleware('permission:changelogs.manage')->group(function () {
        Route::get('admin/changelogs/create', [ChangelogController::class, 'create'])->name('admin.changelogs.create');
        Route::post('admin/changelogs', [ChangelogController::class, 'store'])->name('admin.changelogs.store');
        Route::get('admin/changelogs/{changelog}/edit', [ChangelogController::class, 'edit'])->name('admin.changelogs.edit');
        Route::put('admin/changelogs/{changelog}', [ChangelogController::class, 'update'])->name('admin.changelogs.update');
    });

    Route::middleware('permission:changelogs.publish')->group(function () {
        Route::patch('admin/changelogs/{changelog}/status', [ChangelogController::class, 'updateStatus'])
            ->name('admin.changelogs.update-status');
    });

    // `{group}` is bound to App\Enums\SettingGroup — an unknown group is a
    // 404. Protected settings are refused by UpdateSettingsRequest.
    Route::middleware('permission:settings.update')->group(function () {
        Route::put('admin/settings/{group}', [SettingController::class, 'update'])->name('admin.settings.update');
    });
});
