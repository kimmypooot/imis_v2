<?php

namespace App\Notifications;

use App\Models\LeaveApplication;
use App\Notifications\Concerns\EmailsWhenWanted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * HR-6b: something happened to a leave application that this person
 * should know or act on (legacy lms_notifications_v2). Shown under the
 * top-bar bell; the message never includes health details.
 */
class LeaveApplicationUpdate extends Notification implements ShouldQueueAfterCommit
{
    use EmailsWhenWanted, Queueable, SerializesModels;

    public const KIND = 'leave';

    public function __construct(
        private readonly LeaveApplication $application,
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
    ) {}

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
