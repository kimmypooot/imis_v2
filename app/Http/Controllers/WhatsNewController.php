<?php

namespace App\Http\Controllers;

use App\Models\Changelog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal side of "What's New": the release history page (the legacy
 * profile-menu link) and dismissing the dashboard pop-up.
 */
class WhatsNewController extends Controller
{
    public function index(Request $request): Response
    {
        $releases = Changelog::query()
            ->published()
            ->with('items')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        // Reading the history counts as having seen the latest release.
        if ($latest = $releases->first()) {
            $this->markSeen($request, $latest);
        }

        return Inertia::render('Portal/WhatsNew', [
            'releases' => $releases->map(fn (Changelog $release) => self::present($release)),
        ]);
    }

    public function seen(Request $request): RedirectResponse
    {
        if ($latest = Changelog::latestPublished()) {
            $this->markSeen($request, $latest);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Changelog $release): array
    {
        return [
            'id' => $release->id,
            'version' => $release->version,
            'title' => $release->title,
            'release_date' => $release->release_date?->toDateString(),
            'items' => $release->items->map(fn ($item) => [
                'type' => $item->type->value,
                'type_label' => $item->type->label(),
                'description' => $item->description,
            ]),
        ];
    }

    private function markSeen(Request $request, Changelog $release): void
    {
        $request->user()->forceFill(['last_seen_changelog_id' => $release->id])->saveQuietly();
    }
}
