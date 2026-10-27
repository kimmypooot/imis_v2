<?php

namespace App\Http\Middleware;

use App\Enums\FeedbackStatus;
use App\Enums\PermissionName;
use App\Models\Changelog;
use App\Models\Feedback;
use App\Support\Branding\Branding;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Payroll\PayslipApprovers;
use App\Support\Security\SessionLimits;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    // Shown under the name in the header; may be null.
                    'position' => $user->position,
                    // Header avatar; null falls back to initials.
                    'profile_photo_url' => $user->profile_photo_url,
                    // "Last login" in the account menu — the sign-in before this one.
                    'previous_login_at' => $user->previous_login_at?->toIso8601String(),
                ] : null,
                'roles' => $user
                    ? $user->activeRoles->pluck('name')->values()
                    : [],
                'permissions' => $user
                    ? $user->permissions()->pluck('name')->values()
                    : [],
            ],
            'app' => [
                'version' => fn () => Changelog::currentVersion(),
                // Shows the sidebar's Developer group (its routes exist only locally).
                'local' => app()->environment('local'),
            ],
            // Menu entries that depend on more than a permission (navigation `flag`).
            'flags' => fn () => $user ? [
                // HR-6b: a supervisor, HR certifier, the Head or an OIC.
                'leaveApprover' => app(LeaveApplications::class)->isApprover($user),
                // HR-8: the head of HRD, or someone designated head of HRD.
                'payslipApprover' => app(PayslipApprovers::class)->isApprover($user),
            ] : [],
            // Counts shown on sidebar entries (navigation `badge`), only to
            // those who may open the page behind them.
            'badges' => fn () => $user ? [
                'feedback' => $user->hasPermission(PermissionName::FeedbackView->value)
                    ? Feedback::query()->where('status', FeedbackStatus::New)->count()
                    : 0,
            ] : [],
            // The top-bar bell.
            'notifications' => fn () => $user ? ['unread' => $user->unreadNotifications()->count()] : null,
            // Drives the idle/absolute timeout warning; null when exempt.
            'sessionLimits' => fn () => $user ? app(SessionLimits::class)->forClient($request->session()) : null,
            // One-off confirmation messages ("You have been signed out.").
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
                // Set right after signing in — drives the splash.
                'signedIn' => fn () => $request->session()->get('signed_in'),
            ],
            // Admin-3, Admin → Branding: logos, sign-in background, header name (cached).
            'branding' => fn () => Branding::shared(),
            // An explicit whitelist of display settings (one cached read),
            // never the whole settings table.
            'portal' => fn () => [
                'name' => Settings::get('portal_name'),
                'organization' => Settings::get('organization_name'),
                'notice' => $user && Settings::get('portal_notice_enabled')
                    ? Settings::get('portal_notice')
                    : null,
            ],
        ];
    }
}
