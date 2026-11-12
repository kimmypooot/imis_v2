<script setup>
import { computed } from 'vue';
import { toneClasses, tones } from './tones';

/**
 * Semantic status badge. Status is conveyed by the label text plus color,
 * never color alone — pass the human-readable word (e.g. "Active",
 * "Pending") as the default slot and pick the closest `tone`. `dot` adds
 * a leading dot (status pills — see StatusBadge, which is Badge + dot + pill).
 */
const props = defineProps({
    tone: {
        type: String,
        default: 'neutral',
        validator: (value) => tones.includes(value),
    },
    /** A fully rounded pill (counts, tags in a heading) instead of a rounded-md tag. */
    pill: {
        type: Boolean,
        default: false,
    },
    dot: {
        type: Boolean,
        default: false,
    },
});

const classes = computed(() => toneClasses[props.tone]);
</script>

<template>
    <span
        class="inline-flex items-center py-0.5 text-xs"
        :class="[pill ? 'rounded-full font-semibold' : 'rounded-md font-medium', dot ? 'gap-1.5 px-2.5' : 'gap-1 px-2', classes]"
    >
        <span v-if="dot" class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
        <slot />
    </span>
</template>
