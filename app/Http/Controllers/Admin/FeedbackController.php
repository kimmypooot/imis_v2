<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\StoreFeedbackReplyRequest;
use App\Http\Requests\Feedback\UpdateFeedbackStatusRequest;
use App\Models\Feedback;
use App\Support\Feedback\FeedbackNotifier;
use App\Support\Tables\ServerTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reviewing what users sent in: filter, read, reply (the sender is
 * notified), keep private notes, and move each one along. Nothing is
 * deleted — closing it is the way to put it away.
 */
class FeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Feedback::class);

        $query = Feedback::query()
            ->with('user:id,name')
            ->withCount(['replies as replies_count' => fn ($query) => $query->where('is_internal', false)])
            ->when(FeedbackStatus::tryFrom($request->string('status')->value()), fn ($query, $status) => $query->where('feedback.status', $status))
            ->when(FeedbackType::tryFrom($request->string('type')->value()), fn ($query, $type) => $query->where('feedback.type', $type))
            ->leftJoin('users as senders', 'senders.id', '=', 'feedback.user_id')
            ->select('feedback.*');

        ServerTable::apply(
            $query,
            $request,
            ['feedback.subject', 'feedback.message', 'senders.name'],
            ['subject' => 'feedback.subject', 'created_at' => 'feedback.created_at', 'status' => 'feedback.status', 'type' => 'feedback.type'],
            'feedback.created_at',
        );

        return Inertia::render('Admin/Feedback/Index', [
            'feedback' => ServerTable::paginate($query, $request)->through(fn (Feedback $feedback) => [
                'id' => $feedback->id,
                'type' => $feedback->type->value,
                'type_label' => $feedback->type->label(),
                'subject' => $feedback->subject,
                'sender' => $feedback->user?->name ?? 'Former user',
                'status' => $feedback->status->value,
                'status_label' => $feedback->status->label(),
                'replies_count' => $feedback->replies_count,
                'created_at' => $feedback->created_at->toIso8601String(),
            ]),
            'filters' => ServerTable::query($request, ['status', 'type']),
            'statusOptions' => FeedbackStatus::options(),
            'typeOptions' => FeedbackType::options(),
            'counts' => Feedback::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(Feedback $feedback): Response
    {
        $this->authorize('view', $feedback);

        $feedback->load(['user:id,name,username,email', 'handler:id,name', 'replies.author:id,name']);

        return Inertia::render('Admin/Feedback/Show', [
            'feedback' => [
                'id' => $feedback->id,
                'type_label' => $feedback->type->label(),
                'subject' => $feedback->subject,
                'message' => $feedback->message,
                'page_path' => $feedback->page_path,
                'status' => $feedback->status->value,
                'status_label' => $feedback->status->label(),
                'sender' => $feedback->user ? $feedback->user->only(['name', 'username', 'email']) : null,
                'handled_by' => $feedback->handler?->name,
                'created_at' => $feedback->created_at->toIso8601String(),
                'replies' => $feedback->replies->map(fn ($reply) => [
                    'id' => $reply->id,
                    'body' => $reply->body,
                    'is_internal' => $reply->is_internal,
                    'author' => $reply->author?->name ?? 'Former user',
                    'created_at' => $reply->created_at->toIso8601String(),
                ]),
            ],
            'statusOptions' => FeedbackStatus::options(),
        ]);
    }

    public function reply(StoreFeedbackReplyRequest $request, Feedback $feedback, FeedbackNotifier $notifier): RedirectResponse
    {
        $internal = $request->boolean('is_internal');

        $reply = $feedback->replies()->make(['body' => $request->validated('body'), 'is_internal' => $internal]);
        $reply->forceFill(['user_id' => $request->user()->id])->save();

        // Answering something nobody has looked at yet puts it under review.
        $feedback->forceFill([
            'handled_by' => $request->user()->id,
            ...($feedback->status === FeedbackStatus::New && ! $internal
                ? ['status' => FeedbackStatus::InReview, 'status_changed_at' => now()]
                : []),
        ])->save();

        $this->audit()->record(
            $internal ? AuditEvent::FeedbackNoted : AuditEvent::FeedbackReplied,
            ($internal ? 'Added a private note to' : 'Replied to')." feedback \"{$feedback->subject}\"",
            $feedback,
        );

        if (! $internal) {
            $notifier->replied($feedback);
        }

        return back()->with('status', $internal ? 'Note saved. Only administrators can see it.' : 'Reply sent.');
    }

    public function updateStatus(UpdateFeedbackStatusRequest $request, Feedback $feedback): RedirectResponse
    {
        $status = FeedbackStatus::from($request->validated('status'));

        if ($status === $feedback->status) {
            return back();
        }

        $previous = $feedback->status;
        $feedback->forceFill([
            'status' => $status,
            'status_changed_at' => now(),
            'handled_by' => $request->user()->id,
        ])->save();

        $this->audit()->record(
            AuditEvent::FeedbackStatusChanged,
            "Marked feedback \"{$feedback->subject}\" as {$status->label()}",
            $feedback,
            ['from' => $previous->value, 'to' => $status->value],
        );

        return back()->with('status', "Marked as {$status->label()}.");
    }
}
