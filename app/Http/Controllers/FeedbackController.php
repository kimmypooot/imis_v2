<?php

namespace App\Http\Controllers;

use App\Enums\FeedbackStatus;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Models\Feedback;
use App\Support\Feedback\FeedbackNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sending feedback, suggestions and recommendations from anywhere in IMIS
 * (the dialog in the account menu), and reading your own with their
 * replies. Only your own are ever shown, and private notes never.
 */
class FeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Portal/MyFeedback', [
            'feedback' => $request->user()->feedback()
                ->with(['replies' => fn ($query) => $query->where('is_internal', false)->with('author:id,name')])
                ->latest()->latest('id')->limit(100)->get()
                ->map(fn (Feedback $feedback) => [
                    'id' => $feedback->id,
                    'type' => $feedback->type->value,
                    'type_label' => $feedback->type->label(),
                    'subject' => $feedback->subject,
                    'message' => $feedback->message,
                    'status' => $feedback->status->value,
                    'status_label' => $feedback->status->label(),
                    'created_at' => $feedback->created_at->toIso8601String(),
                    'replies' => $feedback->replies->map(fn ($reply) => [
                        'id' => $reply->id,
                        'body' => $reply->body,
                        'author' => $reply->author?->name ?? 'IMIS administrator',
                        'created_at' => $reply->created_at->toIso8601String(),
                    ]),
                ]),
        ]);
    }

    public function store(StoreFeedbackRequest $request, FeedbackNotifier $notifier): RedirectResponse
    {
        $feedback = new Feedback($request->validated());
        $feedback->forceFill(['user_id' => $request->user()->id, 'status' => FeedbackStatus::New])->save();

        $notifier->received($feedback);

        return back()->with('status', 'Thank you. Your '.$feedback->type->value.' was sent.');
    }
}
