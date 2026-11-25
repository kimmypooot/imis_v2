<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\PushNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-3, Account → Notification settings: everyone chooses, per kind,
 * whether they also want an email (NotificationPreferences). Their own
 * settings only; no permission needed.
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Account/Notifications', [
            'kinds' => NotificationPreferences::for($request->user()),
            'email' => $request->user()->email,
            // Null unless push is allowed (VAPID keys, System Settings): the page then hides it.
            'push' => PushNotifications::isEnabled() ? [
                'publicKey' => config('webpush.vapid.public_key'),
                // To tell whether this browser's subscription is theirs.
                'endpoints' => $request->user()->pushSubscriptions()->pluck('endpoint')->all(),
            ] : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'array'],
            'email.*' => ['boolean'],
        ]);

        NotificationPreferences::save($request->user(), $data['email']);

        return back()->with('status', 'Notification settings saved.');
    }
}
