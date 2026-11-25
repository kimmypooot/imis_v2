<?php

namespace App\Notifications\Concerns;

use App\Support\Mail\EmailTemplates;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\PushNotifications;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A bell notification that is also emailed to those who asked for its
 * kind (NotificationPreferences, Account → Notification settings), and
 * pushed to the devices they switched on there (PushNotifications). The
 * email says what the bell says, with a button to the same page; the push
 * only says a notification arrived. The class sets KIND and builds its
 * bell entry in toArray().
 */
trait EmailsWhenWanted
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, static::KIND);
    }

    /**
     * @return array<int, string>
     */
    protected function channelsFor(object $notifiable, string $kind): array
    {
        return [
            'database',
            ...(NotificationPreferences::wantsEmail($notifiable, $kind) ? ['mail'] : []),
            ...PushNotifications::channelsFor($notifiable),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return PushNotifications::message($this->toArray($notifiable));
    }

    /** In the office's "Notification by email" wording (Admin → Email Templates). */
    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->toArray($notifiable);

        return EmailTemplates::mail('notification', [
            'first_name' => $notifiable->first_name ?: $notifiable->name,
            'name' => $notifiable->name,
            'title' => $entry['title'],
        ], url($entry['url']), before: [$entry['message']], details: [
            'Received' => now()->timezone(config('app.timezone'))->format('F j, Y, g:i A'),
        ]);
    }
}
