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
    case Security = 'security';
    case Backups = 'backups';
    case Retention = 'retention';
    case Executive = 'executive';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Portal => 'Portal',
            self::Contact => 'Contact Information',
            self::HumanResources => 'Human Resources',
            self::Security => 'Security',
            self::Backups => 'Automatic Backups',
            self::Retention => 'Log Retention',
            self::Executive => 'Executive Dashboard',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::General => 'How the system identifies itself.',
            self::Portal => 'What employees see on the portal home and across the application shell.',
            self::Contact => 'How employees can reach the office for help with IMIS.',
            self::HumanResources => 'What HR documents print, such as who certifies the Service Record.',
            self::Security => 'Sign-in, session and password rules for every account. Changes apply from the next request; password rules are checked when a password is next set.',
            self::Backups => 'The nightly database backup kept on the server, in addition to the backups you download.',
            self::Retention => 'How long logs are kept before the nightly clean-up deletes them. 0 keeps them forever.',
            self::Executive => 'Alerts and the weekly summary for top management. Targets and connected systems are set on the Executive Dashboard\'s own Settings page.',
        };
    }
}
