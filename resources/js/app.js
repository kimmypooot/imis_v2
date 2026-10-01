import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import TransitionSplash from './Components/Feedback/TransitionSplash.vue';

const appName = import.meta.env.VITE_APP_NAME || 'CSC RO VIII IMIS';

/**
 * Fades out the boot preloader in app.blade.php once Vue has rendered the
 * first page — it covers only the initial download, never adds a delay.
 */
const dismissBootPreloader = () => {
    const preloader = document.getElementById('boot-preloader');

    if (!preloader) return;

    preloader.classList.add('is-hidden');
    preloader.addEventListener('transitionend', () => preloader.remove(), { once: true });
    window.setTimeout(() => preloader.remove(), 800);
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
        createApp({ render: () => [h(App, props), h(TransitionSplash)] })
            .use(plugin)
            .mount(el);

        dismissBootPreloader();
    },
});
