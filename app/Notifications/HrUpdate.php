<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * HR-6c: a decision on something the person asked HR for (monetization,
 * a flexible work arrangement, a weekly report). Shown under the bell;
 * `url` is always an in-app path.
 */
class HrUpdate extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'hr', 'title' => $this->title, 'message' => $this->message, 'url' => $this->url];
    }
}
