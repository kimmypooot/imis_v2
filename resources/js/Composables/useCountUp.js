import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Eases a figure from its previous value up to the new one, so a number
 * that arrives or changes is noticed. Only plain numbers (or digit strings
 * such as "1,284") count up; anything else ("85%", "—") is shown as given.
 * Skipped entirely when the person prefers reduced motion. Returns the text
 * to display.
 */
export function useCountUp(source, { duration = 700 } = {}) {
    const parse = (value) => {
        if (typeof value === 'number') return Number.isFinite(value) ? value : null;
        if (typeof value === 'string' && /^\d[\d,]*$/.test(value)) return Number(value.replace(/,/g, ''));

        return null;
    };

    const display = ref(source());
    let frame = null;
    let current = 0;

    const stop = () => frame !== null && window.cancelAnimationFrame(frame);

    const run = (target, from) => {
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        const original = source();

        if (target === null || reduced || from === target) {
            display.value = original;
            current = target ?? 0;

            return;
        }

        const started = performance.now();
        const useCommas = typeof original === 'string' ? original.includes(',') : target >= 10000;
        const tick = (now) => {
            const progress = Math.min(1, (now - started) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            const value = Math.round(from + (target - from) * eased);

            current = value;
            display.value = typeof original === 'number' && !useCommas ? value : value.toLocaleString('en-US');
            frame = progress < 1 ? window.requestAnimationFrame(tick) : null;
        };

        frame = window.requestAnimationFrame(tick);
    };

    onMounted(() => run(parse(source()), 0));
    watch(source, (value) => {
        stop();
        run(parse(value), current);
    });
    onBeforeUnmount(stop);

    return display;
}
