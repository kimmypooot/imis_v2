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

    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserStatusChanged = 'user.status_changed';
    case UserRolesChanged = 'user.roles_changed';
    case UserPasswordResetSent = 'user.password_reset_sent';
    case UserPasswordChanged = 'user.password_changed';

    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RolePermissionsChanged = 'role.permissions_changed';

    case OrganizationalUnitCreated = 'organizational_unit.created';
    case OrganizationalUnitUpdated = 'organizational_unit.updated';
    case OrganizationalUnitStatusChanged = 'organizational_unit.status_changed';

    case ServiceCreated = 'service.created';
    case ServiceUpdated = 'service.updated';
    case ServiceStatusChanged = 'service.status_changed';

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

    case ChangelogCreated = 'changelog.created';
    case ChangelogUpdated = 'changelog.updated';
    case ChangelogPublished = 'changelog.published';
    case ChangelogUnpublished = 'changelog.unpublished';

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
