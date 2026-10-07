<?php

namespace App\Http\Controllers\Account;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Support\Security\SecurityPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Account/Password', [
            'requirements' => SecurityPolicy::passwordRequirements(),
            'expired' => SecurityPolicy::passwordExpired($request->user()),
            'expiryDays' => SecurityPolicy::expiryDays(),
            'setByAdmin' => $request->user()->must_change_password,
        ]);
    }

    /**
     * Changes the signed-in user's own password. The password is hashed by
     * the model cast and never logged. Someone sent here by an expired or
     * administrator-set password goes on to the dashboard.
     */
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $wasExpired = SecurityPolicy::mustChangePassword($user);
        $user->update(['password' => $request->validated('password')]);

        $this->audit()->record(AuditEvent::UserPasswordChanged, "{$user->name} changed their password", $user);

        return ($wasExpired ? redirect()->route('dashboard') : back())
            ->with('status', 'Your password has been changed.');
    }
}
