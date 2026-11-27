<?php

namespace App\Notifications;

use App\Notifications\Concerns\EmailsWhenWanted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * Calendar of Activities: PALD returned or removed an activity an office
 * posted. Shown under the bell; `url` is always an in-app path.
 */
class ActivityUpdate extends Notification implements ShouldQueueAfterCommit
{
    use EmailsWhenWanted, Queueable;

    public const KIND = 'activities';

    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => self::KIND, 'title' => $this->title, 'message' => $this->message, 'url' => $this->url];
    }
}
