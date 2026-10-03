<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\SystemAccessLog;
use App\Support\Legacy\LegacySso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Opening a system through IMIS (legacy api/system-redirect.php): resolves
 * where this user launches into, records the launch (System Access Logs),
 * and redirects there.
 *
 * Not an open redirect: the destination is never taken from the request —
 * it is the admin-configured URL resolved by Application::launchUrlFor(),
 * which already applies visibility, restricted access, and maintenance.
 */
class ApplicationLaunchController extends Controller
{
    public function __invoke(Request $request, Application $application, LegacySso $sso): RedirectResponse
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

        // A legacy subsystem also gets a one-time sign-in token.
        return redirect()->away($sso->launchUrl($user, $application, $url));
    }
}
