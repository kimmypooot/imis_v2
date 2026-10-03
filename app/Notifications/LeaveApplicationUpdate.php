<?php

namespace App\Notifications;

use App\Models\LeaveApplication;
use Illuminate\Notifications\Notification;

/**
 * HR-6b: something happened to a leave application that this person
 * should know or act on (legacy lms_notifications_v2). Shown under the
 * top-bar bell; the message never includes health details.
 */
class LeaveApplicationUpdate extends Notification
{
    public function __construct(
        private readonly LeaveApplication $application,
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
        return [
            'kind' => 'leave',
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'leave_application_id' => $this->application->id,
        ];
    }
}
