<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin-3, Admin → Email Templates → Send me a test: one email, already
 * built from a template with sample values, marked "[Test]".
 */
class TestEmail extends Notification
{
    public function __construct(private readonly MailMessage $mail) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mail->subject('[Test] '.$this->mail->subject);
    }
}
