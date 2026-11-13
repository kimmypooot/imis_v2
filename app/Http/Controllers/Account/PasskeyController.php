<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Security\SecurityPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account → Fingerprint & passkeys: the devices a person can sign in with
 * instead of a password (fingerprint, face or screen-lock PIN on a phone or
 * laptop). Adding and removing a device goes through the package's routes
 * (laravel/passkeys), which require a recently confirmed password; this page
 * lists the devices and takes that confirmation.
 */
class PasskeyController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Account/Passkeys', [
            'passkeys' => $request->user()->passkeys()->latest()->get()->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ])->values(),
            'passwordConfirmed' => $this->passwordConfirmed($request),
            'enabled' => SecurityPolicy::passkeysEnabled(),
        ]);
    }

    /**
     * Confirms the signed-in person's password so a device can be added or
     * removed for the next few hours (auth.password_timeout), the same
     * window Laravel's `password.confirm` middleware checks.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($request->string('current_password')->value(), $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'That password is not correct.']);
        }

        $request->session()->passwordConfirmed();

        return back();
    }

    private function passwordConfirmed(Request $request): bool
    {
        return time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout');
    }
}
