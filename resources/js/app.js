import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import TransitionSplash from './Components/Feedback/TransitionSplash.vue';
import DialogHost from './Components/Feedback/DialogHost.vue';

const appName = import.meta.env.VITE_APP_NAME || 'CSC RO VIII IMIS';

/**
 * Fades out the boot preloader in app.blade.php once Vue has rendered the
 * first page. Like the legacy preloader.js, it holds for a short beat so
 * the loading screen reads as a deliberate hand-off rather than a flicker.
 */
const dismissBootPreloader = () => {
    const preloader = document.getElementById('boot-preloader');

    if (!preloader) return;

    // The launch splash is drawn by the server and re-drawn identically by the
    // page (Launching.vue): no hold, no fade.
    if (preloader.hasAttribute('data-instant')) {
        window.requestAnimationFrame(() => preloader.remove());
        return;
    }

    window.setTimeout(() => {
        preloader.classList.add('is-hidden');
        preloader.setAttribute('aria-busy', 'false');
        preloader.addEventListener('transitionend', (event) => event.target === preloader && preloader.remove());
        // Fallback in case transitionend never fires.
        window.setTimeout(() => preloader.remove(), 1200);
    }, 300);
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
