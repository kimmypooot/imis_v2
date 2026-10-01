<script setup>
import { computed } from 'vue';

/**
 * Semantic status badge. Status is conveyed by the label text plus color,
 * never color alone — pass the human-readable word (e.g. "Active",
 * "Pending") as the default slot and pick the closest `tone`.
 */
const props = defineProps({
    tone: {
        type: String,
        default: 'neutral',
        validator: (value) => ['success', 'warning', 'danger', 'info', 'neutral'].includes(value),
    },
});

const toneClasses = {
    success: 'bg-success-bg text-success ring-1 ring-inset ring-success-border',
    warning: 'bg-warning-bg text-warning ring-1 ring-inset ring-warning-border',
    danger: 'bg-danger-bg text-danger ring-1 ring-inset ring-danger-border',
    info: 'bg-info-bg text-info ring-1 ring-inset ring-info-border',
    neutral: 'bg-surface-muted text-text-muted ring-1 ring-inset ring-border',
};

const classes = computed(() => toneClasses[props.tone]);
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium" :class="classes">
        <slot />
    </span>
</template>
