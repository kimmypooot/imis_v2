<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\ServiceResource;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\Changelog;
use App\Models\Service;
use App\Support\Portal\SystemDirectory;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal's authenticated home, laid out like the legacy IMIS
 * dashboard: welcome banner and stats bar (real counts only), notices,
 * the user's own systems, the Systems Directory drill-down, then services.
 * It answers "what can I access" and "where do I go" — nothing decorative.
 */
class PortalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Systems the user holds a role in, so they can launch straight
        // into them from home.
        $mySystems = Application::query()
            ->visibleTo($user)
            ->whereHas('accessGrants', fn ($query) => $query->whereBelongsTo($user))
            ->withAccessFor($user)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $services = Service::query()
            ->visibleTo($user)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(6)
            ->get();

        $announcements = Announcement::query()
            ->published()
            ->orderByDesc('featured')
            ->orderByDesc('publish_at')
            ->limit(Settings::get('portal_home_announcement_limit'))
            ->get();

        // The legacy dashboard's stats bar — literal counts over what this
        // user can see, never placeholders. "Accessible" means launchable
        // by them: open systems plus restricted ones they hold a role in.
        $visibleSystems = Application::query()->visibleTo($user);
        $stats = [
            'systems' => (clone $visibleSystems)->count(),
            'accessible' => (clone $visibleSystems)
                ->where(fn ($query) => $query
                    ->where('requires_access', false)
                    ->orWhereHas('accessGrants', fn ($query) => $query->whereBelongsTo($user)))
                ->count(),
            'underMaintenance' => (clone $visibleSystems)->where('under_maintenance', true)->count(),
        ];

        // The legacy "What's New" pop-up: the latest published release,
        // once per user (until they dismiss it or open the history page).
        $latestRelease = Changelog::latestPublished();
        $whatsNew = $latestRelease && $latestRelease->id !== $user->last_seen_changelog_id
            ? WhatsNewController::present($latestRelease->load('items'))
            : null;

        return Inertia::render('Portal/Dashboard', [
            'stats' => $stats,
            // The legacy Category → Division → System drill-down.
            'directory' => fn () => (new SystemDirectory($user, $request))->build(),
            // Which division is open (?division=unit-3). Bookmarkable, and
            // the browser's Back button returns to the directory.
            'division' => $request->string('division')->limit(40, '')->value() ?: null,
            'whatsNew' => $whatsNew,
            'welcomeMessage' => Settings::get('portal_welcome_message'),
            'contact' => [
                'email' => Settings::get('contact_email'),
                'phone' => Settings::get('contact_phone'),
                'website' => Settings::get('contact_website_url'),
            ],
            'announcements' => AnnouncementResource::collection($announcements),
            'mySystems' => ApplicationResource::collection($mySystems),
            // Every system the user can see that's down right now, so
            // nobody has to discover it by trying to open it.
            'maintenanceNotices' => Application::query()
                ->visibleTo($user)
                ->where('under_maintenance', true)
                ->orderBy('maintenance_started_at')
                ->get(['id', 'name', 'maintenance_message', 'maintenance_started_at'])
                ->map(fn (Application $application) => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'message' => $application->maintenance_message,
                    'since' => $application->maintenance_started_at?->toIso8601String(),
                ]),
            'services' => ServiceResource::collection($services),
        ]);
    }
}
