<?php

namespace App\Support\Settings;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Support\HR\CertifyingOfficer;
use InvalidArgumentException;
use LogicException;

/**
 * The single definition of which settings exist. `sync()` (run by
 * SettingSeeder) writes these definitions into the `settings` table:
 * metadata always follows the code, an editable setting's saved value is
 * kept, and rows for settings no longer defined here are removed. There is
 * no UI or route that creates a setting.
 *
 * Protected settings (`editable => false`) can't be changed through the
 * admin UI by anyone; their value is reset to the default here on every
 * sync, so changing one is a reviewed code change plus a re-seed.
 *
 * Out of scope by design: anything from `.env` or `config/` — database
 * credentials, API keys, private keys, mail/server configuration. Those
 * stay in the environment and are never read or exposed by this module;
 * `sync()` refuses keys that look like credentials as a guard.
 */
final class SettingRegistry
{
    private const FORBIDDEN_KEY_PATTERN = '/password|passwd|secret|token|api[_-]?key|private[_-]?key|credential|app[_-]?key|^db_|^mail_|^aws_/i';

    private const PASSWORD_POLICY_PREFIX = 'password_policy_';

    /**
     * In display order within each group.
     *
     * @return array<string, array{
     *     type: SettingType,
     *     group: SettingGroup,
     *     label: string,
     *     description: string,
     *     default: string|int|bool|null,
     *     editable?: bool,
     *     constraints?: array{required?: bool, min?: int, max?: int, options?: array<int, string>},
     * }>
     */
    public static function definitions(): array
    {
        return [
            'organization_name' => [
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'Organization name',
                'description' => 'The office\'s official name, shown in page footers. Protected: it identifies the agency, so it is changed only through a reviewed deployment.',
                'default' => 'Civil Service Commission Regional Office VIII',
                'editable' => false,
            ],
            'portal_name' => [
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'System name',
                'description' => 'The system\'s name in emails (e.g. "Reset your … password").',
                'default' => 'CSC RO VIII IMIS',
                'constraints' => ['required' => true, 'max' => 60],
            ],
            'header_name' => [
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'Name beside the logo',
                'description' => 'Shown next to the agency logo at the top of every page. The logo itself is changed in Admin → Branding.',
                'default' => 'CSC RO VIII',
                'constraints' => ['required' => true, 'max' => 30],
            ],

            'portal_welcome_message' => [
                'type' => SettingType::Text,
                'group' => SettingGroup::Portal,
                'label' => 'Welcome message',
                'description' => 'Shown under the greeting on the portal home. Leave empty to use the default message.',
                'default' => 'Enhancing public service delivery through innovation and digital transformation.',
                'constraints' => ['max' => 300],
            ],
            'portal_home_announcement_limit' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Portal,
                'label' => 'Announcements on portal home',
                'description' => 'How many live announcements the portal home shows (1–10). The full list is always on the Announcements page.',
                'default' => 3,
                'constraints' => ['required' => true, 'min' => 1, 'max' => 10],
            ],
            'portal_services_enabled' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Portal,
                'label' => 'Show the Services directory',
                'description' => 'Turn off to hide Services from the menu, the portal home and search, and to close the /services pages. Admin → Services stays available for filling it in.',
                'default' => true,
            ],
            'portal_notice_enabled' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Portal,
                'label' => 'Show portal notice',
                'description' => 'Displays the notice below at the top of every signed-in page.',
                'default' => false,
            ],
            'portal_notice' => [
                'type' => SettingType::Text,
                'group' => SettingGroup::Portal,
                'label' => 'Portal notice',
                'description' => 'A short plain-text message, e.g. scheduled maintenance. Only shown while "Show portal notice" is on.',
                'default' => null,
                'constraints' => ['max' => 500],
            ],

            'contact_email' => [
                'type' => SettingType::Email,
                'group' => SettingGroup::Contact,
                'label' => 'Help desk email',
                'description' => 'Shown in the portal home footer for IMIS questions. Leave empty to hide.',
                'default' => null,
            ],
            'contact_phone' => [
                'type' => SettingType::String,
                'group' => SettingGroup::Contact,
                'label' => 'Help desk phone',
                'description' => 'Shown in the portal home footer. Leave empty to hide.',
                'default' => null,
                'constraints' => ['max' => 50],
            ],
            'contact_website_url' => [
                'type' => SettingType::Url,
                'group' => SettingGroup::Contact,
                'label' => 'Office website',
                'description' => 'Shown in the portal home footer. Full address starting with https://. Leave empty to hide.',
                'default' => null,
            ],

            // Admin-2: the privacy notice (/privacy). The rest of the notice
            // is built from what IMIS actually does (PrivacyNotice).
            'privacy_officer_name' => [
                'type' => SettingType::String,
                'group' => SettingGroup::Privacy,
                'label' => 'Data Protection Officer',
                'description' => 'Name (or office) of the Data Protection Officer, shown on the privacy notice. Leave empty to refer people to the HRD.',
                'default' => null,
                'constraints' => ['max' => 150],
            ],
            'privacy_officer_email' => [
                'type' => SettingType::Email,
                'group' => SettingGroup::Privacy,
                'label' => 'Data Protection Officer email',
                'description' => 'Where people send privacy questions and requests. Leave empty to hide.',
                'default' => null,
            ],
            'privacy_notice_extra' => [
                'type' => SettingType::Text,
                'group' => SettingGroup::Privacy,
                'label' => 'Additional notice text',
                'description' => 'Anything the office adds to the notice, such as the Privacy Manual reference (plain text, up to 2,000 characters).',
                'default' => null,
                'constraints' => ['max' => 2000],
            ],

            // HR-5: what the printed Service Record says.
            'hr_certifying_officer_name' => [
                'type' => SettingType::String,
                'group' => SettingGroup::HumanResources,
                'label' => 'Certifying officer',
                'description' => 'Who signs printed Service Records. Leave empty to print whoever holds the selected position on the Plantilla now; type a name only to print someone else.',
                'default' => null,
                'constraints' => ['max' => 150],
            ],
            'hr_certifying_officer_position' => [
                'type' => SettingType::Select,
                'group' => SettingGroup::HumanResources,
                'label' => 'Certifying officer\'s position',
                'description' => 'Printed under the certifying officer\'s name.',
                'default' => null,
                'constraints' => ['options' => array_keys(CertifyingOfficer::POSITIONS)],
            ],
            // HR-6: the Director IV's supervisor (Supervisors), outside IMIS.
            'hr_csc_chairperson' => [
                'type' => SettingType::String,
                'group' => SettingGroup::HumanResources,
                'label' => 'Chairperson of the Civil Service Commission',
                'description' => 'Shown as the immediate supervisor of the Director IV (leave).',
                'default' => 'Atty. Marilyn B. Yap, DPA',
                'constraints' => ['max' => 150],
            ],
            'hr_service_record_branch' => [
                'type' => SettingType::String,
                'group' => SettingGroup::HumanResources,
                'label' => 'Service Record branch',
                'description' => 'The Branch column of the Service Record (National, for a national government agency).',
                'default' => 'National',
                'constraints' => ['required' => true, 'max' => 50],
            ],

            // Security policy, read through App\Support\Security\SecurityPolicy.
            'security_idle_timeout_minutes' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Idle timeout (minutes)',
                'description' => 'Signs a user out after this long without activity (5–720). Leave empty to use the server default (SESSION_LIFETIME). "Remember me" sign-ins are exempt.',
                'default' => null,
                'constraints' => ['min' => 5, 'max' => 720],
            ],
            'security_session_limit_minutes' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Maximum sign-in length (minutes)',
                'description' => 'Signs a user out this long after they signed in, however active they are (30–1440). Leave empty to use the server default (SESSION_ABSOLUTE_LIFETIME).',
                'default' => null,
                'constraints' => ['min' => 30, 'max' => 1440],
            ],
            'security_login_max_attempts' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Failed sign-ins before lockout',
                'description' => 'Wrong passwords allowed for one username from one address before sign-in is locked (3–20).',
                'default' => 5,
                'constraints' => ['required' => true, 'min' => 3, 'max' => 20],
            ],
            'security_lockout_minutes' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Lockout length (minutes)',
                'description' => 'How long sign-in stays locked after too many failures (1–1440).',
                'default' => 15,
                'constraints' => ['required' => true, 'min' => 1, 'max' => 1440],
            ],
            'password_policy_min_length' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Minimum password length',
                'description' => 'Passwords always need letters and numbers; this sets how long they must be (8–64).',
                'default' => 8,
                'constraints' => ['required' => true, 'min' => 8, 'max' => 64],
            ],
            'security_remember_accounts' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Security,
                'label' => 'Offer "Remember this account" on the login page',
                'description' => 'Lets people save their name, username and photo link on the browser they sign in from, so the login page can show a card to pick instead of typing the username. Passwords are never saved. Turn off if staff share computers.',
                'default' => true,
            ],
            'account_inactive_days' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Deactivate after days without signing in',
                'description' => 'Accounts nobody has signed in to for this many days are deactivated overnight (30–730). Counts from the last sign-in, creation or reactivation. Super Administrators are never deactivated automatically. 0 turns this off.',
                'default' => 0,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 730, 'min_unless_zero' => 30],
            ],
            'password_policy_mixed_case' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Security,
                'label' => 'Require upper- and lowercase letters',
                'description' => 'New passwords must mix capital and small letters.',
                'default' => false,
            ],
            'password_policy_symbols' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Security,
                'label' => 'Require a symbol',
                'description' => 'New passwords must include a symbol such as ! or #.',
                'default' => false,
            ],
            'password_policy_expiry_days' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Password expiry (days)',
                'description' => 'After this many days users must choose a new password before continuing (30–365). 0 means passwords never expire.',
                'default' => 0,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 365, 'min_unless_zero' => 30],
            ],
            'password_policy_history' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Security,
                'label' => 'Block reuse of recent passwords',
                'description' => 'How many previous passwords can\'t be chosen again (0–24). 0 only blocks the current one.',
                'default' => 0,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 24],
            ],

            // Run by imis:backup (scheduled nightly in routes/console.php).
            'backup_schedule_enabled' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Backups,
                'label' => 'Back up the database every night',
                'description' => 'Saves a compressed backup in the server\'s private storage at 02:00. Copy them off the server from time to time: a backup on the same disk doesn\'t survive losing that disk.',
                'default' => true,
            ],
            'backup_keep_count' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Backups,
                'label' => 'Backups to keep',
                'description' => 'The newest backups kept on the server (1–90); older automatic and manual ones are deleted after each new backup.',
                'default' => 14,
                'constraints' => ['required' => true, 'min' => 1, 'max' => 90],
            ],

            // Run by imis:prune-logs (scheduled nightly in routes/console.php).
            'retention_login_history_months' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Retention,
                'label' => 'Login history (months)',
                'description' => 'Sign-ins and sign-outs older than this are deleted (6–120). 0 keeps them forever.',
                'default' => 24,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 120, 'min_unless_zero' => 6],
            ],
            'retention_system_access_months' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Retention,
                'label' => 'System access logs (months)',
                'description' => 'Records of systems opened from the portal older than this are deleted (6–120). 0 keeps them forever.',
                'default' => 24,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 120, 'min_unless_zero' => 6],
            ],
            'retention_audit_log_months' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Retention,
                'label' => 'Audit and security log (months)',
                'description' => 'Audit entries (changes, sign-in failures, lockouts) older than this are deleted (12–240), except personal-data access, below. 0 keeps them forever.',
                'default' => 0,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 240, 'min_unless_zero' => 12],
            ],
            'retention_personal_data_access_months' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Retention,
                'label' => 'Personal-data access entries (months)',
                'description' => 'Who viewed or printed an employee\'s HR records (RA 10173). Entries older than this are deleted (36–240). 0 keeps them forever.',
                'default' => 0,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 240, 'min_unless_zero' => 36],
            ],
            'retention_executive_history_months' => [
                'type' => SettingType::Integer,
                'group' => SettingGroup::Retention,
                'label' => 'Executive Dashboard history (months)',
                'description' => 'Past figures behind the dashboard\'s trends older than this are deleted (3–120). 0 keeps them forever.',
                'default' => 24,
                'constraints' => ['required' => true, 'min' => 0, 'max' => 120, 'min_unless_zero' => 3],
            ],
            'executive_alerts' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Executive,
                'label' => 'Alerts',
                'description' => 'Notify Executive Dashboard viewers (the bell) when a figure falls behind its target, or a system\'s figures stop updating.',
                'default' => true,
            ],
            'executive_weekly_digest' => [
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Executive,
                'label' => 'Weekly summary',
                'description' => 'Every Monday at 7:00 AM, send Executive Dashboard viewers a summary by email and the bell: systems needing attention and figures off target.',
                'default' => false,
            ],
        ];
    }

    public static function default(string $key): string|int|bool|null
    {
        $definition = self::definitions()[$key]
            ?? throw new InvalidArgumentException("Unknown setting [{$key}].");

        return $definition['default'];
    }

    /**
     * `password_policy_*` keys describe rules for passwords, never a
     * password, so only the part after the prefix is checked against the
     * credential guard (`password_policy_secret` is still refused).
     */
    private static function withoutPolicyPrefix(string $key): string
    {
        return str_starts_with($key, self::PASSWORD_POLICY_PREFIX) ? substr($key, strlen(self::PASSWORD_POLICY_PREFIX)) : $key;
    }

    public static function sync(): void
    {
        $definitions = self::definitions();
        $positions = [];

        foreach ($definitions as $key => $definition) {
            if (preg_match(self::FORBIDDEN_KEY_PATTERN, self::withoutPolicyPrefix($key))) {
                throw new LogicException("Setting [{$key}] looks like a credential; secrets belong in the environment, not in settings.");
            }

            $editable = $definition['editable'] ?? true;
            $group = $definition['group'];
            $positions[$group->value] = ($positions[$group->value] ?? 0) + 1;

            $setting = Setting::query()->where('key', $key)->first()
                ?? (new Setting)->forceFill(['key' => $key]);

            $setting->forceFill([
                'type' => $definition['type'],
                'group' => $group,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'editable' => $editable,
                'constraints' => $definition['constraints'] ?? null,
                'sort_order' => $positions[$group->value],
            ]);

            if (! $setting->exists || ! $editable) {
                $setting->value = $definition['type']->serialize($definition['default']);
            }

            $setting->save();
        }

        Setting::query()->whereNotIn('key', array_keys($definitions))->get()->each->delete();

        // The model events already flush, but seeders may run with model
        // events disabled (WithoutModelEvents).
        Settings::flush();
    }
}
