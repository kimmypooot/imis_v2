<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from './Icon.vue';

/**
 * The legacy table row actions (`btn-action`): small icon-only buttons —
 * edit (blue), delete/deactivate (red), view (grey) — with the label as
 * the accessible name and as a tooltip. Renders a Link when given `href`.
 *
 * The tooltip shows on hover and on keyboard focus (the browser's own
 * `title` does neither reliably), can be dismissed with Escape, and is
 * drawn at the page level so a table's scroll area never clips it. On
 * touch screens the button is larger, since there is no hover to read the
 * label: give it a label that names the row ("Edit Holiday X").
 */
defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    tone: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'danger', 'success', 'warning', 'neutral'].includes(value),
    },
    href: { type: String, default: null },
    // With `href`: a full-page link in a new tab (a printable page), not an Inertia visit.
    external: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

defineEmits(['click']);

const tones = {
    primary: 'border-primary/30 text-primary hover:bg-primary hover:text-white',
    danger: 'border-danger/30 text-danger hover:bg-danger hover:text-white',
    success: 'border-success/30 text-success hover:bg-success hover:text-white',
    warning: 'border-warning/30 text-warning hover:bg-warning hover:text-white',
    neutral: 'border-border text-text-muted hover:bg-surface-muted hover:text-text',
};

const base =
    'inline-flex h-7 w-7 items-center justify-center rounded-md border bg-surface transition-colors pointer-coarse:h-10 pointer-coarse:w-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-not-allowed disabled:opacity-40';

const root = ref(null);
const open = ref(false);
const tip = ref({ x: 0, y: 0, below: false, end: false });
let timer = null;

const place = () => {
    const rect = root.value?.getBoundingClientRect();
    if (!rect) return;

    const below = rect.top < 44; // no room above: drop it underneath
    // Near the right edge the label lines up with the button's right side instead of centering.
    const end = rect.left + rect.width / 2 > window.innerWidth - 180;
    tip.value = { x: end ? window.innerWidth - rect.right : rect.left + rect.width / 2, y: below ? rect.bottom + 6 : rect.top - 6, below, end };
};

const hide = () => {
    window.clearTimeout(timer);
    open.value = false;
    window.removeEventListener('scroll', hide, true);
    window.removeEventListener('resize', hide);
};

const show = (delay) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => {
        place();
        open.value = true;
        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
    }, delay);
};

// Hovering waits a moment so passing over a row doesn't flash tooltips;
// keyboard focus shows it at once (but not after a mouse click).
const onFocus = (event) => {
    if (event.target instanceof HTMLElement && event.target.matches(':focus-visible')) show(0);
};

onBeforeUnmount(hide);
</script>

<template>
    <span
        ref="root"
        class="inline-flex"
        @mouseenter="show(350)"
        @mouseleave="hide"
        @focusin="onFocus"
        @focusout="hide"
        @keydown.esc="hide"
    >
        <a v-if="href && external && !disabled" :href="href" target="_blank" rel="noopener noreferrer" :class="[base, tones[tone]]" :aria-label="`${label} (opens in a new tab)`">
            <Icon :name="icon" size="sm" />
        </a>
        <Link v-else-if="href && !disabled" :href="href" :class="[base, tones[tone]]" :aria-label="label">
            <Icon :name="icon" size="sm" />
        </Link>
        <button v-else type="button" :class="[base, tones[tone]]" :aria-label="label" :disabled="disabled" @click="$emit('click')">
            <Icon :name="icon" size="sm" />
        </button>

        <!-- Decorative: the button's aria-label already carries the text. -->
        <Teleport to="body">
            <span
                v-if="open"
                aria-hidden="true"
                class="pointer-events-none fixed z-[70] w-max max-w-xs rounded-md bg-text px-2.5 py-1.5 text-xs leading-snug font-medium text-white shadow-lg"
                :class="[tip.below ? '' : '-translate-y-full', tip.end ? '' : '-translate-x-1/2']"
                :style="tip.end ? { right: `${tip.x}px`, top: `${tip.y}px` } : { left: `${tip.x}px`, top: `${tip.y}px` }"
            >
                {{ label }}
            </span>
        </Teleport>
    </span>
</template>
