<?php

namespace App\Support\Settings;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Models\Setting;
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
     *     constraints?: array{required?: bool, min?: int, max?: int},
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
                'description' => 'Short name shown at the top of the sidebar and header.',
                'default' => 'CSC RO VIII IMIS',
                'constraints' => ['required' => true, 'max' => 60],
            ],

            'portal_welcome_message' => [
                'type' => SettingType::Text,
                'group' => SettingGroup::Portal,
                'label' => 'Welcome message',
                'description' => 'Shown under the greeting on the portal home. Leave empty to use the default message.',
                'default' => 'Welcome to the CSC RO VIII Integrated Management Information System.',
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
                'description' => 'Shown on the portal home for IMIS questions. Leave empty to hide.',
                'default' => null,
            ],
            'contact_phone' => [
                'type' => SettingType::String,
                'group' => SettingGroup::Contact,
                'label' => 'Help desk phone',
                'description' => 'Shown on the portal home. Leave empty to hide.',
                'default' => null,
                'constraints' => ['max' => 50],
            ],
            'contact_website_url' => [
                'type' => SettingType::Url,
                'group' => SettingGroup::Contact,
                'label' => 'Office website',
                'description' => 'Full address starting with https://. Leave empty to hide.',
                'default' => null,
            ],
        ];
    }

    public static function default(string $key): string|int|bool|null
    {
        $definition = self::definitions()[$key]
            ?? throw new InvalidArgumentException("Unknown setting [{$key}].");

        return $definition['default'];
    }

    public static function sync(): void
    {
        $definitions = self::definitions();
        $positions = [];

        foreach ($definitions as $key => $definition) {
            if (preg_match(self::FORBIDDEN_KEY_PATTERN, $key)) {
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
