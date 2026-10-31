<?php

use App\Http\Controllers\Account\NotificationSettingsController;
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationLaunchController;
use App\Http\Controllers\EmployeeDocumentFileController;
use App\Http\Controllers\ExecutiveDashboardController;
use App\Http\Controllers\ExecutiveSettingsController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LeaveApplicationController;
use App\Http\Controllers\LeaveApprovalController;
use App\Http\Controllers\My201FileController;
use App\Http\Controllers\MyEarningsController;
use App\Http\Controllers\MyEmployeeRecordController;
use App\Http\Controllers\MyFlexibleWorkController;
use App\Http\Controllers\MyLearningDevelopmentController;
use App\Http\Controllers\MyLeaveApplicationController;
use App\Http\Controllers\MyLeaveController;
use App\Http\Controllers\MyMonetizationController;
use App\Http\Controllers\MyPayslipController;
use App\Http\Controllers\MyPdsController;
use App\Http\Controllers\MyRecordAccessController;
use App\Http\Controllers\MyServiceRecordController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationalUnitController;
use App\Http\Controllers\PayslipApprovalController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PdsPhotoController;
use App\Http\Controllers\PdsSubmissionPrintController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalSearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\WhatsNewController;
use App\Support\HR\Pds\PdsSchema;
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

    // Feedback, suggestions and recommendations: anyone signed in may send
    // one (from the account-menu dialog) and read the replies to their own.
    Route::get('my/feedback', [FeedbackController::class, 'index'])->name('my.feedback');
    Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:5,1')->name('feedback.store');

    Route::get('whats-new', [WhatsNewController::class, 'index'])->name('whats-new');
    Route::post('whats-new/seen', [WhatsNewController::class, 'seen'])->name('whats-new.seen');

    // Self-service account actions (the legacy profile menu).
    Route::redirect('account', '/account/password')->name('account.index');
    Route::get('account/password', [PasswordController::class, 'edit'])->name('account.password.edit');
    // Admin-3: which notifications also come by email.
    Route::get('account/notifications', [NotificationSettingsController::class, 'edit'])->name('account.notifications.edit');
    Route::put('account/notifications', [NotificationSettingsController::class, 'update'])->name('account.notifications.update');
    Route::put('account/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('account.password.update');

    // HR-1 self-service: your own employee record (no permission needed).
    Route::get('my/employee-record', [MyEmployeeRecordController::class, 'show'])->name('my.employee-record');

    // Admin-2: who else opened or printed your HR records (no permission needed).
    Route::get('my/record-access', [MyRecordAccessController::class, 'index'])->name('my.record-access');

    // HR-2 self-service: your own Personal Data Sheet.
    Route::get('my/pds', [MyPdsController::class, 'show'])->name('my.pds');
    Route::put('my/pds/{section}', [MyPdsController::class, 'update'])
        ->whereIn('section', PdsSchema::editableSectionKeys())
        ->name('my.pds.update');
    Route::get('my/pds/print', [MyPdsController::class, 'print'])->name('my.pds.print');
    // The e-signature re-checks the password, so it's rate-limited like
    // the password change.
    Route::post('my/pds/submit', [MyPdsController::class, 'submit'])
        ->middleware('throttle:6,1')
        ->name('my.pds.submit');
    Route::get('my/pds/submissions/{submission}', [MyPdsController::class, 'submission'])->name('my.pds.submission');
    Route::get('my/pds/photo', [PdsPhotoController::class, 'showOwn'])->name('my.pds.photo');
    Route::post('my/pds/photo', [PdsPhotoController::class, 'storeOwn'])->name('my.pds.photo.store');
    Route::delete('my/pds/photo', [PdsPhotoController::class, 'destroyOwn'])->name('my.pds.photo.destroy');

    // HR-3a: your own Learning & Development log (ldms), and a training's
    // certificate (its owner or HRD — PdsLearningDevelopmentPolicy::view).
    Route::get('my/learning-development', [MyLearningDevelopmentController::class, 'index'])->name('my.ld');
    Route::post('my/learning-development', [MyLearningDevelopmentController::class, 'store'])->name('my.ld.store');
    // POST + _method=PUT, so a certificate file can ride along.
    Route::put('my/learning-development/{training}', [MyLearningDevelopmentController::class, 'update'])->name('my.ld.update');
    Route::delete('my/learning-development/{training}', [MyLearningDevelopmentController::class, 'destroy'])->name('my.ld.destroy');
    Route::get('hr/trainings/{training}/certificate', [MyLearningDevelopmentController::class, 'certificate'])->name('hr.trainings.certificate');

    // HR-6: your own leave credits and leave card.
    Route::get('my/leave', [MyLeaveController::class, 'show'])->name('my.leave');

    // HR-6b: filing leave (CS Form No. 6) and your applications.
    Route::get('my/leave/apply', [MyLeaveApplicationController::class, 'create'])->name('my.leave.apply');
    Route::get('my/leave/applications', [MyLeaveApplicationController::class, 'index'])->name('my.leave.applications');
    Route::post('my/leave/applications', [MyLeaveApplicationController::class, 'store'])->middleware('throttle:20,1')->name('my.leave.applications.store');
    // POST + _method=PUT, so a corrected attachment can come along.
    Route::put('my/leave/applications/{application}', [MyLeaveApplicationController::class, 'update'])->name('my.leave.applications.update');
    Route::post('my/leave/applications/{application}/cancel', [MyLeaveApplicationController::class, 'cancel'])->name('my.leave.applications.cancel');

    // One application (the employee, its approvers, HR — LeaveApplicationPolicy),
    // its printable form and attachment; and the approvals queue.
    Route::get('leave/applications/{application}', [LeaveApplicationController::class, 'show'])->name('leave.applications.show');
    Route::get('leave/applications/{application}/form', [LeaveApplicationController::class, 'print'])->name('leave.applications.form');
    Route::get('leave/applications/{application}/attachment', [LeaveApplicationController::class, 'attachment'])->name('leave.applications.attachment');
    Route::get('leave/approvals', [LeaveApprovalController::class, 'index'])->name('leave.approvals');
    Route::post('leave/approvals/steps/{step}', [LeaveApprovalController::class, 'act'])->middleware('throttle:60,1')->name('leave.approvals.act');

    // HR-6c: monetization, flexible work and weekly reports, the calendar.
    Route::get('my/leave/calendar', [MyLeaveController::class, 'calendar'])->name('my.leave.calendar');
    Route::get('my/leave/monetization', [MyMonetizationController::class, 'show'])->name('my.leave.monetization');
    Route::post('my/leave/monetization', [MyMonetizationController::class, 'store'])->middleware('throttle:10,1')->name('my.leave.monetization.store');
    Route::post('my/leave/monetization/{monetization}/cancel', [MyMonetizationController::class, 'cancel'])->name('my.leave.monetization.cancel');
    Route::get('my/flexible-work', [MyFlexibleWorkController::class, 'show'])->name('my.fwa');
    Route::post('my/flexible-work', [MyFlexibleWorkController::class, 'store'])->middleware('throttle:10,1')->name('my.fwa.store');
    Route::post('my/flexible-work/{arrangement}/cancel', [MyFlexibleWorkController::class, 'cancel'])->name('my.fwa.cancel');
    Route::post('my/flexible-work/reports', [MyFlexibleWorkController::class, 'report'])->middleware('throttle:20,1')->name('my.fwa.report');

    // HR-8: your payslips (requested, then approved by the head of HRD),
    // the head of HRD's approvals, and one printable payslip (yours once
    // approved, or anyone's for payroll staff — PayrollRunPolicy).
    Route::get('my/earnings', [MyEarningsController::class, 'show'])->name('my.earnings');
    Route::get('my/payslips', [MyPayslipController::class, 'index'])->name('my.payslips');
    Route::post('my/payslips', [MyPayslipController::class, 'store'])->middleware('throttle:10,1')->name('my.payslips.store');
    Route::post('my/payslips/{payslipRequest}/cancel', [MyPayslipController::class, 'cancel'])->name('my.payslips.cancel');
    Route::get('payslips/approvals', [PayslipApprovalController::class, 'index'])->name('payslips.approvals');
    Route::post('payslips/approvals/{payslipRequest}', [PayslipApprovalController::class, 'decide'])->middleware('throttle:60,1')->name('payslips.approvals.decide');
    Route::get('payslips/{entry}', [PayslipController::class, 'show'])->name('payslips.show');

    // Your notifications (the top-bar bell).
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');

    // HR-5: your own Service Record (a reference copy; HR certifies).
    Route::get('my/service-record', [MyServiceRecordController::class, 'show'])->name('my.service-record');
    Route::get('my/service-record/print', [MyServiceRecordController::class, 'print'])->name('my.service-record.print');

    // HR-2c: your own 201 file, and opening a 201 document (its owner or
    // HR — EmployeeDocumentPolicy::view).
    Route::get('my/201-file', [My201FileController::class, 'show'])->name('my.201-file');
    Route::get('hr/documents/{document}', [EmployeeDocumentFileController::class, 'show'])->name('hr.documents.show');
    Route::get('hr/documents/{document}/download', [EmployeeDocumentFileController::class, 'download'])->name('hr.documents.download');

    // A signed PDS copy, for its owner or HR (PdsSubmissionPolicy::view).
    Route::get('pds/submissions/{submission}/print', PdsSubmissionPrintController::class)->name('pds.submissions.print');
    Route::get('pds/submissions/{submission}/photo', [PdsPhotoController::class, 'showSubmission'])->name('pds.submissions.photo');

    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('announcements/{announcement:slug}', [AnnouncementController::class, 'show'])->name('announcements.show');

    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    // Opens the system (and records the launch) — see ApplicationLaunchController.
    Route::get('applications/{application}/launch', ApplicationLaunchController::class)
        ->middleware('throttle:60,1')
        ->name('applications.launch');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');

    Route::get('organizational-units', [OrganizationalUnitController::class, 'index'])->name('organizational-units.index');
    Route::get('organizational-units/{organizationalUnit}', [OrganizationalUnitController::class, 'show'])->name('organizational-units.show');

    // Executive Dashboard: top management's view of every module and
    // connected system (Exec-1 to Exec-3), and its settings.
    Route::middleware('permission:executive.view')->group(function () {
        Route::get('executive', [ExecutiveDashboardController::class, 'index'])->name('executive.index');
        Route::get('executive/print', [ExecutiveDashboardController::class, 'print'])->name('executive.print');
    });

    Route::middleware('permission:executive.manage')->group(function () {
        Route::get('executive/settings', [ExecutiveSettingsController::class, 'index'])->name('executive.settings');
        Route::put('executive/feeds/{application}', [ExecutiveSettingsController::class, 'updateFeed'])->name('executive.feeds.update');
        Route::post('executive/feeds/{application}/key', [ExecutiveSettingsController::class, 'generateKey'])->name('executive.feeds.key');
        Route::put('executive/targets', [ExecutiveSettingsController::class, 'saveTarget'])->name('executive.targets.save');
        Route::delete('executive/targets/{target}', [ExecutiveSettingsController::class, 'destroyTarget'])->name('executive.targets.destroy');
        Route::post('executive/collect', [ExecutiveSettingsController::class, 'collect'])->middleware('throttle:6,1')->name('executive.collect');
    });
});
