<?php

namespace App\Enums;

/**
 * Shared active/inactive lifecycle for portal directory entries
 * (applications, services, organizational units). Deliberately not the
 * same enum as RoleStatus/UserStatus — those belong to Phase 1/2 models
 * and are not portal directory concepts.
 */
enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => ucfirst($case->value)],
            self::cases(),
        );
    }
}
