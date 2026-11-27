<?php

namespace App\Enums;

/**
 * Stable, machine-readable identifiers for the permissions seeded to
 * demonstrate the authorization architecture. Future modules define their
 * own permission rows (`resource.action`) without needing to extend this
 * enum — it exists so this phase's own code never hardcodes a permission
 * string in more than one place.
 */
enum PermissionName: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesUpdate = 'roles.update';
    case RolesDelete = 'roles.delete';

    case PermissionsView = 'permissions.view';
    case PermissionsManage = 'permissions.manage';

    case OrganizationalUnitsView = 'organizational_units.view';
    case OrganizationalUnitsCreate = 'organizational_units.create';
    case OrganizationalUnitsUpdate = 'organizational_units.update';
    case OrganizationalUnitsArchive = 'organizational_units.archive';

    case ServicesView = 'services.view';
    case ServicesCreate = 'services.create';
    case ServicesUpdate = 'services.update';
    case ServicesArchive = 'services.archive';

    case ApplicationsView = 'applications.view';
    case ApplicationsCreate = 'applications.create';
    case ApplicationsUpdate = 'applications.update';
    case ApplicationsArchive = 'applications.archive';
    // Permanently removing a retired or never-used system from the directory.
    case ApplicationsDelete = 'applications.delete';
    // Granting/changing/revoking a user's role inside a system. Defining a
    // system's roles is part of editing the system (`applications.update`).
    case ApplicationsManageAccess = 'applications.manage_access';
    // The same, only in the systems your own office manages (a system's
    // managing office), e.g. HRD for ORS and OTRS. Never your own access,
    // and never office designations (those stay with manage_access).
    case ApplicationsManageOfficeAccess = 'applications.manage_office_access';
    // Putting a system in/out of maintenance — and launching it while it's
    // in maintenance, so whoever does the work can verify it.
    case ApplicationsMaintenance = 'applications.maintenance';

    case CommitteesView = 'committees.view';
    case CommitteesCreate = 'committees.create';
    case CommitteesUpdate = 'committees.update';
    case CommitteesArchive = 'committees.archive';

    case AnnouncementsView = 'announcements.view';
    case AnnouncementsCreate = 'announcements.create';
    case AnnouncementsUpdate = 'announcements.update';
    case AnnouncementsPublish = 'announcements.publish';
    case AnnouncementsArchive = 'announcements.archive';

    // Read-only on purpose: there is no permission (and no route) to edit
    // or delete audit entries — see AuditLogPolicy.
    case AuditLogsView = 'audit_logs.view';

    // Viewing vs. changing application settings. Protected settings can't
    // be changed even with `settings.update` — see SettingRegistry.
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    // Admin-3, Admin → Scheduled Tasks: pause, resume and run a task now.
    // Seeing the list needs only `settings.view`.
    case ScheduledTasksManage = 'scheduled_tasks.manage';

    // "What's New" release notes: view drafts, write them, and publish
    // (publishing is what makes a release pop up for everyone).
    case ChangelogsView = 'changelogs.view';
    case ChangelogsManage = 'changelogs.manage';
    case ChangelogsPublish = 'changelogs.publish';

    // Feedback, suggestions and recommendations from users. Anyone signed
    // in may send one (and read the replies to theirs) with no permission;
    // `view` reads everyone's, `manage` replies, adds private notes and
    // changes the status.
    case FeedbackView = 'feedback.view';
    case FeedbackManage = 'feedback.manage';

    // Active sessions: see who is signed in, and force-sign-out a session.
    case SessionsView = 'sessions.view';
    case SessionsRevoke = 'sessions.revoke';

    // Issuing and revoking the bearer tokens connected systems use to call IMIS's APIs.
    case ApiTokensManage = 'api_tokens.manage';

    // Database backup download. Additionally limited to Super
    // Administrators and a password re-check — see BackupController.
    case BackupsCreate = 'backups.create';

    // HR-1 employee records (personal data under RA 10173). `view` sees
    // everyone's; `view_unit` only the viewer's own office's (supervisors).
    // Everyone can always see their own record, with no permission.
    case EmployeesView = 'employees.view';
    case EmployeesViewUnit = 'employees.view_unit';
    case EmployeesCreate = 'employees.create';
    case EmployeesUpdate = 'employees.update';
    // HR-1b: recording, correcting and removing someone's reassignments,
    // details and designations (never your own). Reading the history
    // follows the employee-record rules above.
    case EmployeesReassign = 'employees.reassign';

    // HR-2 Personal Data Sheet. Deliberately separate from `employees.*`:
    // the PDS holds identity numbers and answers on cases, health and
    // disability, so supervisors (`view_unit`) never see it (RA 10173 data
    // minimization). Everyone reads and edits their own PDS with no
    // permission; `pds.update` is HR editing on someone's behalf.
    case PdsView = 'pds.view';
    case PdsUpdate = 'pds.update';
    // HR-2b: verify or return a signed PDS submission (never your own).
    case PdsVerify = 'pds.verify';

    // HR-2c 201-file documents. Employees always see their own (active)
    // documents with no permission; supervisors never see anyone's.
    case EmployeeDocumentsView = 'employee_documents.view';
    case EmployeeDocumentsManage = 'employee_documents.manage';

    // SALN (RA 6713 §8). Everyone encodes and prints their own with no
    // permission. `review` is the SALN Committee (clear or return a SALN
    // HRD has received); `manage` is HRD: receive hard copies, decide
    // (overriding the Committee), file scans, exemptions, compliance, and
    // encoding on someone's behalf. Nobody decides their own SALN.
    case SalnReview = 'saln.review';
    case SalnManage = 'saln.manage';

    // HR-3 Learning & Development. Everyone keeps their own log with no
    // permission. `view` is HRD seeing everyone's records and certificates
    // (HR-3b adds managing and approving).
    case LdView = 'ld.view';
    // HR-3b: adding, editing and removing records on someone's behalf
    // (incl. approved ones, and bulk add), and approving or rejecting
    // employees' entries. Never your own records (separation of duties).
    case LdManage = 'ld.manage';
    case LdApprove = 'ld.approve';

    // HR-4 plantilla. `view` reads the items, who holds them and the
    // salary schedules; `manage` adds and edits items, appoints, records
    // step increments and vacancies, and maintains the salary schedules
    // (never their own appointment).
    case PlantillaView = 'plantilla.view';
    case PlantillaManage = 'plantilla.manage';
    // Override: correct any period's details (step, status, dates, …)
    // when the record is wrong — their own included. Corrections only, no
    // appointing or step increments; each is audited, own ones flagged.
    case PlantillaCorrect = 'plantilla.correct';

    // HR-5: viewing and printing anyone's Service Record (HR). Everyone
    // reads and prints their own with no permission.
    case ServiceRecordsView = 'service_records.view';
    // Adding, editing and removing Service Record lines by hand — service
    // in another agency before the CSC (never on their own record).
    case ServiceRecordsManage = 'service_records.manage';

    // Notices of Salary Adjustment and of Step Increment: generating them
    // (a NOSI also records the step increment on the plantilla), printing
    // them and filing the signed copies in the 201 File. Never your own.
    case SalaryNoticesManage = 'salary_notices.manage';

    // HR-6 leave. `view` reads everyone's leave cards; `manage` maintains
    // leave types and holidays, employees' leave settings, opening
    // balances and adjustments, and runs accrual (never on their own card).
    // Everyone reads their own leave with no permission.
    case LeaveView = 'leave.view';
    case LeaveManage = 'leave.manage';
    // HR-6b, CS Form No. 6 Section 7: `certify` is HR's Part A
    // (certification of leave credits); `approve` is the Head of Agency's
    // Parts C/D. Immediate supervisors need no permission (Part B goes to
    // the supervisor named on the employee's leave settings), and an
    // officer-in-charge designation lets someone act for the Head for a
    // period. Nobody acts on their own application.
    case LeaveCertify = 'leave.certify';
    case LeaveApprove = 'leave.approve';

    // HR DTR (Daily Time Record from biometric logs). `view` reads punches
    // and device ID mappings; `manage` uploads logs and maps IDs. Everyone
    // will read their own DTR with no permission.
    case DtrView = 'dtr.view';
    case DtrManage = 'dtr.manage';

    // HR-8 payroll. `view` reads payrolls, pay profiles, loans, payslips
    // and payslip requests (monitoring); `manage` also maintains pay
    // components, pay profiles and loans, and drafts, adjusts, finalizes
    // and reopens payrolls (never their own pay). Payslip requests are
    // decided by the head of HRD, which needs no permission
    // (App\Support\HR\Payroll\PayslipApprovers); everyone requests their
    // own payslips with no permission.
    // Deciding staff requests to correct their own employee record
    // (never one's own). Everyone files requests with no permission.
    case DataCorrectionsReview = 'data_corrections.review';

    case PayrollView = 'payroll.view';
    case PayrollManage = 'payroll.manage';
    // Accounting (the Accountant III at MSD): reviews payrolls HRD submits,
    // enters each person's withholding tax (never their own) and returns
    // them to HRD. Also reads payrolls.
    case PayrollTax = 'payroll.tax';

    // Executive Dashboard (Exec-1 to Exec-3): `view` is top management
    // seeing every module's and system's figures in one place — totals
    // only, never a person's record; links into a module still follow
    // that module's own permissions. `manage` connects systems' summary
    // feeds, sets targets, and runs a collection on demand.
    case ExecutiveView = 'executive.view';
    case ExecutiveManage = 'executive.manage';

    // Calendar of Activities. `view` is every staff account (the default
    // `user` role): the calendar, list and details. `post` adds, edits,
    // cancels and marks done the activities of the poster's own office
    // (exactly their organizational unit). `manage` is PALD: everything
    // for any office, returning or removing activities, and what changed.
    case ActivitiesView = 'activities.view';
    case ActivitiesPost = 'activities.post';
    case ActivitiesManage = 'activities.manage';
}
