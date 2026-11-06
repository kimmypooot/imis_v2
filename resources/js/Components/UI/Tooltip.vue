<script setup>
import { onBeforeUnmount, ref } from 'vue';

/**
 * A text tooltip for any control that has no visible label (icon buttons).
 * It shows on hover (after a short wait, so passing over a row doesn't flash
 * tooltips) and at once on keyboard focus, hides on Escape, and is drawn at
 * the page level so a scroll area never clips it. It is decorative: the
 * wrapped control must carry its own accessible name (`aria-label`).
 */
defineProps({
    label: { type: String, required: true },
});

const root = ref(null);
const open = ref(false);
const tip = ref({ x: 0, y: 0, below: false, end: false });
let timer = null;

const place = () => {
    const rect = root.value?.getBoundingClientRect();
    if (!rect) return;

    const below = rect.top < 44; // no room above: drop it underneath
    // Near the right edge the label lines up with the control's right side instead of centering.
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

// Keyboard focus shows it at once (but not after a mouse click).
const onFocus = (event) => {
    if (event.target instanceof HTMLElement && event.target.matches(':focus-visible')) show(0);
};

onBeforeUnmount(hide);
</script>

<template>
    <span ref="root" class="inline-flex" @mouseenter="show(350)" @mouseleave="hide" @focusin="onFocus" @focusout="hide" @keydown.esc="hide">
        <slot />

        <Teleport to="body">
            <span
                v-if="open"
                aria-hidden="true"
                class="pointer-events-none fixed z-tooltip w-max max-w-xs rounded-md bg-text px-2.5 py-1.5 text-xs leading-snug font-medium text-white shadow-menu"
                :class="[tip.below ? '' : '-translate-y-full', tip.end ? '' : '-translate-x-1/2']"
                :style="tip.end ? { right: `${tip.x}px`, top: `${tip.y}px` } : { left: `${tip.x}px`, top: `${tip.y}px` }"
            >
                {{ label }}
            </span>
        </Teleport>
    </span>
</template>
