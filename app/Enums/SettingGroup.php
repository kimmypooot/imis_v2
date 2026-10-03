<?php

namespace App\Enums;

/**
 * How settings are grouped on the admin screen. Each group is saved as
 * one form (`PUT /admin/settings/{group}`); the case order is the order
 * the groups appear in.
 */
enum SettingGroup: string
{
    case General = 'general';
    case Portal = 'portal';
    case Contact = 'contact';
    case HumanResources = 'human_resources';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Portal => 'Portal',
            self::Contact => 'Contact Information',
            self::HumanResources => 'Human Resources',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::General => 'How the system identifies itself.',
            self::Portal => 'What employees see on the portal home and across the application shell.',
            self::Contact => 'How employees can reach the office for help with IMIS.',
            self::HumanResources => 'What HR documents print, such as who certifies the Service Record.',
        };
    }
}
