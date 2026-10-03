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
    // Granting/changing/revoking a user's role inside a system. Defining a
    // system's roles is part of editing the system (`applications.update`).
    case ApplicationsManageAccess = 'applications.manage_access';
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

    // "What's New" release notes: view drafts, write them, and publish
    // (publishing is what makes a release pop up for everyone).
    case ChangelogsView = 'changelogs.view';
    case ChangelogsManage = 'changelogs.manage';
    case ChangelogsPublish = 'changelogs.publish';

    // Active sessions: see who is signed in, and force-sign-out a session.
    case SessionsView = 'sessions.view';
    case SessionsRevoke = 'sessions.revoke';

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

    // HR-5: viewing and printing anyone's Service Record (HR). Everyone
    // reads and prints their own with no permission.
    case ServiceRecordsView = 'service_records.view';

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
}
