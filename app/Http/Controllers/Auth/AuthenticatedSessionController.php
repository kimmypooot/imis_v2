<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Rules\Turnstile;
use App\Support\Security\LoginHistoryRecorder;
use App\Support\Security\SessionLimits;
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
            // `?reason=idle` comes from the browser's idle countdown, after
            // the server session has already expired — nothing to flash.
            'status' => $request->session()->get('status')
                ?? ($request->query('reason') === 'idle' ? 'You were signed out after a period of inactivity.' : null),
            'lockoutSeconds' => $request->session()->get('lockout_seconds'),
            // Public by design (it's embedded in the page); the secret
            // never leaves the server.
            'turnstileSiteKey' => Turnstile::enabled() ? config('services.turnstile.site_key') : null,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, LoginHistoryRecorder $history, SessionLimits $limits): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Starts the idle/absolute limits; "Remember me" opts out of them.
        $limits->start($request->session(), $request->boolean('remember'));

        // Login history (legacy imis_history_login_logs), keyed by the
        // regenerated session id so Active Sessions can match it.
        $history->started($request, $request->user());

        // Tells the next page to open with the "Login Successful" splash.
        $request->session()->flash('signed_in', 'portal');

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, LoginHistoryRecorder $history): RedirectResponse
    {
        // Also signs the person out of the systems opened from this session.
        $history->signedOut($request->session()->getId(), $request->user()?->id);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Back to the login screen, as in the legacy IMIS.
        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}
