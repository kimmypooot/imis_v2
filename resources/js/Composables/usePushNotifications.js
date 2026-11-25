import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Push notifications on this device (Account → Notification settings).
 * Registers the service worker (/sw.js), asks the browser for permission,
 * subscribes with the server's VAPID key and hands the subscription to
 * IMIS. getPush() returns the page's `push` prop; its `endpoints` are the person's own devices, so a browser someone
 * else switched on shows as off for them.
 *
 * state: 'loading' | 'unsupported' | 'needs-install' | 'blocked' | 'off' | 'on'
 */
export function usePushNotifications(getPush) {
    const state = ref('loading');
    const busy = ref(false);
    const failure = ref(null);

    const supported = () => typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    // iPhone and iPad: push only works once IMIS is added to the Home Screen.
    const isAppleMobile = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isInstalled = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;

    const registration = () => navigator.serviceWorker.register('/sw.js').then(() => navigator.serviceWorker.ready);

    const refresh = async () => {
        if (!supported()) {
            state.value = isAppleMobile() && !isInstalled() ? 'needs-install' : 'unsupported';
            return;
        }
        if (Notification.permission === 'denied') {
            state.value = 'blocked';
            return;
        }

        const existing = await navigator.serviceWorker.getRegistration('/');
        const subscription = existing ? await existing.pushManager.getSubscription() : null;
        state.value = subscription && getPush().endpoints.includes(subscription.endpoint) ? 'on' : 'off';
    };

    const keyBytes = (base64Url) => {
        const base64 = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
    };

    const enable = async () => {
        busy.value = true;
        failure.value = null;
        try {
            if ((await Notification.requestPermission()) !== 'granted') {
                state.value = Notification.permission === 'denied' ? 'blocked' : 'off';
                return;
            }

            const ready = await registration();
            const subscription =
                (await ready.pushManager.getSubscription()) ?? (await ready.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(getPush().publicKey) }));
            const json = subscription.toJSON();
            const encoding = (PushManager.supportedContentEncodings ?? ['aes128gcm']).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';

            router.post('/account/push-subscriptions', { endpoint: json.endpoint, keys: json.keys, contentEncoding: encoding }, { preserveScroll: true, onSuccess: () => refresh(), onFinish: () => (busy.value = false) });
            return;
        } catch {
            failure.value = 'This browser could not switch push notifications on. Try again, or use another browser.';
        }
        busy.value = false;
    };

    const disable = async () => {
        busy.value = true;
        failure.value = null;
        const existing = await navigator.serviceWorker.getRegistration('/');
        const subscription = existing ? await existing.pushManager.getSubscription() : null;
        if (!subscription) {
            busy.value = false;
            state.value = 'off';
            return;
        }

        const endpoint = subscription.endpoint;
        await subscription.unsubscribe().catch(() => {});
        router.delete('/account/push-subscriptions', { data: { endpoint }, preserveScroll: true, onSuccess: () => refresh(), onFinish: () => (busy.value = false) });
    };

    const sendTest = () => {
        busy.value = true;
        router.post('/account/push-subscriptions/test', {}, { preserveScroll: true, onSuccess: () => refresh(), onFinish: () => (busy.value = false) });
    };

    onMounted(() => refresh().catch(() => (state.value = 'unsupported')));

    return {
        state,
        busy,
        failure,
        isOn: computed(() => state.value === 'on'),
        deviceCount: computed(() => getPush().endpoints.length),
        refresh,
        enable,
        disable,
        sendTest,
    };
}
