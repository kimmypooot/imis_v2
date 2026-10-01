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
}
