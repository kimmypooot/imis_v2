<?php

namespace App\Http\Middleware;

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
                    // "Last login" in the account menu — the sign-in before this one.
                    'previous_login_at' => $user->previous_login_at?->toIso8601String(),
                ] : null,
                'roles' => $user
                    ? $user->roles->pluck('name')->values()
                    : [],
                'permissions' => $user
                    ? $user->permissions()->pluck('name')->values()
                    : [],
            ],
            'app' => [
                'version' => config('app.version'),
            ],
            // One-off confirmation messages ("You have been signed out.").
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                // 'portal' | 'admin' right after signing in — drives the splash.
                'signedIn' => fn () => $request->session()->get('signed_in'),
            ],
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
