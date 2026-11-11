<?php

namespace App\Notifications\Concerns;

use App\Support\Mail\EmailTemplates;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A bell notification that is also emailed to those who asked for its
 * kind (NotificationPreferences, Account → Notification settings). The
 * email says what the bell says, with a button to the same page.
 * The class sets KIND and builds its bell entry in toArray().
 */
trait EmailsWhenWanted
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::wantsEmail($notifiable, static::KIND) ? ['database', 'mail'] : ['database'];
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
