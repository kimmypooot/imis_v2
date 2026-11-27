<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AgeAndServiceController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\ApplicationAccessController;
use App\Http\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Admin\ApplicationRoleController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\BiometricImportController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\ChangelogController;
use App\Http\Controllers\Admin\CommitteeController;
use App\Http\Controllers\Admin\CommitteeMemberController;
use App\Http\Controllers\Admin\DashboardLayoutController;
use App\Http\Controllers\Admin\DataCorrectionController;
use App\Http\Controllers\Admin\DtrManagementController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\EmployeeAssignmentController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeDocumentController;
use App\Http\Controllers\Admin\EmployeeDtrController;
use App\Http\Controllers\Admin\EmployeeLearningDevelopmentController;
use App\Http\Controllers\Admin\EmployeeLeaveController;
use App\Http\Controllers\Admin\EmployeePdsController;
use App\Http\Controllers\Admin\EmployeePdsImportController;
use App\Http\Controllers\Admin\EmployeePunchController;
use App\Http\Controllers\Admin\EmployeeServiceRecordController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\FlexibleWorkController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\HrDashboardController;
use App\Http\Controllers\Admin\HrReadinessController;
use App\Http\Controllers\Admin\LdReportController;
use App\Http\Controllers\Admin\LdReviewController;
use App\Http\Controllers\Admin\LearningDevelopmentController;
use App\Http\Controllers\Admin\LeaveApplicationController as AdminLeaveApplicationController;
use App\Http\Controllers\Admin\LeaveCalendarController;
use App\Http\Controllers\Admin\LeaveCardSyncController;
use App\Http\Controllers\Admin\LeaveCreditController;
use App\Http\Controllers\Admin\LeaveCreditsPurgeController;
use App\Http\Controllers\Admin\LeaveDesignationController;
use App\Http\Controllers\Admin\LeaveMonetizationController;
use App\Http\Controllers\Admin\LeaveReportController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\NonPlantillaController;
use App\Http\Controllers\Admin\OfficeDtrExportController;
use App\Http\Controllers\Admin\OrganizationalUnitController as AdminOrganizationalUnitController;
use App\Http\Controllers\Admin\PayComponentController;
use App\Http\Controllers\Admin\PayProfileController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\PayrollMonitoringController;
use App\Http\Controllers\Admin\PayrollReconciliationController;
use App\Http\Controllers\Admin\PdsVerificationController;
use App\Http\Controllers\Admin\PlantillaAppointmentController;
use App\Http\Controllers\Admin\PlantillaController;
use App\Http\Controllers\Admin\PlantillaSheetController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\ReassignmentReportController;
use App\Http\Controllers\Admin\SalaryNoticeController;
use App\Http\Controllers\Admin\SalaryScheduleController;
use App\Http\Controllers\Admin\SalnController;
use App\Http\Controllers\Admin\ScheduledTaskController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StaffingController;
use App\Http\Controllers\Admin\SystemAccessController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\TardinessController;
use App\Http\Controllers\Admin\TestDataResetController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Admin\WorkScheduleController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthorizationDemoController;
use App\Http\Controllers\PdsPhotoController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserImportController;
use App\Http\Controllers\UserSystemAccessController;
use App\Support\Branding\Branding;
use App\Support\HR\Pds\PdsSchema;
use App\Support\Mail\EmailTemplates;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Administration surface. `/admin` is the system administration landing
 * page, gated by the `access-admin` Gate ability (any of the core admin
 * permissions); `/admin/hr` is Human Resources' own, gated by `access-hr`;
 * every other route is additionally protected by its controller's policy
 * check ($this->authorize()) — the "permission"/"can" middleware here is
 * a route-boundary guard, not the sole protection.
 */
Route::middleware('auth')->group(function () {
    Route::get('admin', [AdminController::class, 'index'])
        ->middleware('can:access-admin')
        ->name('admin.index');

    // The Human Resources area's own landing page (any HR permission).
    Route::get('admin/hr', [HrDashboardController::class, 'index'])
        ->middleware('can:access-hr')
        ->name('admin.hr.index');

    // Developer tools, registered only when running locally (APP_ENV=local),
    // so they don't exist (404) on staging or the live server.
    if (app()->environment('local')) {
        Route::get('authorization-demo', [AuthorizationDemoController::class, 'show'])
            ->name('authorization.demo');

        // Component library reference — not a business page.
        Route::get('design-system', fn () => Inertia::render('Dev/DesignSystem'))
            ->name('design-system');
    }

    Route::middleware('permission:roles.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    });

    Route::middleware('permission:roles.create')->group(function () {
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    });

    Route::middleware('permission:roles.update')->group(function () {
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
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        // Admin-3: bulk import from a CSV (preview first, then create).
        Route::get('users/import', [UserImportController::class, 'create'])->name('users.import');
        Route::get('users/import/template', [UserImportController::class, 'template'])->name('users.import.template');
        Route::post('users/import/preview', [UserImportController::class, 'preview'])->middleware('throttle:20,1')->name('users.import.preview');
        Route::post('users/import', [UserImportController::class, 'store'])->name('users.import.store');
    });

    Route::middleware('permission:users.view')->group(function () {
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    });

    Route::middleware('permission:users.update')->group(function () {
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/photo', [UserController::class, 'updatePhoto'])->name('users.photo.update');
        Route::delete('users/{user}/photo', [UserController::class, 'destroyPhoto'])->name('users.photo.destroy');
        Route::put('users/{user}/roles', [UserController::class, 'assignRoles'])->name('users.assign-roles');
        Route::post('users/{user}/send-password-reset', [UserController::class, 'sendPasswordReset'])
            ->name('users.send-password-reset');
        // A lost or stolen phone: removes every fingerprint / face sign-in device.
        Route::delete('users/{user}/passkeys', [UserController::class, 'resetPasskeys'])->name('users.reset-passkeys');
        // Super Administrators only (UserPolicy::setPassword).
        Route::put('users/{user}/password', [UserController::class, 'setPassword'])->name('users.set-password');
    });

    Route::middleware('permission:users.delete')->group(function () {
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.update-status');
        Route::post('users/bulk-status', [UserController::class, 'bulkStatus'])->name('users.bulk-status');
    });

    Route::middleware('permission:organizational_units.view')->group(function () {
        Route::get('admin/organizational-units', [AdminOrganizationalUnitController::class, 'index'])
            ->name('admin.organizational-units.index');
    });

    Route::middleware('permission:organizational_units.create')->group(function () {
        Route::post('admin/organizational-units', [AdminOrganizationalUnitController::class, 'store'])
            ->name('admin.organizational-units.store');
    });

    Route::middleware('permission:organizational_units.update')->group(function () {
        Route::put('admin/organizational-units/{organizationalUnit}', [AdminOrganizationalUnitController::class, 'update'])
            ->name('admin.organizational-units.update');
    });

    Route::middleware('permission:organizational_units.archive')->group(function () {
        Route::patch('admin/organizational-units/{organizationalUnit}/status', [AdminOrganizationalUnitController::class, 'updateStatus'])
            ->name('admin.organizational-units.update-status');
    });

    Route::middleware('permission:services.view')->group(function () {
        Route::get('admin/services', [AdminServiceController::class, 'index'])->name('admin.services.index');
        Route::get('admin/service-categories', [ServiceCategoryController::class, 'index'])->name('admin.service-categories.index');
    });

    Route::middleware('permission:services.create')->group(function () {
        Route::post('admin/services', [AdminServiceController::class, 'store'])->name('admin.services.store');
        Route::post('admin/service-categories', [ServiceCategoryController::class, 'store'])->name('admin.service-categories.store');
    });

    Route::middleware('permission:services.update')->group(function () {
        Route::put('admin/services/{service}', [AdminServiceController::class, 'update'])->name('admin.services.update');
        Route::put('admin/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update'])->name('admin.service-categories.update');
    });

    Route::middleware('permission:services.archive')->group(function () {
        Route::patch('admin/services/{service}/status', [AdminServiceController::class, 'updateStatus'])
            ->name('admin.services.update-status');
        Route::patch('admin/service-categories/{serviceCategory}/status', [ServiceCategoryController::class, 'updateStatus'])
            ->name('admin.service-categories.update-status');
    });

    Route::middleware('permission:applications.view')->group(function () {
        Route::get('admin/applications', [AdminApplicationController::class, 'index'])->name('admin.applications.index');
    });

    Route::middleware('permission:applications.create')->group(function () {
        Route::post('admin/applications', [AdminApplicationController::class, 'store'])->name('admin.applications.store');
    });

    Route::middleware('permission:applications.update')->group(function () {
        Route::put('admin/applications/{application}', [AdminApplicationController::class, 'update'])->name('admin.applications.update');
    });

    Route::middleware('permission:applications.archive')->group(function () {
        Route::patch('admin/applications/{application}/status', [AdminApplicationController::class, 'updateStatus'])
            ->name('admin.applications.update-status');
    });

    Route::middleware('permission:applications.delete')->group(function () {
        Route::delete('admin/applications/{application}', [AdminApplicationController::class, 'destroy'])
            ->name('admin.applications.destroy');
    });

    Route::middleware('permission:applications.maintenance')->group(function () {
        Route::patch('admin/applications/{application}/maintenance', [AdminApplicationController::class, 'updateMaintenance'])
            ->name('admin.applications.update-maintenance');
    });

    // Per-system roles and who holds them. Defining a system's roles is
    // part of editing it; granting them to people is `manage_access`.
    Route::middleware('permission:applications.update')->group(function () {
        Route::post('admin/applications/{application}/roles', [ApplicationRoleController::class, 'store'])
            ->name('admin.applications.roles.store');
        Route::put('admin/applications/{application}/roles/{applicationRole}', [ApplicationRoleController::class, 'update'])
            ->name('admin.applications.roles.update');
        Route::patch('admin/applications/{application}/roles/{applicationRole}/status', [ApplicationRoleController::class, 'updateStatus'])
            ->name('admin.applications.roles.update-status');
    });

    // Legacy "Manage Dashboard": which systems appear under each division.
    Route::middleware('permission:applications.update')->group(function () {
        Route::get('admin/dashboard-layout', [DashboardLayoutController::class, 'index'])->name('admin.dashboard-layout.index');
        Route::put('admin/dashboard-layout/{type}/{id}', [DashboardLayoutController::class, 'update'])
            ->whereIn('type', ['unit', 'committee'])
            ->whereNumber('id')
            ->name('admin.dashboard-layout.update');
    });

    // Legacy "Roles and Permissions" (per system). An office access manager
    // reaches it too, for the systems their own office manages only.
    Route::middleware('permission:applications.manage_access,applications.manage_office_access')->group(function () {
        Route::get('admin/system-access', [SystemAccessController::class, 'index'])->name('admin.system-access.index');
        Route::put('admin/system-access/{application}', [SystemAccessController::class, 'update'])->name('admin.system-access.update');
    });

    Route::middleware('permission:applications.manage_access')->group(function () {
        // Legacy "User Access" (per user): every system at once.
        Route::get('admin/user-access', [UserAccessController::class, 'index'])->name('admin.user-access.index');
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
        Route::post('admin/committees', [CommitteeController::class, 'store'])->name('admin.committees.store');
    });

    Route::middleware('permission:committees.view')->group(function () {
        Route::get('admin/committees/{committee}', [CommitteeController::class, 'show'])->name('admin.committees.show');
    });

    Route::middleware('permission:committees.update')->group(function () {
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

    // HR-1 employee records. Viewing takes either permission (supervisors
    // hold only `view_unit`); EmployeePolicy narrows which records.
    Route::middleware('permission:employees.view,employees.view_unit')->group(function () {
        Route::get('admin/hr/employees', [EmployeeController::class, 'index'])->name('admin.hr.employees.index');
        Route::get('admin/hr/employees/{employee}', [EmployeeController::class, 'show'])->name('admin.hr.employees.show');
    });

    Route::middleware('permission:employees.create')->group(function () {
        Route::post('admin/hr/employees', [EmployeeController::class, 'store'])->name('admin.hr.employees.store');
        Route::post('admin/hr/employees/bulk', [EmployeeController::class, 'storeMany'])->name('admin.hr.employees.store-many');
    });

    Route::middleware('permission:employees.update')->group(function () {
        Route::put('admin/hr/employees/{employee}', [EmployeeController::class, 'update'])->name('admin.hr.employees.update');
        Route::put('admin/hr/employees/{employee}/personal', [EmployeeController::class, 'updatePersonal'])->name('admin.hr.employees.update-personal');
        Route::post('admin/hr/employees/{employee}/photo', [EmployeeController::class, 'updatePhoto'])->name('admin.hr.employees.photo.update');
        Route::delete('admin/hr/employees/{employee}/photo', [EmployeeController::class, 'destroyPhoto'])->name('admin.hr.employees.photo.destroy');
    });

    // HR-1b: reassignment history. Who was where on a date, and movements
    // (supervisors see their office's people only); recording is HR's.
    // Scoped bindings: an entry only resolves under its own employee.
    Route::middleware('permission:employees.view,employees.view_unit')->group(function () {
        Route::get('admin/hr/reassignments', [ReassignmentReportController::class, 'index'])->name('admin.hr.reassignments');
    });

    // Age & Service: the whole roster behind the dashboard's birthday,
    // retirement and loyalty lists (HR and top management only).
    Route::middleware('permission:employees.view')->group(function () {
        Route::get('admin/hr/age-and-service', [AgeAndServiceController::class, 'index'])->name('admin.hr.age-and-service');
    });

    // HR-5: someone's Service Record, on screen and printable (HR only;
    // EmployeePolicy::viewServiceRecord).
    Route::middleware('permission:service_records.view')->group(function () {
        Route::get('admin/hr/employees/{employee}/service-record', [EmployeeServiceRecordController::class, 'show'])->name('admin.hr.employees.service-record');
        Route::get('admin/hr/employees/{employee}/service-record/print', [EmployeeServiceRecordController::class, 'print'])->name('admin.hr.employees.service-record.print');
    });
    // HR-5: service in other agencies, entered by hand (EmployeePolicy::manageServiceRecord).
    Route::middleware('permission:service_records.manage')->scopeBindings()->group(function () {
        Route::post('admin/hr/employees/{employee}/service-record/entries', [EmployeeServiceRecordController::class, 'store'])->name('admin.hr.employees.service-record.entries.store');
        Route::put('admin/hr/employees/{employee}/service-record/entries/{serviceRecordEntry}', [EmployeeServiceRecordController::class, 'update'])->name('admin.hr.employees.service-record.entries.update');
        Route::delete('admin/hr/employees/{employee}/service-record/entries/{serviceRecordEntry}', [EmployeeServiceRecordController::class, 'destroy'])->name('admin.hr.employees.service-record.entries.destroy');
    });

    // HR-6a: leave — types, holidays, everyone's credits, one person's
    // leave card. `leave.manage` also reads; EmployeePolicy keeps HR off
    // their own card. Scoped bindings: an entry only resolves under its
    // own employee.
    Route::middleware('permission:leave.view,leave.manage')->group(function () {
        Route::get('admin/hr/leave', [LeaveCreditController::class, 'index'])->name('admin.hr.leave.index');
        Route::get('admin/hr/leave/types', [LeaveTypeController::class, 'index'])->name('admin.hr.leave.types');
        Route::get('admin/hr/leave/holidays', [HolidayController::class, 'index'])->name('admin.hr.leave.holidays');
        // HR-6b: every application, and officers-in-charge for the Head.
        Route::get('admin/hr/leave/applications', [AdminLeaveApplicationController::class, 'index'])->name('admin.hr.leave.applications');
        Route::get('admin/hr/leave/designations', [LeaveDesignationController::class, 'index'])->name('admin.hr.leave.designations');
        // HR-6c: monetization / terminal leave, flexible work, reports.
        Route::get('admin/hr/leave/monetization', [LeaveMonetizationController::class, 'index'])->name('admin.hr.leave.monetization');
        Route::get('admin/hr/leave/flexible-work', [FlexibleWorkController::class, 'index'])->name('admin.hr.leave.fwa');
        Route::get('admin/hr/leave/reports', [LeaveReportController::class, 'index'])->name('admin.hr.leave.reports');
        Route::get('admin/hr/leave/calendar', [LeaveCalendarController::class, 'index'])->name('admin.hr.leave.calendar');
        // DTR: a month's tardiness and undertime, charged to leave credits (CSC Table IV).
        Route::get('admin/hr/leave/tardiness', [TardinessController::class, 'index'])->name('admin.hr.leave.tardiness');
        Route::get('admin/hr/leave/calendar/{application}', [LeaveCalendarController::class, 'show'])->name('admin.hr.leave.calendar.show');
        Route::get('admin/hr/employees/{employee}/leave', [EmployeeLeaveController::class, 'show'])->name('admin.hr.employees.leave');
    });

    // DTR: biometric logs uploaded by HRD. `dtr.manage` also reads.
    Route::middleware('permission:dtr.view,dtr.manage')->group(function () {
        Route::get('admin/hr/dtr', [DtrManagementController::class, 'index'])->name('admin.hr.dtr.index');
        Route::get('admin/hr/dtr/import', [BiometricImportController::class, 'show'])->name('admin.hr.dtr.import');
        Route::get('admin/hr/dtr/schedules', [WorkScheduleController::class, 'show'])->name('admin.hr.dtr.schedules');
        Route::get('admin/hr/employees/{employee}/dtr', [EmployeeDtrController::class, 'show'])->name('admin.hr.employees.dtr');
        Route::get('admin/hr/employees/{employee}/dtr/punches', [EmployeePunchController::class, 'index'])->name('admin.hr.employees.dtr.punches');
        Route::get('admin/hr/employees/{employee}/dtr/print', [EmployeeDtrController::class, 'print'])->name('admin.hr.employees.dtr.print');
        Route::get('admin/hr/employees/{employee}/dtr/export', [EmployeeDtrController::class, 'export'])->middleware('throttle:30,1')->name('admin.hr.employees.dtr.export');
        Route::get('admin/hr/dtr/export', OfficeDtrExportController::class)->middleware('throttle:6,1')->name('admin.hr.dtr.export');
    });

    Route::middleware('permission:dtr.manage')->group(function () {
        Route::post('admin/hr/dtr/import', [BiometricImportController::class, 'store'])->name('admin.hr.dtr.import.store');
        Route::delete('admin/hr/dtr/import/ids', [BiometricImportController::class, 'clearIds'])->name('admin.hr.dtr.import.clear-ids');
        Route::post('admin/hr/dtr/import/preview', [BiometricImportController::class, 'preview'])->middleware('throttle:20,1')->name('admin.hr.dtr.import.preview');
        Route::post('admin/hr/employees/{employee}/dtr/adjustments', [EmployeeDtrController::class, 'store'])->name('admin.hr.employees.dtr.adjustments.store');
        Route::delete('admin/hr/employees/{employee}/dtr/adjustments/{adjustment}', [EmployeeDtrController::class, 'destroy'])->name('admin.hr.employees.dtr.adjustments.destroy');
        Route::post('admin/hr/employees/{employee}/dtr/punches', [EmployeePunchController::class, 'store'])->middleware('throttle:60,1')->name('admin.hr.employees.dtr.punches.store');
        Route::patch('admin/hr/employees/{employee}/dtr/punches/{punch}/void', [EmployeePunchController::class, 'void'])->name('admin.hr.employees.dtr.punches.void');
        Route::patch('admin/hr/employees/{employee}/dtr/punches/{punch}/restore', [EmployeePunchController::class, 'restore'])->name('admin.hr.employees.dtr.punches.restore');
        Route::post('admin/hr/dtr/schedules', [WorkScheduleController::class, 'store'])->name('admin.hr.dtr.schedules.store');
        Route::post('admin/hr/dtr/schedules/assign', [WorkScheduleController::class, 'assign'])->name('admin.hr.dtr.schedules.assign');
        Route::post('admin/hr/dtr/schedules/assign-many', [WorkScheduleController::class, 'assignMany'])->middleware('throttle:20,1')->name('admin.hr.dtr.schedules.assign-many');
        Route::put('admin/hr/dtr/schedules/{schedule}', [WorkScheduleController::class, 'update'])->name('admin.hr.dtr.schedules.update');
        Route::patch('admin/hr/dtr/schedules/assignments/{assignment}', [WorkScheduleController::class, 'end'])->name('admin.hr.dtr.schedules.assignments.end');
        Route::post('admin/hr/dtr/schedules/assignments/end-many', [WorkScheduleController::class, 'endMany'])->middleware('throttle:20,1')->name('admin.hr.dtr.schedules.assignments.end-many');
        Route::post('admin/hr/dtr/schedules/assignments/remove-many', [WorkScheduleController::class, 'destroyMany'])->middleware('throttle:20,1')->name('admin.hr.dtr.schedules.assignments.remove-many');
        Route::delete('admin/hr/dtr/schedules/assignments/{assignment}', [WorkScheduleController::class, 'destroy'])->name('admin.hr.dtr.schedules.assignments.destroy');
    });

    Route::middleware('permission:leave.manage')->scopeBindings()->group(function () {
        // HR-7: what is left before the legacy LMS and ldms are retired.
        Route::get('admin/hr/readiness', [HrReadinessController::class, 'index'])->name('admin.hr.readiness');
        Route::post('admin/hr/leave/tardiness', [TardinessController::class, 'store'])->name('admin.hr.leave.tardiness.store');
        Route::post('admin/hr/leave/tardiness/reviewed', [TardinessController::class, 'storeReviewed'])->middleware('throttle:6,1')->name('admin.hr.leave.tardiness.reviewed');
        Route::post('admin/hr/leave/tardiness/sync', [TardinessController::class, 'sync'])->middleware('throttle:6,1')->name('admin.hr.leave.tardiness.sync');
        Route::post('admin/hr/leave/tardiness/verify', [TardinessController::class, 'verify'])->middleware('throttle:30,1')->name('admin.hr.leave.tardiness.verify');
        Route::post('admin/hr/leave/tardiness/{deduction}/reverse', [TardinessController::class, 'reverse'])->name('admin.hr.leave.tardiness.reverse');
        Route::post('admin/hr/leave/accrual', [LeaveCreditController::class, 'accrue'])->name('admin.hr.leave.accrue');
        Route::post('admin/hr/leave/year-start', [LeaveCreditController::class, 'startYear'])->name('admin.hr.leave.year-start');
        // Syncing with HR's leave card workbook (the master file): upload, review, apply.
        Route::get('admin/hr/leave/sync', [LeaveCardSyncController::class, 'show'])->name('admin.hr.leave.sync');
        Route::post('admin/hr/leave/sync', [LeaveCardSyncController::class, 'upload'])->name('admin.hr.leave.sync.upload');
        Route::post('admin/hr/leave/sync/apply', [LeaveCardSyncController::class, 'apply'])->name('admin.hr.leave.sync.apply');
        Route::delete('admin/hr/leave/sync', [LeaveCardSyncController::class, 'discard'])->name('admin.hr.leave.sync.discard');
        Route::post('admin/hr/leave/types', [LeaveTypeController::class, 'store'])->name('admin.hr.leave.types.store');
        Route::put('admin/hr/leave/types/{type}', [LeaveTypeController::class, 'update'])->name('admin.hr.leave.types.update');
        Route::post('admin/hr/leave/holidays', [HolidayController::class, 'store'])->name('admin.hr.leave.holidays.store');
        Route::put('admin/hr/leave/holidays/{holiday}', [HolidayController::class, 'update'])->name('admin.hr.leave.holidays.update');
        Route::delete('admin/hr/leave/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('admin.hr.leave.holidays.destroy');
        Route::post('admin/hr/leave/designations', [LeaveDesignationController::class, 'store'])->name('admin.hr.leave.designations.store');
        Route::put('admin/hr/leave/designations/{designation}', [LeaveDesignationController::class, 'update'])->name('admin.hr.leave.designations.update');
        Route::delete('admin/hr/leave/designations/{designation}', [LeaveDesignationController::class, 'destroy'])->name('admin.hr.leave.designations.destroy');
        Route::post('admin/hr/leave/monetization/terminal', [LeaveMonetizationController::class, 'terminal'])->name('admin.hr.leave.monetization.terminal');
        Route::post('admin/hr/leave/monetization/{monetization}/decide', [LeaveMonetizationController::class, 'decide'])->name('admin.hr.leave.monetization.decide');
        Route::post('admin/hr/leave/monetization/{monetization}/release', [LeaveMonetizationController::class, 'release'])->name('admin.hr.leave.monetization.release');
        Route::post('admin/hr/leave/flexible-work/{arrangement}/decide', [FlexibleWorkController::class, 'decide'])->name('admin.hr.leave.fwa.decide');
        Route::post('admin/hr/leave/flexible-work/{arrangement}/terminate', [FlexibleWorkController::class, 'terminate'])->name('admin.hr.leave.fwa.terminate');
        Route::post('admin/hr/leave/flexible-work/reports/{report}/acknowledge', [FlexibleWorkController::class, 'acknowledge'])->name('admin.hr.leave.fwa.acknowledge');
        Route::post('admin/hr/employees/{employee}/leave/entries', [EmployeeLeaveController::class, 'store'])->name('admin.hr.employees.leave.store');
        Route::post('admin/hr/employees/{employee}/leave/entries/{entry}/reverse', [EmployeeLeaveController::class, 'reverse'])->name('admin.hr.employees.leave.reverse');
        Route::post('admin/hr/employees/{employee}/leave/entries/{entry}/correct', [EmployeeLeaveController::class, 'correct'])->name('admin.hr.employees.leave.correct');
        Route::put('admin/hr/employees/{employee}/leave/card-months/{cardMonth}', [EmployeeLeaveController::class, 'correctMonth'])->name('admin.hr.employees.leave.card-months.correct');
        Route::put('admin/hr/employees/{employee}/leave/settings', [EmployeeLeaveController::class, 'updateSettings'])->name('admin.hr.employees.leave.settings');
    });

    // HR-8: payroll. `payroll.manage` also reads; PayrollRunPolicy keeps
    // HR off their own pay. Payrolls sit under /runs/{run}, clear of the
    // fixed paths. Scoped bindings: an entry only resolves under its run,
    // a line under its entry, a pay item or loan under its employee.
    Route::middleware('permission:payroll.view,payroll.manage,payroll.tax')->group(function () {
        Route::get('admin/hr/payroll', [PayrollController::class, 'index'])->name('admin.hr.payroll.index');
    });

    // Accounting reads one payroll, enters withholding tax on one HRD
    // submitted, and returns it to HRD. Like payslip approvals, the
    // reviewer is who holds the Accountant III item (no role needed) or
    // anyone with `payroll.tax`, so these are guarded by PayrollRunPolicy
    // (`view`, `enterTax`) alone.
    Route::get('admin/hr/payroll/runs/{run}', [PayrollController::class, 'show'])->name('admin.hr.payroll.show');
    Route::get('admin/hr/payroll/runs/{run}/general-payroll', [PayrollController::class, 'generalPayroll'])->name('admin.hr.payroll.general');
    Route::get('admin/hr/payroll/runs/{run}/remittances', [PayrollController::class, 'remittances'])->name('admin.hr.payroll.remittances');
    Route::put('admin/hr/payroll/runs/{run}/withholding-tax', [PayrollController::class, 'withholdingTax'])->name('admin.hr.payroll.tax');
    Route::post('admin/hr/payroll/runs/{run}/return', [PayrollController::class, 'returnToHrd'])->name('admin.hr.payroll.return');

    Route::middleware('permission:payroll.view,payroll.manage')->scopeBindings()->group(function () {
        Route::get('admin/hr/payroll/runs/{run}/reconciliation', [PayrollReconciliationController::class, 'show'])->name('admin.hr.payroll.reconciliation');
        Route::post('admin/hr/payroll/runs/{run}/reconciliation', [PayrollReconciliationController::class, 'store'])->middleware('throttle:20,1')->name('admin.hr.payroll.reconciliation.store');
        Route::delete('admin/hr/payroll/runs/{run}/reconciliation', [PayrollReconciliationController::class, 'destroy'])->name('admin.hr.payroll.reconciliation.destroy');
        Route::get('admin/hr/payroll/components', [PayComponentController::class, 'index'])->name('admin.hr.payroll.components');
        Route::get('admin/hr/payroll/profiles', [PayProfileController::class, 'index'])->name('admin.hr.payroll.profiles');
        Route::get('admin/hr/payroll/profiles/{employee}', [PayProfileController::class, 'show'])->name('admin.hr.payroll.profiles.show');
        Route::get('admin/hr/payroll/loans', [PayrollMonitoringController::class, 'loans'])->name('admin.hr.payroll.loans');
        Route::get('admin/hr/payroll/requests', [PayrollMonitoringController::class, 'requests'])->name('admin.hr.payroll.requests');
    });

    Route::middleware('permission:payroll.manage')->scopeBindings()->group(function () {
        Route::post('admin/hr/payroll/runs', [PayrollController::class, 'store'])->name('admin.hr.payroll.store');
        Route::post('admin/hr/payroll/runs/{run}/regenerate', [PayrollController::class, 'regenerate'])->name('admin.hr.payroll.regenerate');
        Route::post('admin/hr/payroll/runs/{run}/submit', [PayrollController::class, 'submit'])->name('admin.hr.payroll.submit');
        Route::post('admin/hr/payroll/runs/{run}/finalize', [PayrollController::class, 'finalize'])->name('admin.hr.payroll.finalize');
        Route::post('admin/hr/payroll/runs/{run}/reopen', [PayrollController::class, 'reopen'])->name('admin.hr.payroll.reopen');
        Route::delete('admin/hr/payroll/runs/{run}', [PayrollController::class, 'destroy'])->name('admin.hr.payroll.destroy');
        Route::post('admin/hr/payroll/runs/{run}/entries/{entry}/cancel', [PayrollController::class, 'cancelEntry'])->name('admin.hr.payroll.entries.cancel');
        Route::post('admin/hr/payroll/runs/{run}/entries/{entry}/regenerate', [PayrollController::class, 'regenerateEntry'])->name('admin.hr.payroll.entries.regenerate');
        Route::post('admin/hr/payroll/runs/{run}/entries/{entry}/lines', [PayrollController::class, 'storeLine'])->name('admin.hr.payroll.lines.store');
        Route::put('admin/hr/payroll/runs/{run}/entries/{entry}/lines/{line}', [PayrollController::class, 'updateLine'])->name('admin.hr.payroll.lines.update');
        Route::delete('admin/hr/payroll/runs/{run}/entries/{entry}/lines/{line}', [PayrollController::class, 'destroyLine'])->name('admin.hr.payroll.lines.destroy');
        Route::post('admin/hr/payroll/components', [PayComponentController::class, 'store'])->name('admin.hr.payroll.components.store');
        Route::put('admin/hr/payroll/components/{component}', [PayComponentController::class, 'update'])->name('admin.hr.payroll.components.update');
        Route::delete('admin/hr/payroll/components/{component}', [PayComponentController::class, 'destroy'])->name('admin.hr.payroll.components.destroy');
        Route::post('admin/hr/payroll/profiles/{employee}/items', [PayProfileController::class, 'storeItem'])->name('admin.hr.payroll.items.store');
        Route::put('admin/hr/payroll/profiles/{employee}/items/{payItem}', [PayProfileController::class, 'updateItem'])->name('admin.hr.payroll.items.update');
        Route::delete('admin/hr/payroll/profiles/{employee}/items/{payItem}', [PayProfileController::class, 'destroyItem'])->name('admin.hr.payroll.items.destroy');
        Route::post('admin/hr/payroll/profiles/{employee}/loans', [PayProfileController::class, 'storeLoan'])->name('admin.hr.payroll.loans.store');
        Route::put('admin/hr/payroll/profiles/{employee}/loans/{loan}', [PayProfileController::class, 'updateLoan'])->name('admin.hr.payroll.loans.update');
        Route::post('admin/hr/payroll/profiles/{employee}/loans/{loan}/stop', [PayProfileController::class, 'stopLoan'])->name('admin.hr.payroll.loans.stop');
        Route::post('admin/hr/payroll/profiles/{employee}/loans/{loan}/resume', [PayProfileController::class, 'resumeLoan'])->name('admin.hr.payroll.loans.resume');
        Route::delete('admin/hr/payroll/profiles/{employee}/loans/{loan}', [PayProfileController::class, 'destroyLoan'])->name('admin.hr.payroll.loans.destroy');
        Route::post('admin/hr/payroll/profiles/{employee}/loans/{loan}/cancel', [PayProfileController::class, 'cancelLoan'])->name('admin.hr.payroll.loans.cancel');
        Route::post('admin/hr/payroll/profiles/{employee}/loans/{loan}/lines/{line}/remit', [PayProfileController::class, 'remitLine'])->withoutScopedBindings()->name('admin.hr.payroll.loans.remit');
    });

    // HR-4: plantilla and salary schedules. `plantilla.manage` also reads;
    // PlantillaItemPolicy keeps HR off their own appointment. Scoped
    // bindings: an appointment only resolves under its own item.
    Route::middleware('permission:plantilla.view,plantilla.manage')->group(function () {
        Route::get('admin/hr/plantilla', [PlantillaController::class, 'index'])->name('admin.hr.plantilla.index');
        Route::get('admin/hr/plantilla/{item}', [PlantillaController::class, 'show'])->name('admin.hr.plantilla.show');
        Route::get('admin/hr/salary-schedules', [SalaryScheduleController::class, 'index'])->name('admin.hr.salary-schedules.index');
        Route::get('admin/hr/positions', [PositionController::class, 'index'])->name('admin.hr.positions.index');
        Route::get('admin/hr/staffing', [StaffingController::class, 'index'])->name('admin.hr.staffing.index');
        // Casual, contractual, Job Order and Contract of Service staff (no plantilla item).
        Route::get('admin/hr/non-plantilla', [NonPlantillaController::class, 'index'])->name('admin.hr.non-plantilla.index');
    });

    Route::middleware('permission:plantilla.manage')->group(function () {
        Route::post('admin/hr/non-plantilla/contracts', [NonPlantillaController::class, 'store'])->name('admin.hr.non-plantilla.contracts.store');
        Route::put('admin/hr/non-plantilla/contracts/{contract}', [NonPlantillaController::class, 'update'])->name('admin.hr.non-plantilla.contracts.update');
        Route::delete('admin/hr/non-plantilla/contracts/{contract}', [NonPlantillaController::class, 'destroy'])->name('admin.hr.non-plantilla.contracts.destroy');
    });

    Route::middleware('permission:plantilla.manage')->scopeBindings()->group(function () {
        Route::post('admin/hr/plantilla', [PlantillaController::class, 'store'])->name('admin.hr.plantilla.store');
        // Completing the plantilla from a spreadsheet (its own path, clear of {item}).
        Route::get('admin/hr/plantilla-sheet', [PlantillaSheetController::class, 'show'])->name('admin.hr.plantilla.sheet');
        Route::get('admin/hr/plantilla-sheet/template', [PlantillaSheetController::class, 'template'])->name('admin.hr.plantilla.sheet.template');
        Route::post('admin/hr/plantilla-sheet', [PlantillaSheetController::class, 'upload'])->name('admin.hr.plantilla.sheet.upload');
        Route::post('admin/hr/plantilla-sheet/apply', [PlantillaSheetController::class, 'apply'])->name('admin.hr.plantilla.sheet.apply');
        Route::delete('admin/hr/plantilla-sheet', [PlantillaSheetController::class, 'discard'])->name('admin.hr.plantilla.sheet.discard');
        Route::put('admin/hr/plantilla/{item}', [PlantillaController::class, 'update'])->name('admin.hr.plantilla.update');
        Route::patch('admin/hr/plantilla/{item}/abolish', [PlantillaController::class, 'abolish'])->name('admin.hr.plantilla.abolish');
        Route::patch('admin/hr/plantilla/{item}/restore', [PlantillaController::class, 'restore'])->name('admin.hr.plantilla.restore');

        Route::post('admin/hr/plantilla/{item}/appointments', [PlantillaAppointmentController::class, 'store'])->name('admin.hr.plantilla.appointments.store');
        Route::post('admin/hr/plantilla/{item}/appointments/past', [PlantillaAppointmentController::class, 'storePast'])->name('admin.hr.plantilla.appointments.store-past');
        Route::put('admin/hr/plantilla/{item}/appointments/{appointment}', [PlantillaAppointmentController::class, 'update'])->name('admin.hr.plantilla.appointments.update');
        Route::post('admin/hr/plantilla/{item}/appointments/{appointment}/step-increment', [PlantillaAppointmentController::class, 'stepIncrement'])->name('admin.hr.plantilla.appointments.step-increment');
        Route::patch('admin/hr/plantilla/{item}/appointments/{appointment}/vacate', [PlantillaAppointmentController::class, 'vacate'])->name('admin.hr.plantilla.appointments.vacate');
        Route::delete('admin/hr/plantilla/{item}/appointments/{appointment}', [PlantillaAppointmentController::class, 'destroy'])->name('admin.hr.plantilla.appointments.destroy');

        Route::post('admin/hr/salary-schedules', [SalaryScheduleController::class, 'store'])->name('admin.hr.salary-schedules.store');
        Route::put('admin/hr/salary-schedules/{schedule}', [SalaryScheduleController::class, 'update'])->name('admin.hr.salary-schedules.update');
        Route::delete('admin/hr/salary-schedules/{schedule}', [SalaryScheduleController::class, 'destroy'])->name('admin.hr.salary-schedules.destroy');

        Route::post('admin/hr/positions', [PositionController::class, 'store'])->name('admin.hr.positions.store');
        Route::post('admin/hr/positions/from-staff', [PositionController::class, 'addFromStaff'])->name('admin.hr.positions.from-staff');
        Route::put('admin/hr/positions/{position}', [PositionController::class, 'update'])->name('admin.hr.positions.update');
        Route::delete('admin/hr/positions/{position}', [PositionController::class, 'destroy'])->name('admin.hr.positions.destroy');
    });

    Route::middleware('permission:employees.reassign')->scopeBindings()->group(function () {
        Route::post('admin/hr/employees/{employee}/assignments', [EmployeeAssignmentController::class, 'store'])->name('admin.hr.employees.assignments.store');
        // POST + _method=PUT, so a scanned order can be attached to a correction.
        Route::put('admin/hr/employees/{employee}/assignments/{assignment}', [EmployeeAssignmentController::class, 'update'])->name('admin.hr.employees.assignments.update');
        Route::delete('admin/hr/employees/{employee}/assignments/{assignment}', [EmployeeAssignmentController::class, 'destroy'])->name('admin.hr.employees.assignments.destroy');
    });

    // HR-2: an employee's PDS, for HR only (supervisors never reach it).
    Route::middleware('permission:pds.view')->group(function () {
        Route::get('admin/hr/employees/{employee}/pds', [EmployeePdsController::class, 'show'])->name('admin.hr.employees.pds');
        Route::get('admin/hr/employees/{employee}/pds/print', [EmployeePdsController::class, 'print'])->name('admin.hr.employees.pds.print');
        Route::get('admin/hr/employees/{employee}/pds/print.xlsx', [EmployeePdsController::class, 'workbook'])->middleware('throttle:30,1')->name('admin.hr.employees.pds.print.xlsx');
        Route::get('admin/hr/employees/{employee}/pds/photo', [PdsPhotoController::class, 'showFor'])->name('admin.hr.employees.pds.photo');
    });

    Route::middleware('permission:pds.update')->group(function () {
        Route::put('admin/hr/employees/{employee}/pds/{section}', [EmployeePdsController::class, 'update'])
            ->whereIn('section', PdsSchema::editableSectionKeys())
            ->name('admin.hr.employees.pds.update');
        Route::post('admin/hr/employees/{employee}/pds/import', [EmployeePdsImportController::class, 'preview'])->name('admin.hr.employees.pds.import');
        Route::post('admin/hr/employees/{employee}/pds/import/apply', [EmployeePdsImportController::class, 'apply'])->name('admin.hr.employees.pds.import.apply');
        Route::post('admin/hr/employees/{employee}/pds/photo', [PdsPhotoController::class, 'storeFor'])->name('admin.hr.employees.pds.photo.store');
        Route::delete('admin/hr/employees/{employee}/pds/photo', [PdsPhotoController::class, 'destroyFor'])->name('admin.hr.employees.pds.photo.destroy');
    });

    // HR-2c: an employee's 201-file documents (HR only). Scoped bindings:
    // a document id only resolves under its own employee.
    Route::middleware('permission:employee_documents.view')->group(function () {
        Route::get('admin/hr/employees/{employee}/documents', [EmployeeDocumentController::class, 'index'])->name('admin.hr.employees.documents');
    });

    Route::middleware('permission:employee_documents.manage')->scopeBindings()->group(function () {
        Route::post('admin/hr/employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->name('admin.hr.employees.documents.store');
        Route::patch('admin/hr/employees/{employee}/documents/{document}/archive', [EmployeeDocumentController::class, 'archive'])
            ->name('admin.hr.employees.documents.archive');
        Route::patch('admin/hr/employees/{employee}/documents/{document}/restore', [EmployeeDocumentController::class, 'restore'])
            ->name('admin.hr.employees.documents.restore');
    });

    // HR-2b: a signed PDS (read with either permission; reviewing needs
    // pds.verify — see PdsSubmissionPolicy) and the verification queue.
    Route::middleware('permission:pds.view,pds.verify')->group(function () {
        Route::get('admin/hr/pds-verifications/{submission}', [PdsVerificationController::class, 'show'])->name('admin.hr.pds-verifications.show');
    });

    // Notices of Salary Adjustment and of Step Increment: generate, print,
    // file the signed copy in the 201 File. SalaryNoticePolicy keeps HR off
    // their own notices.
    Route::middleware('permission:salary_notices.manage')->group(function () {
        Route::get('admin/hr/salary-notices', [SalaryNoticeController::class, 'index'])->name('admin.hr.salary-notices.index');
        Route::post('admin/hr/salary-notices/nosa', [SalaryNoticeController::class, 'generateNosa'])->name('admin.hr.salary-notices.nosa');
        Route::post('admin/hr/salary-notices/nosi', [SalaryNoticeController::class, 'generateNosi'])->name('admin.hr.salary-notices.nosi');
        Route::get('admin/hr/salary-notices/print', [SalaryNoticeController::class, 'print'])->name('admin.hr.salary-notices.print');
        Route::post('admin/hr/salary-notices/file', [SalaryNoticeController::class, 'fileMany'])->middleware('throttle:30,1')->name('admin.hr.salary-notices.file-many');
        Route::post('admin/hr/salary-notices/{notice}/file', [SalaryNoticeController::class, 'file'])->whereNumber('notice')->name('admin.hr.salary-notices.file');
        Route::delete('admin/hr/salary-notices/{notice}', [SalaryNoticeController::class, 'destroy'])->whereNumber('notice')->name('admin.hr.salary-notices.destroy');
    });

    // SALN: HRD's compliance list, exemptions, receiving, filing and encoding
    // on someone's behalf (`saln.manage`); the queue and review are shared
    // with the SALN Committee (`saln.review`). SalnPolicy decides each SALN,
    // and nobody decides their own.
    Route::middleware('permission:saln.manage')->group(function () {
        Route::get('admin/hr/saln', [SalnController::class, 'index'])->name('admin.hr.saln.index');
        Route::post('admin/hr/saln', [SalnController::class, 'open'])->name('admin.hr.saln.open');
        Route::post('admin/hr/saln/exemptions', [SalnController::class, 'exempt'])->name('admin.hr.saln.exempt');
        Route::post('admin/hr/saln/settings', [SalnController::class, 'settings'])->name('admin.hr.saln.settings');
        Route::post('admin/hr/saln/receive', [SalnController::class, 'receive'])->middleware('throttle:60,1')->name('admin.hr.saln.receive');
        Route::get('admin/hr/saln/{saln}/edit', [SalnController::class, 'edit'])->whereNumber('saln')->name('admin.hr.saln.edit');
        Route::put('admin/hr/saln/{saln}', [SalnController::class, 'update'])->whereNumber('saln')->name('admin.hr.saln.update');
        Route::post('admin/hr/saln/{saln}/generate', [SalnController::class, 'generate'])->whereNumber('saln')->name('admin.hr.saln.generate');
        Route::post('admin/hr/saln/{saln}/file', [SalnController::class, 'file'])->whereNumber('saln')->middleware('throttle:30,1')->name('admin.hr.saln.file');
    });

    Route::middleware('permission:saln.review,saln.manage')->group(function () {
        Route::get('admin/hr/saln/queue', [SalnController::class, 'queue'])->name('admin.hr.saln.queue');
        Route::get('admin/hr/saln/{saln}', [SalnController::class, 'show'])->whereNumber('saln')->name('admin.hr.saln.show');
        Route::get('admin/hr/saln/{saln}/versions/{version}/print', [SalnController::class, 'print'])->whereNumber(['saln', 'version'])->name('admin.hr.saln.print');
        Route::post('admin/hr/saln/{saln}/decide', [SalnController::class, 'decide'])->whereNumber('saln')->middleware('throttle:60,1')->name('admin.hr.saln.decide');
    });

    Route::middleware('permission:data_corrections.review')->group(function () {
        Route::get('admin/hr/corrections', [DataCorrectionController::class, 'index'])->name('admin.hr.corrections.index');
        Route::post('admin/hr/corrections/{correction}', [DataCorrectionController::class, 'decide'])->middleware('throttle:60,1')->name('admin.hr.corrections.decide');
    });

    Route::middleware('permission:pds.verify')->group(function () {
        Route::get('admin/hr/pds-verifications', [PdsVerificationController::class, 'index'])->name('admin.hr.pds-verifications.index');
        Route::post('admin/hr/pds-verifications/{submission}/verify', [PdsVerificationController::class, 'verify'])->name('admin.hr.pds-verifications.verify');
        Route::post('admin/hr/pds-verifications/{submission}/return', [PdsVerificationController::class, 'sendBack'])->name('admin.hr.pds-verifications.return');
    });

    // HR-3b: HRD's Learning & Development — the overview and one person's
    // log (read with either permission), managing on someone's behalf
    // (scoped: a training only resolves under its own employee), and the
    // review queue. The policies keep HRD off their own records.
    Route::middleware('permission:ld.view,ld.manage')->group(function () {
        Route::get('admin/hr/learning-development', [LearningDevelopmentController::class, 'index'])->name('admin.hr.ld.index');
        // HR-3c: the dashboard and training report (approved trainings only).
        Route::get('admin/hr/learning-development/dashboard', [LdReportController::class, 'dashboard'])->name('admin.hr.ld.dashboard');
        Route::get('admin/hr/learning-development/report', [LdReportController::class, 'report'])->name('admin.hr.ld.report');
        Route::get('admin/hr/employees/{employee}/learning-development', [EmployeeLearningDevelopmentController::class, 'index'])->name('admin.hr.employees.ld');
    });

    Route::middleware('permission:ld.manage')->scopeBindings()->group(function () {
        Route::post('admin/hr/learning-development/bulk', [LearningDevelopmentController::class, 'storeMany'])->name('admin.hr.ld.store-many');
        Route::post('admin/hr/employees/{employee}/learning-development', [EmployeeLearningDevelopmentController::class, 'store'])->name('admin.hr.employees.ld.store');
        // POST + _method=PUT, so a certificate file can ride along.
        Route::put('admin/hr/employees/{employee}/learning-development/{training}', [EmployeeLearningDevelopmentController::class, 'update'])->name('admin.hr.employees.ld.update');
        Route::delete('admin/hr/employees/{employee}/learning-development/{training}', [EmployeeLearningDevelopmentController::class, 'destroy'])->name('admin.hr.employees.ld.destroy');
    });

    Route::middleware('permission:ld.approve')->group(function () {
        Route::get('admin/hr/learning-development/reviews', [LdReviewController::class, 'index'])->name('admin.hr.ld.reviews');
        Route::post('admin/hr/learning-development/reviews/{training}/approve', [LdReviewController::class, 'approve'])->name('admin.hr.ld.reviews.approve');
        Route::post('admin/hr/learning-development/reviews/{training}/reject', [LdReviewController::class, 'reject'])->name('admin.hr.ld.reviews.reject');
    });

    Route::middleware('permission:announcements.view')->group(function () {
        Route::get('admin/announcements', [AdminAnnouncementController::class, 'index'])->name('admin.announcements.index');
    });

    Route::middleware('permission:announcements.create')->group(function () {
        Route::post('admin/announcements', [AdminAnnouncementController::class, 'store'])->name('admin.announcements.store');
    });

    Route::middleware('permission:announcements.view')->group(function () {
        Route::get('admin/announcements/{announcement}', [AdminAnnouncementController::class, 'show'])->name('admin.announcements.show');
    });

    Route::middleware('permission:announcements.update')->group(function () {
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

    // Legacy "Backup Database" — Super Administrators only (see BackupController).
    Route::middleware('permission:backups.create')->group(function () {
        Route::get('admin/backup', [BackupController::class, 'index'])->name('admin.backup.index');
        Route::post('admin/backup', [BackupController::class, 'download'])
            ->middleware('throttle:5,10')
            ->name('admin.backup.download');
        // Backups kept on the server (nightly imis:backup, or "Back up now").
        Route::post('admin/backup/files', [BackupController::class, 'store'])
            ->middleware('throttle:3,10')
            ->name('admin.backup.store');
        Route::post('admin/backup/files/{backup}/download', [BackupController::class, 'downloadStored'])
            ->where('backup', '[A-Za-z0-9.\-]+')
            ->middleware('throttle:5,10')
            ->name('admin.backup.download-stored');
        Route::delete('admin/backup/files/{backup}', [BackupController::class, 'destroy'])
            ->where('backup', '[A-Za-z0-9.\-]+')
            ->name('admin.backup.destroy');
    });

    // Legacy System Administration logs (read-only) and Active Sessions.
    Route::middleware('permission:audit_logs.view')->group(function () {
        Route::get('admin/login-history', [ActivityLogController::class, 'logins'])->name('admin.login-history.index');
        Route::get('admin/login-history/export', [ActivityLogController::class, 'exportLogins'])->name('admin.login-history.export');
        Route::get('admin/access-logs', [ActivityLogController::class, 'access'])->name('admin.access-logs.index');
        Route::get('admin/access-logs/export', [ActivityLogController::class, 'exportAccess'])->name('admin.access-logs.export');
        Route::get('admin/security-logs', [ActivityLogController::class, 'security'])->name('admin.security-logs.index');
        Route::get('admin/security-logs/export', [ActivityLogController::class, 'exportSecurity'])->name('admin.security-logs.export');
    });

    Route::middleware('permission:api_tokens.manage')->group(function () {
        Route::get('admin/api-tokens', [ApiTokenController::class, 'index'])->name('admin.api-tokens.index');
        Route::post('admin/api-tokens', [ApiTokenController::class, 'store'])->name('admin.api-tokens.store');
        Route::delete('admin/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('admin.api-tokens.destroy');
    });

    Route::middleware('permission:sessions.view')->group(function () {
        Route::get('admin/sessions', [SessionController::class, 'index'])->name('admin.sessions.index');
    });

    Route::middleware('permission:sessions.revoke')->group(function () {
        Route::delete('admin/sessions/{key}', [SessionController::class, 'destroy'])
            ->where('key', '[a-f0-9]{64}')
            ->name('admin.sessions.destroy');
        Route::delete('admin/sessions/user/{user}', [SessionController::class, 'destroyUser'])->name('admin.sessions.destroy-user');
    });

    Route::middleware('permission:settings.view')->group(function () {
        Route::get('admin/settings', [SettingController::class, 'index'])->name('admin.settings.index');
        Route::get('admin/system-health', [SystemHealthController::class, 'index'])->name('admin.system-health');
        Route::get('admin/scheduled-tasks', [ScheduledTaskController::class, 'index'])->name('admin.scheduled-tasks.index');
        Route::get('admin/email-templates', [EmailTemplateController::class, 'index'])->name('admin.email-templates.index');
        Route::post('admin/email-templates/{key}/preview', [EmailTemplateController::class, 'preview'])
            ->whereIn('key', array_keys(EmailTemplates::DEFINITIONS))->name('admin.email-templates.preview');
        Route::get('admin/branding', [BrandingController::class, 'index'])->name('admin.branding.index');
    });

    // Admin-3: replace or reset the logos and sign-in background.
    Route::middleware('permission:settings.update')->group(function () {
        // POST (not PUT): the image is a file upload.
        Route::post('admin/branding/{key}', [BrandingController::class, 'update'])
            ->whereIn('key', array_keys(Branding::ASSETS))->name('admin.branding.update');
        Route::delete('admin/branding/{key}', [BrandingController::class, 'destroy'])
            ->whereIn('key', array_keys(Branding::ASSETS))->name('admin.branding.destroy');
    });

    // Admin-3: the wording of the emails IMIS sends.
    Route::middleware('permission:settings.update')->group(function () {
        Route::put('admin/email-templates/{key}', [EmailTemplateController::class, 'update'])
            ->whereIn('key', array_keys(EmailTemplates::DEFINITIONS))->name('admin.email-templates.update');
        Route::delete('admin/email-templates/{key}', [EmailTemplateController::class, 'destroy'])
            ->whereIn('key', array_keys(EmailTemplates::DEFINITIONS))->name('admin.email-templates.destroy');
        Route::post('admin/email-templates/{key}/test', [EmailTemplateController::class, 'test'])
            ->whereIn('key', array_keys(EmailTemplates::DEFINITIONS))->middleware('throttle:5,1')->name('admin.email-templates.test');
    });

    // Admin-3: pause, resume and run scheduled tasks.
    Route::middleware('permission:scheduled_tasks.manage')->group(function () {
        Route::post('admin/scheduled-tasks/pause', [ScheduledTaskController::class, 'pause'])->name('admin.scheduled-tasks.pause');
        Route::post('admin/scheduled-tasks/resume', [ScheduledTaskController::class, 'resume'])->name('admin.scheduled-tasks.resume');
        Route::post('admin/scheduled-tasks/run', [ScheduledTaskController::class, 'run'])->middleware('throttle:10,1')->name('admin.scheduled-tasks.run');
    });

    Route::middleware('permission:changelogs.view')->group(function () {
        Route::get('admin/changelogs', [ChangelogController::class, 'index'])->name('admin.changelogs.index');
    });

    Route::middleware('permission:changelogs.manage')->group(function () {
        Route::post('admin/changelogs', [ChangelogController::class, 'store'])->name('admin.changelogs.store');
        Route::put('admin/changelogs/{changelog}', [ChangelogController::class, 'update'])->name('admin.changelogs.update');
    });

    Route::middleware('permission:changelogs.publish')->group(function () {
        Route::patch('admin/changelogs/{changelog}/status', [ChangelogController::class, 'updateStatus'])
            ->name('admin.changelogs.update-status');
    });

    Route::middleware('permission:feedback.view')->group(function () {
        Route::get('admin/feedback', [FeedbackController::class, 'index'])->name('admin.feedback.index');
        Route::get('admin/feedback/{feedback}', [FeedbackController::class, 'show'])->name('admin.feedback.show');
    });

    Route::middleware('permission:feedback.manage')->group(function () {
        Route::post('admin/feedback/{feedback}/replies', [FeedbackController::class, 'reply'])->name('admin.feedback.reply');
        Route::patch('admin/feedback/{feedback}/status', [FeedbackController::class, 'updateStatus'])->name('admin.feedback.update-status');
    });

    // `{group}` is bound to App\Enums\SettingGroup — an unknown group is a
    // 404. Protected settings are refused by UpdateSettingsRequest.
    Route::middleware('permission:settings.update')->group(function () {
        Route::put('admin/settings/{group}', [SettingController::class, 'update'])->name('admin.settings.update');
        // Danger zone, Super Administrators only (see LeaveCreditsPurgeController).
        Route::delete('admin/settings/leave-credits', [LeaveCreditsPurgeController::class, 'destroy'])->middleware('throttle:3,1')->name('admin.settings.leave-credits.destroy');
        // Development only (see TestDataResetController).
        Route::delete('admin/settings/test-data', [TestDataResetController::class, 'destroy'])->middleware('throttle:3,1')->name('admin.settings.test-data.destroy');
    });
});
