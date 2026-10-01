<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Rules\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
            'lockoutSeconds' => $request->session()->get('lockout_seconds'),
            // Public by design (it's embedded in the page); the secret
            // never leaves the server.
            'turnstileSiteKey' => Turnstile::enabled() ? config('services.turnstile.site_key') : null,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Tells the next page to open with the "Login Successful" splash.
        $request->session()->flash('signed_in', $request->wantsAdminConsole() ? 'admin' : 'portal');

        // The legacy "Superadmin Login" toggle: land on the admin console.
        // Choosing it never grants access — without it the user simply
        // lands on the dashboard, told why.
        if ($request->wantsAdminConsole()) {
            return $request->user()->can('access-admin')
                ? redirect()->route('admin.index')
                : redirect()->route('dashboard')->with('status', "Your account doesn't have administrator access, so you've been signed in to the portal.");
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Back to the login screen, as in the legacy IMIS.
        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}
