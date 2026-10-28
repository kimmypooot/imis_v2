import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import TransitionSplash from './Components/Feedback/TransitionSplash.vue';
import DialogHost from './Components/Feedback/DialogHost.vue';

const appName = import.meta.env.VITE_APP_NAME || 'CSC RO VIII IMIS';

// Boot preloader timing. SHOW_DELAY must match the fade-in delay in
// resources/css/boot.css (.imis-preloader).
const SHOW_DELAY = 120;
const MIN_VISIBLE = 400;
const FONT_WAIT = 1500;

/**
 * Removes the boot preloader in app.blade.php once Vue has rendered the first
 * page and the fonts are in (so text doesn't swap under the fade).
 *
 * The screen fades in only after SHOW_DELAY, so a page that is ready first
 * never shows it: it is just removed. Once it has shown, it stays for
 * MIN_VISIBLE, so it reads as a deliberate hand-off rather than a flicker.
 */
const dismissBootPreloader = async () => {
    const preloader = document.getElementById('boot-preloader');

    if (!preloader) return;

    // The launch splash is drawn by the server and re-drawn identically by the
    // page (Launching.vue): no hold, no fade.
    if (preloader.hasAttribute('data-instant')) {
        window.requestAnimationFrame(() => preloader.remove());
        return;
    }

    await Promise.race([document.fonts?.ready, new Promise((resolve) => window.setTimeout(resolve, FONT_WAIT))]);

    const shownFor = performance.now() - (window.__bootAt ?? 0) - SHOW_DELAY;

    if (shownFor < 0) {
        preloader.remove();
        return;
    }

    window.setTimeout(() => {
        preloader.classList.add('is-hidden');
        preloader.setAttribute('aria-busy', 'false');
        preloader.addEventListener('transitionend', (event) => event.target === preloader && preloader.remove());
        // Fallback in case transitionend never fires.
        window.setTimeout(() => preloader.remove(), 1000);
    }, Math.max(0, MIN_VISIBLE - shownFor));
};

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Navigation progress bar in the brand accent (a design token, not a
    // hard-coded hex), shown only for visits slower than 250ms.
    progress: { color: 'var(--color-accent)', delay: 250 },
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // The sign-in/sign-out splash lives beside the page component so it
        // survives Inertia swapping one page (and layout) for the next.
        createApp({ render: () => [h(App, props), h(TransitionSplash), h(DialogHost)] })
            .use(plugin)
            .mount(el);

        dismissBootPreloader();
    },
});

/**
 * Back/Forward restores a page from the browser's history snapshot, so a
 * list shows the data it had when you left it — an employee you just
 * separated still reads "In Service". Inertia fires `navigate` without a
 * visit id only for that restore, so refresh the page's data then. Scroll
 * position and the page's own state (tabs, filters) are kept.
 */
router.on('navigate', (event) => {
    if (event.detail.visitId === undefined) {
        router.reload({ preserveScroll: true });
    }
});
