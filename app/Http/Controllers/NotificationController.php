<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Your notifications (the top-bar bell): HR-6b leave updates first. Only
 * your own; opening one marks it read and takes you to what it's about.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Portal/Notifications', [
            'notifications' => $request->user()->notifications()->latest()->limit(100)->get()
                ->map(fn (DatabaseNotification $notification) => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'Notification',
                    'message' => $notification->data['message'] ?? '',
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = (string) ($notification->data['url'] ?? '/notifications');

        // Only ever an in-app path, never an outside address.
        return redirect(str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : '/notifications');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', 'All notifications marked as read.');
    }
}
