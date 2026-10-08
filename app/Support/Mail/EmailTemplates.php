<?php

namespace App\Support\Mail;

use App\Models\EmailTemplate;
use App\Support\Settings\Settings;
use Illuminate\Notifications\Messages\MailMessage;
use InvalidArgumentException;

/**
 * Admin-3, Admin → Email Templates: the wording of every email IMIS sends,
 * editable by the office. Each email is a subject, an opening, a button
 * and a closing; text may use the placeholders listed per template,
 * written in braces (`{first_name}`). Paragraphs are separated by a blank
 * line. Plain text only — Laravel's mail layout escapes it.
 *
 * Only edited templates are stored (`email_templates`); deleting one puts
 * the default below back.
 */
final class EmailTemplates
{
    /** Placeholders every template can use. */
    private const COMMON = ['portal_name', 'organization'];

    public const DEFINITIONS = [
        'account_setup' => [
            'label' => 'New account: set your password',
            'description' => 'Sent when an administrator creates an account (one at a time or by import), and to anyone who asks to reset a password before they have ever signed in.',
            'placeholders' => ['first_name', 'name', 'username', 'expires_minutes'],
            'subject' => 'Your {portal_name} account is ready',
            'intro' => "An account has been created for you in {portal_name}, the information system of the {organization}.\n\nYour username is {username}. Choose your password to sign in for the first time.",
            'button' => 'Set your password',
            'outro' => "This link works for {expires_minutes} minutes. If it has expired, use \"Forgot password?\" on the sign-in page.\n\nIf you weren't expecting this account, tell the Human Resource Division.",
        ],
        'password_reset' => [
            'label' => 'Password reset',
            'description' => 'Sent when someone uses "Forgot password?" on the sign-in page.',
            'placeholders' => ['first_name', 'name', 'username', 'expires_minutes'],
            'subject' => 'Reset your {portal_name} password',
            'intro' => 'We received a request to reset the password of your account ({username}).',
            'button' => 'Reset your password',
            'outro' => "This link works for {expires_minutes} minutes.\n\nIf you didn't ask for this, you can ignore this email: your password stays the same.",
        ],
        'notification' => [
            'label' => 'Notification by email',
            'description' => 'The frame around a bell notification someone chose to get by email (Account → Notification settings). The notification\'s own title is the subject and its text comes first.',
            'placeholders' => ['first_name', 'name', 'title'],
            'subject' => '{title}',
            'intro' => '',
            'button' => 'Open in {portal_name}',
            'outro' => 'You get this email because you asked for it in {portal_name} → Notification settings.',
        ],
        'weekly_summary' => [
            'label' => 'Executive Dashboard weekly summary',
            'description' => 'The Monday summary for top management. The list of systems and figures is added after the opening.',
            'placeholders' => ['first_name', 'name'],
            'subject' => '{portal_name} weekly summary',
            'intro' => 'Good morning, {first_name}.',
            'button' => 'Open the Executive Dashboard',
            'outro' => '',
        ],
    ];

    /**
     * A template's current wording: the office's, else the default.
     *
     * @return array{subject: string, intro: string, button: ?string, outro: ?string, edited: bool}
     */
    public static function get(string $key): array
    {
        $definition = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown email template [{$key}].");
        $saved = EmailTemplate::query()->where('key', $key)->first();

        return [
            'subject' => $saved->subject ?? $definition['subject'],
            'intro' => $saved->intro ?? $definition['intro'],
            'button' => $saved ? $saved->button : $definition['button'],
            'outro' => $saved ? $saved->outro : $definition['outro'],
            'edited' => $saved !== null,
        ];
    }

    /**
     * Builds the email. `$before` lines come first (e.g. a notification's
     * own text), `$after` lines follow the opening (e.g. the summary's list).
     *
     * @param  array<string, string|int|null>  $values  placeholder → value
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function mail(string $key, array $values, ?string $url = null, array $before = [], array $after = []): MailMessage
    {
        $template = self::get($key);
        $fill = fn (?string $text) => self::fill((string) $text, $values);
        $mail = (new MailMessage)->subject($fill($template['subject']));

        foreach ([...$before, ...self::paragraphs($fill($template['intro'])), ...$after] as $line) {
            $mail->line($line);
        }

        if ($url && filled($template['button'])) {
            $mail->action($fill($template['button']), $url);
        }

        foreach (self::paragraphs($fill($template['outro'])) as $line) {
            $mail->line($line);
        }

        return $mail->salutation(Settings::get('organization_name'));
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    public static function fill(string $text, array $values): string
    {
        $values = [
            'portal_name' => Settings::get('portal_name'),
            'organization' => Settings::get('organization_name'),
            ...$values,
        ];

        return preg_replace_callback('/\{([a-z_]+)\}/', fn (array $match) => array_key_exists($match[1], $values) ? (string) $values[$match[1]] : $match[0], $text);
    }

    /**
     * Placeholders a template may use.
     *
     * @return array<int, string>
     */
    public static function placeholders(string $key): array
    {
        return [...self::DEFINITIONS[$key]['placeholders'], ...self::COMMON];
    }

    /**
     * @return array<int, string>
     */
    private static function paragraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', trim($text)))));
    }
}
