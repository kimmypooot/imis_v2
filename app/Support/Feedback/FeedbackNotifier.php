<?php

namespace App\Support\Feedback;

use App\Enums\PermissionName;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeedbackUpdate;
use Illuminate\Support\Facades\Notification;

/**
 * The bell notifications around feedback: new feedback goes to those who
 * can read it, a reply goes to the person who sent it.
 */
class FeedbackNotifier
{
    public function received(Feedback $feedback): void
    {
        $sender = $feedback->user;

        $recipients = User::query()->where('status', 'active')
            ->whereHas('activeRoles.permissions', fn ($query) => $query->where('name', PermissionName::FeedbackView->value))
            ->when($sender, fn ($query) => $query->whereKeyNot($sender->id))
            ->get();

        Notification::send($recipients, new FeedbackUpdate(
            'New '.$feedback->type->value,
            ($sender?->name ?? 'Someone').": {$feedback->subject}",
            "/admin/feedback/{$feedback->id}",
            'feedback_received',
        ));
    }

    public function applied(Feedback $feedback): void
    {
        $feedback->user?->notify(new FeedbackUpdate(
            'Your '.$feedback->type->value.' was applied',
            "\"{$feedback->subject}\" has been applied in IMIS. Thank you!",
            "/my/feedback#feedback-{$feedback->id}",
        ));
    }

    public function replied(Feedback $feedback): void
    {
        $feedback->user?->notify(new FeedbackUpdate(
            'Reply to your '.$feedback->type->value,
            "\"{$feedback->subject}\" has a reply.",
            "/my/feedback#feedback-{$feedback->id}",
        ));
    }
}
