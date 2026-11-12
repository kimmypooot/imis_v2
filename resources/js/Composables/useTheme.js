import { ref } from 'vue';

/**
 * Light / dark / follow-the-system appearance. The choice is remembered in
 * this browser (localStorage) and applied as `data-theme` on <html>; the
 * inline script in app.blade.php applies it before first paint so the page
 * never flashes the wrong theme. Light is the default.
 */
const KEY = 'imis-theme';
const choices = ['light', 'dark', 'system'];

const read = () => {
    try {
        const stored = window.localStorage.getItem(KEY);

        return choices.includes(stored) ? stored : 'light';
    } catch {
        return 'light';
    }
};

const theme = ref(typeof window === 'undefined' ? 'light' : read());

const apply = (value) => {
    const dark = value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
};

if (typeof window !== 'undefined') {
    // "System" follows the OS setting live.
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (theme.value === 'system') apply('system');
    });
}

export function useTheme() {
    const setTheme = (value) => {
        if (!choices.includes(value)) return;

        theme.value = value;
        apply(value);

        try {
            window.localStorage.setItem(KEY, value);
        } catch {
            // Storage blocked: the choice still applies until the page reloads.
        }
    };

    return { theme, themes: choices, setTheme };
}
