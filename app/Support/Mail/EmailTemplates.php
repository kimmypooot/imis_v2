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
            'greeting' => 'Welcome, {first_name}!',
            'subject' => 'Welcome to {portal_name}: your account is ready',
            'intro' => "Your account in {portal_name}, the integrated management information system of the {organization}, has been created and is ready for you.\n\nYour username is {username}. To get started, please choose a password using the button below. Once you are signed in, the systems and services you have been given access to are all in one place.",
            'button' => 'Set your password',
            'outro' => "For your security, this link is valid for {expires_minutes} minutes and can be used only once. If it has expired, select \"Forgot password?\" on the sign-in page and we will send you a new one.\n\nWere you not expecting this account? Please let the Human Resource Division know so we can look into it.",
        ],
        'password_reset' => [
            'label' => 'Password reset',
            'description' => 'Sent when someone uses "Forgot password?" on the sign-in page.',
            'placeholders' => ['first_name', 'name', 'username', 'expires_minutes'],
            'greeting' => 'Hello, {first_name}',
            'subject' => 'Reset your {portal_name} password',
            'intro' => "We received a request to reset the password for your account ({username}).\n\nSelect the button below to choose a new password. It only takes a moment.",
            'button' => 'Reset your password',
            'outro' => "This link is valid for {expires_minutes} minutes and can be used only once.\n\nDidn't make this request? You can safely ignore this email, and your password will stay exactly as it is. If you are worried about the security of your account, please contact the Human Resource Division.",
        ],
        'notification' => [
            'label' => 'Notification by email',
            'description' => 'The frame around a bell notification someone chose to get by email (Account → Notification settings). The notification\'s own title is the subject and its text comes first.',
            'placeholders' => ['first_name', 'name', 'title'],
            'greeting' => 'Hello, {first_name}',
            'subject' => '{title}',
            'intro' => '',
            'button' => 'View details in {portal_name}',
            'outro' => 'You are receiving this email because you chose to be notified by email in {portal_name}. You can change this at any time under Account → Notification settings.',
        ],
        'weekly_summary' => [
            'label' => 'Executive Dashboard weekly summary',
            'description' => 'The Monday summary for top management. The list of systems and figures is added after the opening.',
            'placeholders' => ['first_name', 'name'],
            'greeting' => 'Your weekly summary',
            'subject' => '{portal_name} weekly summary',
            'intro' => 'Good morning, {first_name}. Here is a quick look at how the systems and targets you follow in {portal_name} are doing this week.',
            'button' => 'Open the Executive Dashboard',
            'outro' => 'Open the Executive Dashboard for the full picture, including trends, details and targets.',
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
     * @param  array<string, string|int|null>  $details  label → value rows, shown as a panel after the opening
     * @param  array<string, ?string>  $override  wording to use instead of the saved one (a draft being previewed)
     */
    public static function mail(string $key, array $values, ?string $url = null, array $before = [], array $after = [], array $details = [], array $override = []): MailMessage
    {
        $template = [...self::get($key), ...$override];
        $fill = fn (?string $text) => self::fill((string) $text, $values);
        $mail = (new MailMessage)
            ->subject($fill($template['subject']))
            ->greeting($fill(self::DEFINITIONS[$key]['greeting'] ?? 'Hello, {first_name}'));

        $details = array_filter($details, fn (mixed $value) => filled($value));
        if ($details !== []) {
            $mail->viewData['details'] = array_map(fn (mixed $value) => self::fill((string) $value, $values), $details);
        }

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
