<?php

namespace App\Support\Notifications;

use App\Models\User;

/**
 * Admin-3, Account → Notification settings: every notification shows under
 * the bell; for each kind, a person may also have it emailed. Email is
 * off by default except the weekly summary, which is an email by nature.
 * Kinds tied to a permission are only offered to those who hold it.
 * Nothing here can turn off a password-reset or set-password email.
 */
final class NotificationPreferences
{
    public const KINDS = [
        'leave' => [
            'label' => 'Leave',
            'description' => 'Your leave applications as they move along, and applications waiting for you to act on.',
            'email' => false,
            'permission' => null,
        ],
        'hr' => [
            'label' => 'HR requests',
            'description' => 'Decisions on your payslip requests, leave monetization and flexible work — and payslip requests waiting for your approval.',
            'email' => false,
            'permission' => null,
        ],
        'executive' => [
            'label' => 'Executive Dashboard alerts',
            'description' => 'A figure falling behind its target, or a system\'s figures no longer updating.',
            'email' => false,
            'permission' => 'executive.view',
        ],
        'executive_digest' => [
            'label' => 'Weekly summary',
            'description' => 'Mondays, when the office has it turned on: systems needing attention and figures off target.',
            'email' => true,
            'permission' => 'executive.view',
        ],
    ];

    public static function wantsEmail(object $notifiable, string $kind): bool
    {
        if (! $notifiable instanceof User || blank($notifiable->email)) {
            return false;
        }

        return (bool) ($notifiable->notification_preferences[$kind]['email'] ?? self::KINDS[$kind]['email']);
    }

    /**
     * The kinds this person is offered, with their current choice.
     *
     * @return array<int, array{kind: string, label: string, description: string, email: bool}>
     */
    public static function for(User $user): array
    {
        return collect(self::KINDS)
            ->filter(fn (array $kind) => $kind['permission'] === null || $user->hasPermission($kind['permission']))
            ->map(fn (array $kind, string $key) => [
                'kind' => $key,
                'label' => $kind['label'],
                'description' => $kind['description'],
                'email' => self::wantsEmail($user, $key),
            ])
            ->values()->all();
    }

    /**
     * @param  array<string, bool>  $email  kind → wants email; kinds not offered to this person are ignored
     */
    public static function save(User $user, array $email): void
    {
        $offered = array_column(self::for($user), 'kind');
        $preferences = $user->notification_preferences ?? [];

        foreach ($offered as $kind) {
            if (array_key_exists($kind, $email)) {
                $preferences[$kind] = ['email' => (bool) $email[$kind]];
            }
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();
    }
}
