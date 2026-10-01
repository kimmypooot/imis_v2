<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal announcements. Every query goes through
 * Announcement::scopePublished()/isPublished() — drafts, scheduled,
 * expired, and archived announcements are a 404 here regardless of who
 * asks, including admins (who use the admin preview instead).
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        $announcements = Announcement::query()
            ->published()
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('summary', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('featured')
            ->orderByDesc('publish_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Portal/Announcements/Index', [
            'announcements' => AnnouncementResource::collection($announcements),
            'filters' => $request->only(['search']),
        ]);
    }

    public function show(Announcement $announcement): Response
    {
        abort_unless($announcement->isPublished(), 404);

        $announcement->load('author:id,name');

        return Inertia::render('Portal/Announcements/Show', [
            'announcement' => (new AnnouncementResource($announcement))->withBody(),
        ]);
    }
}
