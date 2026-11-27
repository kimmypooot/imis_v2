<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\ServiceResource;
use App\Models\Activity;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\Changelog;
use App\Models\Service;
use App\Support\Activities\UpcomingActivities;
use App\Support\Portal\AtAGlance;
use App\Support\Portal\GettingStarted;
use App\Support\Portal\NeedsAttention;
use App\Support\Portal\QuickAccess;
use App\Support\Portal\SystemDirectory;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal's authenticated home, laid out as a command center: the
 * welcome banner and the At a glance stat strip; "Needs your attention"
 * and the Systems Directory drill-down; and a side column with what's
 * coming up, announcements, system status and services. It answers "what
 * is waiting on me", "what can I access" and "where do I go".
 */
class PortalController extends Controller
{
    public function index(Request $request, AtAGlance $glance, GettingStarted $gettingStarted, QuickAccess $quickAccess, UpcomingActivities $upcoming, NeedsAttention $attention): Response
    {
        $user = $request->user();

        $services = Service::query()
            ->visibleTo($user)
            ->when(! Settings::get('portal_services_enabled'), fn ($query) => $query->whereRaw('1 = 0'))
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

        // The legacy "What's New" pop-up: the latest published release,
        // once per user (until they dismiss it or open the history page).
        $latestRelease = Changelog::latestPublished();
        $whatsNew = $latestRelease && $latestRelease->id !== $user->last_seen_changelog_id
            ? WhatsNewController::present($latestRelease->load('items'))
            : null;

        return Inertia::render('Portal/Dashboard', [
            // The legacy Category → Division → System drill-down.
            'directory' => fn () => (new SystemDirectory($user, $request))->build(),
            // Which division is open (?division=unit-3). Bookmarkable, and
            // the browser's Back button returns to the directory.
            'division' => $request->string('division')->limit(40, '')->value() ?: null,
            // The personal "At a glance" row, loaded after the page appears.
            'glance' => Inertia::defer(fn () => $glance->for($user)),
            // What is waiting on this person, each with the page that settles it.
            'attention' => Inertia::defer(fn () => $attention->for($user)),
            // The banner's round shortcuts (only the pages the user can open).
            'shortcuts' => fn () => $quickAccess->for($user),
            // The Calendar of Activities section: the next 7 days and a month
            // for the mini calendar (?month=YYYY-MM from its ‹ › partial reloads).
            'activities' => Inertia::defer(fn () => $user->can('viewAny', Activity::class)
                ? $upcoming->for($user, null, $request->string('month')->limit(7, '')->value() ?: null)
                : null),
            // One-time setup still to do (signature, PDS, fingerprint); null once done.
            'gettingStarted' => fn () => $gettingStarted->for($user),
            'whatsNew' => $whatsNew,
            'welcomeMessage' => Settings::get('portal_welcome_message'),
            // The legacy greeting's division badge (the user's fo_rsu).
            'userDivision' => $user->organizationalUnit?->name ?: $user->organizationalUnit?->short_name,
            'contact' => [
                'email' => Settings::get('contact_email'),
                'phone' => Settings::get('contact_phone'),
                'website' => Settings::get('contact_website_url'),
            ],
            'announcements' => AnnouncementResource::collection($announcements),
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
