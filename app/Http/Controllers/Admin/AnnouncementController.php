<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AnnouncementStatus;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementFeaturedRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementStatusRequest;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Announcement::class);

        $announcements = Announcement::query()
            ->with('author:id,name')
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('summary', 'like', "%{$search}%");
                });
            })
            ->when(
                AnnouncementStatus::tryFrom($request->string('status')->value()),
                fn ($query, $status) => $query->where('status', $status),
            )
            ->when(
                $request->filled('featured'),
                fn ($query) => $query->where('featured', $request->boolean('featured')),
            )
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Announcement $announcement) => $this->present($announcement));

        return Inertia::render('Admin/Announcements/Index', [
            'announcements' => $announcements,
            'filters' => $request->only(['search', 'status', 'featured']),
            'statusOptions' => AnnouncementStatus::options(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Announcement::class);

        return Inertia::render('Admin/Announcements/Create');
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        $announcement = new Announcement($request->announcementData());
        $announcement->slug = Announcement::generateUniqueSlug($announcement->title);
        $announcement->status = AnnouncementStatus::Draft;
        $announcement->author()->associate($request->user());
        $announcement->save();

        $this->audit()->record(
            AuditEvent::AnnouncementCreated,
            "Created announcement draft {$announcement->title}",
            $announcement,
        );

        return to_route('admin.announcements.show', $announcement);
    }

    /**
     * Admin preview: renders the announcement as the portal would, in any
     * status, alongside why it is or isn't currently visible.
     */
    public function show(Announcement $announcement): Response
    {
        $this->authorize('view', $announcement);

        $announcement->load('author:id,name');

        return Inertia::render('Admin/Announcements/Show', [
            'announcement' => $this->present($announcement),
        ]);
    }

    public function edit(Announcement $announcement): Response
    {
        $this->authorize('update', $announcement);

        return Inertia::render('Admin/Announcements/Edit', [
            'announcement' => $this->present($announcement),
        ]);
    }

    /**
     * Content edits never change the slug, so published links stay valid.
     */
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($request->announcementData());

        // `body` changes are recorded as truncated before/after values by
        // the logger — enough to see what changed, not a full revision store.
        $this->audit()->recordChanges(
            AuditEvent::AnnouncementUpdated,
            "Updated announcement {$announcement->title}",
            $announcement,
        );

        return to_route('admin.announcements.show', $announcement);
    }

    public function updateStatus(UpdateAnnouncementStatusRequest $request, Announcement $announcement): RedirectResponse
    {
        $from = $announcement->status;
        $to = $request->enum('status', AnnouncementStatus::class);

        $announcement->transitionTo($to);

        [$event, $verb] = match (true) {
            $to === AnnouncementStatus::Published => [AuditEvent::AnnouncementPublished, 'Published'],
            $to === AnnouncementStatus::Archived => [AuditEvent::AnnouncementArchived, 'Archived'],
            $from === AnnouncementStatus::Archived => [AuditEvent::AnnouncementRestored, 'Restored'],
            default => [AuditEvent::AnnouncementUnpublished, 'Unpublished'],
        };

        $this->audit()->record($event, "{$verb} announcement {$announcement->title}", $announcement, [
            'from' => $from,
            'to' => $to,
            'publish_at' => $announcement->publish_at,
            'expires_at' => $announcement->expires_at,
        ]);

        return back();
    }

    public function updateFeatured(UpdateAnnouncementFeaturedRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update(['featured' => $request->boolean('featured')]);

        if ($announcement->wasChanged('featured')) {
            $this->audit()->record(
                $announcement->featured ? AuditEvent::AnnouncementFeatured : AuditEvent::AnnouncementUnfeatured,
                ($announcement->featured ? 'Featured' : 'Unfeatured')." announcement {$announcement->title}",
                $announcement,
            );
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'slug' => $announcement->slug,
            'summary' => $announcement->summary,
            'body' => $announcement->body,
            'status' => $announcement->status->value,
            'visibility_state' => $announcement->visibilityState(),
            'featured' => $announcement->featured,
            'publish_at' => $announcement->publish_at?->toIso8601String(),
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'author' => $announcement->author?->name,
            'updated_at' => $announcement->updated_at?->toIso8601String(),
        ];
    }
}
