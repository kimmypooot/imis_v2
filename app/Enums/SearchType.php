<?php

namespace App\Enums;

/**
 * The record types portal search covers, in the order their result
 * groups are shown. Only portal-facing content is here — never users,
 * roles, audit logs, settings, or any other administrative record. A
 * `?type=` value outside this list is ignored.
 */
enum SearchType: string
{
    case Services = 'services';
    case Applications = 'applications';
    case OrganizationalUnits = 'organizational_units';
    case Announcements = 'announcements';

    public function label(): string
    {
        return match ($this) {
            self::Services => 'Services',
            self::Applications => 'Systems / Applications',
            self::OrganizationalUnits => 'Organizational Units',
            self::Announcements => 'Announcements',
        };
    }

    /**
     * Shown on each result so its kind is clear without relying on the
     * group heading (e.g. when scanning a filtered list).
     */
    public function singularLabel(): string
    {
        return match ($this) {
            self::Services => 'Service',
            self::Applications => 'System',
            self::OrganizationalUnits => 'Organizational unit',
            self::Announcements => 'Announcement',
        };
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
