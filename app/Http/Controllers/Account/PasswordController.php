<?php

namespace App\Http\Controllers\Account;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Account/Password');
    }

    /**
     * Changes the signed-in user's own password. The password is hashed by
     * the model cast and never logged.
     */
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        $this->audit()->record(AuditEvent::UserPasswordChanged, "{$user->name} changed their password", $user);

        return back()->with('status', 'Your password has been changed.');
    }
}
