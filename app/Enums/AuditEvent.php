<?php

namespace App\Enums;

/**
 * Every auditable event, as `subject.action`. The part after the dot is
 * stored separately as `audit_logs.action`, so a log can be filtered by
 * one exact event or by a verb across subjects.
 *
 * Adding a case here is all a future module needs to start recording a
 * new kind of event — the admin filter options are derived from it.
 */
enum AuditEvent: string
{
    case AuthLogin = 'auth.login';
    case AuthLogout = 'auth.logout';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthLoginBlocked = 'auth.login_blocked';
    case AuthLockout = 'auth.lockout';
    case AuthPasswordReset = 'auth.password_reset';
    case SessionEnded = 'auth.session_ended';
    case DatabaseBackupDownloaded = 'system.backup_downloaded';
    case DatabaseBackupCreated = 'system.backup_created';
    case DatabaseBackupFailed = 'system.backup_failed';
    case DatabaseBackupDeleted = 'system.backup_deleted';
    case LogsPruned = 'system.logs_pruned';
    case ScheduledTaskPaused = 'system.task_paused';
    case ScheduledTaskResumed = 'system.task_resumed';
    case ScheduledTaskRun = 'system.task_run';

    case UserCreated = 'user.created';
    case UsersImported = 'user.imported';
    case UserUpdated = 'user.updated';
    case UserStatusChanged = 'user.status_changed';
    case UserRolesChanged = 'user.roles_changed';
    case UserPasswordResetSent = 'user.password_reset_sent';
    case UserPasswordChanged = 'user.password_changed';
    case UserPasswordSet = 'user.password_set';

    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RolePermissionsChanged = 'role.permissions_changed';

    case OrganizationalUnitCreated = 'organizational_unit.created';
    case OrganizationalUnitUpdated = 'organizational_unit.updated';
    case OrganizationalUnitStatusChanged = 'organizational_unit.status_changed';

    case ServiceCreated = 'service.created';
    case ServiceUpdated = 'service.updated';
    case ServiceStatusChanged = 'service.status_changed';
    case ServiceCategoryCreated = 'service_category.created';
    case ServiceCategoryUpdated = 'service_category.updated';
    case ServiceCategoryStatusChanged = 'service_category.status_changed';

    case ApplicationCreated = 'application.created';
    case ApplicationUpdated = 'application.updated';
    case ApplicationStatusChanged = 'application.status_changed';
    case ApplicationListingsChanged = 'application.listings_changed';
    case ApplicationMaintenanceStarted = 'application.maintenance_started';
    case ApplicationMaintenanceUpdated = 'application.maintenance_updated';
    case ApplicationMaintenanceEnded = 'application.maintenance_ended';
    case ApplicationRoleCreated = 'application.role_created';
    case ApplicationRoleUpdated = 'application.role_updated';
    case ApplicationRoleStatusChanged = 'application.role_status_changed';
    case ApplicationAccessGranted = 'application.access_granted';
    case ApplicationAccessChanged = 'application.access_changed';
    case ApplicationAccessRevoked = 'application.access_revoked';

    // HR-1. `viewed` is the Data Privacy Act access trail: recorded each
    // time someone other than the employee opens their record.
    case EmployeeCreated = 'employee.created';
    case EmployeeUpdated = 'employee.updated';
    case EmployeeViewed = 'employee.viewed';
    // The HR dashboard analytics (names, birthdays, ages); once a day per viewer.
    case HrDashboardViewed = 'employee.hr_dashboard_viewed';
    // HR-1b: assignment history. Metadata names the office move, if any.
    case EmployeeAssignmentRecorded = 'employee_assignment.recorded';
    case EmployeeAssignmentUpdated = 'employee_assignment.updated';
    case EmployeeAssignmentDeleted = 'employee_assignment.deleted';
    // HR-4 plantilla.
    case PlantillaItemCreated = 'plantilla_item.created';
    case PlantillaItemUpdated = 'plantilla_item.updated';
    case PlantillaItemAbolished = 'plantilla_item.abolished';
    case PlantillaItemRestored = 'plantilla_item.restored';
    case PlantillaAppointed = 'plantilla_appointment.appointed';
    case PlantillaStepIncrement = 'plantilla_appointment.step_increment';
    case PlantillaVacated = 'plantilla_appointment.vacated';
    case PlantillaAppointmentCorrected = 'plantilla_appointment.corrected';
    case PlantillaAppointmentRemoved = 'plantilla_appointment.removed';
    // Non-plantilla staff (casual, contractual, Job Order, Contract of Service): their contracts / appointments.
    case NonPlantillaContractRecorded = 'non_plantilla_contract.recorded';
    case NonPlantillaContractUpdated = 'non_plantilla_contract.updated';
    case NonPlantillaContractRemoved = 'non_plantilla_contract.removed';
    // Plantilla items that only ever held non-plantilla staff, taken off the plantilla (ids in metadata).
    case PlantillaNonPlantillaItemsRemoved = 'plantilla_item.non_plantilla_removed';
    // `imis:link-plantilla`: staff linked to new items from their accounts (counts in metadata).
    case PlantillaLinkedFromAccounts = 'plantilla_appointment.linked_from_accounts';
    // Items and links completed from the plantilla spreadsheet (item ids in metadata).
    case PlantillaSheetApplied = 'plantilla_item.sheet_applied';
    // `imis:import-plantilla`: the certified Plantilla of Personnel workbook (ids touched, by kind, in metadata).
    case PlantillaImported = 'plantilla_item.imported';
    // HR-4 positions. Metadata counts the plantilla items adjusted.
    case PositionCreated = 'position.created';
    case PositionUpdated = 'position.updated';
    case PositionDeleted = 'position.deleted';
    case PositionsAddedFromStaff = 'position.added_from_staff';
    case SalaryScheduleSaved = 'salary_schedule.saved';
    case SalaryScheduleDeleted = 'salary_schedule.deleted';
    // HR-5: HR opening or printing someone's Service Record.
    case ServiceRecordViewed = 'service_record.viewed';
    case ServiceRecordPrinted = 'service_record.printed';
    case ServiceRecordLineAdded = 'service_record.line_added';
    case ServiceRecordLineUpdated = 'service_record.line_updated';
    case ServiceRecordLineRemoved = 'service_record.line_removed';
    // HR-6a leave foundations.
    case LeaveTypeSaved = 'leave_type.saved';
    case HolidaySaved = 'holiday.saved';
    case HolidayDeleted = 'holiday.deleted';
    case LeaveSettingsUpdated = 'leave_settings.updated';
    case LeaveCreditPosted = 'leave_credit.posted';
    case LeaveCreditReversed = 'leave_credit.reversed';
    case LeaveAccrualRun = 'leave_credit.accrual_run';
    case LeaveYearStartRun = 'leave_credit.year_start_run';
    case LeaveCardViewed = 'leave_card.viewed';
    // HR-6b leave applications.
    case LeaveApplicationFiled = 'leave_application.filed';
    case LeaveApplicationResubmitted = 'leave_application.resubmitted';
    case LeaveApplicationCancelled = 'leave_application.cancelled';
    case LeaveApplicationActed = 'leave_application.acted';
    case LeaveApplicationViewed = 'leave_application.viewed';
    case LeaveAttachmentViewed = 'leave_application.attachment_viewed';
    case LeaveDesignationSaved = 'leave_designation.saved';
    case LeaveDesignationDeleted = 'leave_designation.deleted';
    // HR-6c: monetization / terminal leave, flexible work and weekly reports.
    case LeaveMonetizationRequested = 'leave_monetization.requested';
    case LeaveMonetizationDecided = 'leave_monetization.decided';
    case LeaveMonetizationReleased = 'leave_monetization.released';
    case LeaveMonetizationCancelled = 'leave_monetization.cancelled';
    case FwaRequested = 'fwa.requested';
    case FwaDecided = 'fwa.decided';
    case FwaTerminated = 'fwa.terminated';
    case FwaCancelled = 'fwa.cancelled';
    case FwaReportSubmitted = 'fwa_report.submitted';
    case FwaReportAcknowledged = 'fwa_report.acknowledged';
    // HR-7: the one-time import from the legacy LMS (counts in metadata).
    case LeaveImportedFromLegacy = 'leave_credit.imported_from_legacy';
    // HR-8 payroll. Metadata names fields or counts — never amounts.
    case PayComponentCreated = 'pay_component.created';
    case PayComponentUpdated = 'pay_component.updated';
    case PayComponentDeleted = 'pay_component.deleted';
    case PayItemSaved = 'employee_pay_item.saved';
    case PayItemDeleted = 'employee_pay_item.deleted';
    case LoanSaved = 'employee_loan.saved';
    case LoanStopped = 'employee_loan.stopped';
    case LoanResumed = 'employee_loan.resumed';
    case LoanDeleted = 'employee_loan.deleted';
    case LoanCancelled = 'employee_loan.cancelled';
    case LoanRemitted = 'employee_loan.remitted';
    case PayrollCreated = 'payroll_run.created';
    case PayrollRegenerated = 'payroll_run.regenerated';
    case PayrollAdjusted = 'payroll_entry.adjusted';
    case PayrollFinalized = 'payroll_run.finalized';
    case PayrollReopened = 'payroll_run.reopened';
    case PayrollDeleted = 'payroll_run.deleted';
    case PayslipCancelled = 'payroll_entry.cancelled';
    case PayslipRequested = 'payslip_request.requested';
    case PayslipRequestCancelled = 'payslip_request.cancelled';
    case PayslipRequestDecided = 'payslip_request.decided';
    // Someone other than the employee opened their payslip (RA 10173).
    case PayslipViewed = 'payroll_entry.payslip_viewed';
    // HR-2. Metadata names the section and which fields/rows changed —
    // never the values, which are sensitive.
    case PdsViewed = 'pds.viewed';
    case PdsUpdated = 'pds.updated';
    // HR-2b: e-signed submission, HR's verdict, and printing someone
    // else's PDS.
    case PdsSubmitted = 'pds.submitted';
    case PdsVerified = 'pds.verified';
    case PdsReturned = 'pds.returned';
    case PdsPrinted = 'pds.printed';
    // HR-2c. `viewed` is recorded each time someone other than the
    // employee opens or downloads a 201 document.
    case EmployeeDocumentUploaded = 'employee_document.uploaded';
    case EmployeeDocumentViewed = 'employee_document.viewed';
    case EmployeeDocumentArchived = 'employee_document.archived';
    case EmployeeDocumentRestored = 'employee_document.restored';
    // HR-3. `certificate_viewed` is recorded when someone other than the
    // employee opens a training certificate.
    case LdRecordCreated = 'ld_record.created';
    case LdRecordUpdated = 'ld_record.updated';
    case LdRecordDeleted = 'ld_record.deleted';
    case LdCertificateViewed = 'ld_record.certificate_viewed';
    // HR-3b: HRD opening someone's L&D log (the access trail), and HRD's
    // review of an employee's entry.
    case LdLogViewed = 'ld_record.log_viewed';
    case LdRecordApproved = 'ld_record.approved';
    case LdRecordRejected = 'ld_record.rejected';
    // HR-3c: HRD opening the training report (names, sex and positions of
    // everyone trained), and the one-time ldms import.
    case LdReportViewed = 'ld_record.report_viewed';
    case LdRecordsImported = 'ld_record.imported';

    case CommitteeCreated = 'committee.created';
    case CommitteeUpdated = 'committee.updated';
    case CommitteeStatusChanged = 'committee.status_changed';
    case CommitteeMemberAdded = 'committee.member_added';
    case CommitteeMemberUpdated = 'committee.member_updated';
    case CommitteeMemberStatusChanged = 'committee.member_status_changed';

    case AnnouncementCreated = 'announcement.created';
    case AnnouncementUpdated = 'announcement.updated';
    case AnnouncementPublished = 'announcement.published';
    case AnnouncementUnpublished = 'announcement.unpublished';
    case AnnouncementArchived = 'announcement.archived';
    case AnnouncementRestored = 'announcement.restored';
    case AnnouncementFeatured = 'announcement.featured';
    case AnnouncementUnfeatured = 'announcement.unfeatured';

    case SettingUpdated = 'setting.updated';
    case EmailTemplateUpdated = 'setting.email_template_updated';
    case BrandingUpdated = 'setting.branding_updated';

    case ChangelogCreated = 'changelog.created';
    case ChangelogUpdated = 'changelog.updated';
    case ChangelogPublished = 'changelog.published';
    case ChangelogUnpublished = 'changelog.unpublished';

    case FeedbackReplied = 'feedback.replied';
    case FeedbackNoted = 'feedback.noted';
    case FeedbackStatusChanged = 'feedback.status_changed';

    // Executive Dashboard settings: a system's summary feed and the
    // targets figures are measured against. Viewing the dashboard isn't
    // audited: it shows totals only, never a person's record.
    case ExecutiveFeedUpdated = 'executive.feed_updated';
    case ExecutiveFeedKeyGenerated = 'executive.feed_key_generated';
    case ExecutiveTargetSaved = 'executive.target_saved';
    case ExecutiveTargetRemoved = 'executive.target_removed';
    case ExecutiveCollected = 'executive.collected';

    /**
     * Someone looking at or printing an employee's HR records — the
     * personal-data access trail kept for RA 10173, which has its own
     * retention period (System Settings → Log Retention).
     *
     * @return array<int, self>
     */
    public static function personalDataAccess(): array
    {
        return [
            self::EmployeeViewed, self::ServiceRecordViewed, self::ServiceRecordPrinted,
            self::LeaveCardViewed, self::LeaveApplicationViewed, self::LeaveAttachmentViewed,
            self::PayslipViewed, self::PdsViewed, self::PdsPrinted, self::EmployeeDocumentViewed,
            self::LdCertificateViewed, self::LdLogViewed, self::LdReportViewed,
        ];
    }

    public function action(): string
    {
        return str($this->value)->afterLast('.')->value();
    }

    public function label(): string
    {
        return str($this->value)->replace(['.', '_'], ' ')->headline()->value();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
