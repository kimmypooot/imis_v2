<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Notifications\PushTest;
use App\Support\Notifications\PushNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Account → Notification settings: a device switching push notifications
 * on or off, and "Send a test". Your own devices only; no permission
 * needed. A browser shared by two people belongs to whoever switched it
 * on last (HasPushSubscriptions moves the endpoint).
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(PushNotifications::isEnabled(), 404);

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:500', function (string $attribute, string $value, \Closure $fail) {
                if (! PushNotifications::allowsEndpoint($value)) {
                    $fail('This browser\'s push service is not supported.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        $request->user()->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['contentEncoding'] ?? 'aes128gcm');

        return back()->with('status', 'Push notifications are on for this device.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return back()->with('status', 'Push notifications are off for this device.');
    }

    public function test(Request $request): RedirectResponse
    {
        abort_unless(PushNotifications::isEnabled(), 404);

        if (! $request->user()->pushSubscriptions()->exists()) {
            return back()->with('error', 'No device has push notifications on yet.');
        }

        $request->user()->notifyNow(new PushTest);

        return back()->with('status', 'Test sent. It should appear on your devices in a few seconds.');
    }
}
