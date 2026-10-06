<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\SystemAccessLog;
use App\Support\Legacy\LegacySso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opening a system through IMIS (legacy api/system-redirect.php): resolves
 * where this user launches into, records the launch (System Access Logs),
 * and sends the browser there through an "Opening <system>" splash page.
 *
 * Not an open redirect: the destination is never taken from the request —
 * it is the admin-configured URL resolved by Application::launchUrlFor(),
 * which already applies visibility, restricted access, and maintenance.
 */
class ApplicationLaunchController extends Controller
{
    public function __invoke(Request $request, Application $application, LegacySso $sso): RedirectResponse|Response
    {
        $user = $request->user();

        abort_unless($application->isVisibleTo($user), 404);

        $application->loadAccessFor($user);
        $url = $application->launchUrlFor($user);

        if ($url === null) {
            return redirect()
                ->route('applications.show', $application)
                ->with('error', $application->under_maintenance
                    ? "{$application->name} is under maintenance right now."
                    : "You don't have access to {$application->name}.");
        }

        SystemAccessLog::query()->forceCreate([
            'user_id' => $user->id,
            'username' => $user->username,
            'application_id' => $application->id,
            'application_name' => $application->name,
            'role_name' => $application->accessFor($user)?->role->name,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 495, '') ?: null,
            'accessed_at' => now(),
        ]);

        // A subsystem also gets a one-time sign-in token (valid for seconds,
        // so it is issued here, right before the page sends the browser on).
        // The page shows the "Opening <system>" splash for a moment first.
        return Inertia::render('Portal/Applications/Launching', [
            'application' => ['name' => $application->name],
            'url' => $sso->launchUrl($user, $application, $url),
        ]);
    }
}
