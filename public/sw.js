/*
 * IMIS service worker: push notifications only. It caches nothing and
 * handles no requests, so pages load exactly as without it.
 * The payload comes from App\Support\Notifications\PushNotifications.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = {};
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'IMIS', {
            body: payload.body || 'You have a new notification. Open IMIS to read it.',
            icon: payload.icon || '/pwa-icon-192.png',
            badge: payload.badge || '/pwa-icon-192.png',
            data: payload.data || {},
        }),
    );
});

// Opens the notification's page in IMIS: an open IMIS tab if there is one.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const path = event.notification.data?.url;
    const target = new URL(typeof path === 'string' && path.startsWith('/') && !path.startsWith('//') ? path : '/notifications', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (windows) => {
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
            if (open) {
                await open.focus();
                try {
                    return await open.navigate(target);
                } catch {
                    // A tab the worker doesn't control can't be navigated: open a new one.
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
