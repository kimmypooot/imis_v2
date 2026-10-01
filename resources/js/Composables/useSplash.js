import { reactive, readonly } from 'vue';

/**
 * The branded full-screen splash shown between signing in/out and the next
 * page — the legacy IMISTransition, rebuilt. State is module-level and the
 * splash is rendered once at the app root (app.js), so it stays on screen
 * while Inertia swaps the login page for the dashboard (or back).
 */
const state = reactive({
    visible: false,
    title: '',
    subtitle: '',
    name: '',
    note: '',
});

let hideTimer = null;

const show = ({ title, subtitle = '', name = '', note = '' }) => {
    window.clearTimeout(hideTimer);
    Object.assign(state, { visible: true, title, subtitle, name, note });
};

const hide = (delay = 0) => {
    window.clearTimeout(hideTimer);
    hideTimer = window.setTimeout(() => (state.visible = false), delay);
};

export const firstName = (name) => (name || '').trim().split(/\s+/)[0] || '';

export function useSplash() {
    return { splash: readonly(state), show, hide };
}
