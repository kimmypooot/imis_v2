<?php

namespace App\Notifications;

use App\Notifications\Concerns\EmailsWhenWanted;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Notifications\Notification;

/**
 * Something about feedback: a reply to the person who sent it (kind
 * `feedback`), or new feedback arriving for those who manage it (kind
 * `feedback_received`). Shown under the bell; `url` is an in-app path.
 */
class FeedbackUpdate extends Notification
{
    use EmailsWhenWanted;

    public const KIND = 'feedback';

    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
        private readonly string $kind = self::KIND,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::wantsEmail($notifiable, $this->kind) ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'message' => $this->message, 'url' => $this->url];
    }
}
