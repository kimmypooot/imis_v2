<script setup>
import Spinner from './Spinner.vue';

/**
 * The in-panel "loading" moment for content that is fetched after the page
 * appears (a modal body, a menu, a card's data): a spinner and one plain
 * line saying what is being loaded, centered in the space. For lists and
 * tables prefer skeleton rows, which keep the layout.
 */
defineProps({
    label: { type: String, default: 'Loading…' },
    /** `compact` drops the vertical padding, for menus and small cards. */
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-3 text-center text-sm text-text-muted" :class="compact ? 'py-4' : 'py-12'" role="status" aria-live="polite">
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-info-bg text-link">
            <Spinner size="md" label="" />
        </span>
        <p>{{ label }}</p>
    </div>
</template>
