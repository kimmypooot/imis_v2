import { computed, ref } from 'vue';

/**
 * Installing IMIS as an app (public/manifest.webmanifest). Chrome and Edge
 * offer installation through a `beforeinstallprompt` event, fired once,
 * early: listenForInstall() catches it at start-up (app.js) and keeps it
 * for the "Install IMIS app" menu item and the Dashboard card. Safari on an
 * iPhone or iPad has no such event; there people use Share → "Add to Home
 * Screen", which the menu explains instead.
 */
const deferredPrompt = ref(null);
const installed = ref(false);

const isStandalone = () =>
    typeof window !== 'undefined' && (window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true);

const isAppleMobile = () =>
    typeof navigator !== 'undefined' && (/iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1));

let listening = false;

export function listenForInstall() {
    if (listening || typeof window === 'undefined') return;
    listening = true;
    installed.value = isStandalone();

    window.addEventListener('beforeinstallprompt', (event) => {
        // Keep the browser's own mini-bar from showing; IMIS offers it in the menu.
        event.preventDefault();
        deferredPrompt.value = event;
    });

    window.addEventListener('appinstalled', () => {
        installed.value = true;
        deferredPrompt.value = null;
    });
}

export function useInstallApp() {
    /** The browser can install IMIS now (Chrome, Edge). */
    const canInstall = computed(() => !installed.value && deferredPrompt.value !== null);
    /** iPhone or iPad Safari, not yet on the Home Screen: show how instead. */
    const needsHomeScreen = computed(() => !installed.value && deferredPrompt.value === null && isAppleMobile() && !isStandalone());

    /** Opens the browser's install prompt; resolves true if the person installed it. */
    const install = async () => {
        const event = deferredPrompt.value;
        if (!event) return false;
        event.prompt();
        const { outcome } = await event.userChoice;
        // A prompt can only be used once.
        deferredPrompt.value = null;
        if (outcome === 'accepted') installed.value = true;

        return outcome === 'accepted';
    };

    return { canInstall, needsHomeScreen, installed, install };
}
