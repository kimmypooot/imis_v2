<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\ChangelogItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Changelog\ChangelogRequest;
use App\Http\Requests\Changelog\UpdateChangelogStatusRequest;
use App\Models\Changelog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Writes and publishes "What's New" release notes. A published release
 * pops up once on each user's dashboard (see PortalController). No hard
 * delete; unpublishing takes a release back down.
 */
class ChangelogController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Changelog::class);

        return Inertia::render('Admin/Changelogs/Index', [
            // Everything the side form edits, per row (legacy layout).
            'changelogs' => Changelog::query()
                ->with('items')
                ->orderByRaw("status = 'published' desc")
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get(['id', 'version', 'title', 'release_date', 'status', 'published_at'])
                ->map(fn (Changelog $changelog) => [
                    ...$changelog->only(['id', 'version', 'title', 'status']),
                    'release_date' => $changelog->release_date?->toDateString(),
                    'published_at' => $changelog->published_at?->toIso8601String(),
                    'items' => $changelog->items->map(fn ($item) => ['type' => $item->type->value, 'description' => $item->description]),
                ]),
            'typeOptions' => ChangelogItemType::options(),
        ]);
    }

    public function store(ChangelogRequest $request): RedirectResponse
    {
        $changelog = DB::transaction(function () use ($request) {
            $changelog = new Changelog($request->release());
            $changelog->forceFill(['created_by' => $request->user()->id])->save();
            $changelog->replaceItems($request->entries());

            return $changelog;
        });

        $this->audit()->record(AuditEvent::ChangelogCreated, "Drafted release {$changelog->version}", $changelog, [
            'entries' => count($request->entries()),
        ]);

        return back()->with('status', "Release {$changelog->version} was saved as a draft.");
    }

    public function update(ChangelogRequest $request, Changelog $changelog): RedirectResponse
    {
        DB::transaction(function () use ($request, $changelog) {
            $changelog->update($request->release());
            $changelog->replaceItems($request->entries());
        });

        $this->audit()->record(AuditEvent::ChangelogUpdated, "Updated release {$changelog->version}", $changelog, [
            'entries' => count($request->entries()),
        ]);

        return back()->with('status', "Release {$changelog->version} was saved.");
    }

    public function updateStatus(UpdateChangelogStatusRequest $request, Changelog $changelog): RedirectResponse
    {
        $publish = $request->validated('status') === Changelog::STATUS_PUBLISHED;

        if ($publish === $changelog->isPublished()) {
            return back();
        }

        $changelog->forceFill([
            'status' => $request->validated('status'),
            // Publish order decides which release is "latest". Someone who
            // already saw this release isn't shown it again on re-publish.
            'published_at' => $publish ? now() : null,
        ])->save();

        $this->audit()->record(
            $publish ? AuditEvent::ChangelogPublished : AuditEvent::ChangelogUnpublished,
            ($publish ? 'Published' : 'Unpublished')." release {$changelog->version}",
            $changelog,
        );

        return back()->with('status', "Release {$changelog->version} was ".($publish ? 'published' : 'unpublished').'.');
    }
}
