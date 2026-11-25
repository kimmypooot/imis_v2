<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Send a test" on Notification settings: a push to the person's own
 * devices only, to check they arrive. Never stored under the bell, and
 * sent at once (not queued) so the person sees it while on the page.
 */
class PushTest extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(config('app.name', 'IMIS'))
            ->body('Push notifications work on this device.')
            ->icon('/pwa-icon-192.png')
            ->badge('/pwa-icon-192.png')
            ->data(['url' => '/account/notifications']);
    }
}
