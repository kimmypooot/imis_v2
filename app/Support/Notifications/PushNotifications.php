<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Support\Settings\Settings;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Push notifications (Web Push) to the devices a person switched on in
 * Account → Notification settings. Every bell notification is also pushed
 * to those devices. The text is deliberately generic ("a new Leave
 * notification"): it shows on lock screens and shared computers, so the
 * details stay in IMIS behind sign-in. Needs VAPID keys in .env
 * (`php artisan webpush:vapid`), HTTPS (localhost is exempt) and the
 * Super Administrator's "Allow push notifications" (System Settings).
 */
final class PushNotifications
{
    /** The server has VAPID keys. */
    public static function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /** Configured, and allowed by the Super Administrator (System Settings → Notifications). */
    public static function isEnabled(): bool
    {
        return self::isConfigured() && (bool) Settings::get('push_notifications_enabled');
    }

    /**
     * The push channel, for an active account with at least one device.
     *
     * @return array<int, string>
     */
    public static function channelsFor(object $notifiable): array
    {
        if (! self::isEnabled() || ! $notifiable instanceof User || ! $notifiable->isActive()) {
            return [];
        }

        return $notifiable->pushSubscriptions()->exists() ? [WebPushChannel::class] : [];
    }

    /**
     * @param  array<string, mixed>  $entry  the bell entry (kind, title, message, url)
     */
    public static function message(array $entry): WebPushMessage
    {
        $label = NotificationPreferences::KINDS[$entry['kind'] ?? '']['label'] ?? null;

        return (new WebPushMessage)
            ->title(config('app.name', 'IMIS'))
            ->body($label ? "You have a new {$label} notification. Open IMIS to read it." : 'You have a new notification. Open IMIS to read it.')
            ->icon('/pwa-icon-192.png')
            ->badge('/pwa-icon-192.png')
            ->data(['url' => self::path((string) ($entry['url'] ?? ''))]);
    }

    /**
     * Only the push services of the major browsers are accepted as
     * endpoints: the server sends to whatever address is stored, so an
     * arbitrary one would let anyone make it call internal addresses.
     */
    public static function allowsEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || blank($host = strtolower($parts['host'] ?? ''))) {
            return false;
        }

        foreach (config('webpush.allowed_hosts', []) as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** An in-app path, never another site. */
    private static function path(string $url): string
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : '/notifications';
    }
}
