<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Exec-3: something on the Executive Dashboard needs top management's
 * attention — a figure fell behind its target, or a system's figures
 * stopped arriving (or came back). Shown under the bell; totals only.
 */
class ExecutiveAlert extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly string $message,
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
        return ['kind' => 'executive', 'title' => $this->title, 'message' => $this->message, 'url' => '/executive'];
    }
}
