<?php

namespace App\Notifications;

use App\Notifications\Concerns\EmailsWhenWanted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * HR-4: something on the plantilla needs HR (plantilla.manage), e.g. a
 * holder whose account title no longer matches their item. `keys` names
 * what was reported, so the same thing is never reported twice.
 */
class PlantillaAlert extends Notification implements ShouldQueueAfterCommit
{
    use EmailsWhenWanted, Queueable;

    public const KIND = 'plantilla';

    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
        private readonly array $keys = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => self::KIND, 'title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'keys' => $this->keys];
    }
}
