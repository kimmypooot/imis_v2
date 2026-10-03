<script setup>
import { computed } from 'vue';

/**
 * The legacy `status-badge`: a rounded pill with a leading dot. Pass the
 * raw status value; known values get their color, anything else is grey.
 * The word is always shown, so color is never the only signal.
 */
const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, default: null },
});

const tones = {
    active: 'bg-success-bg text-success',
    published: 'bg-success-bg text-success',
    success: 'bg-success-bg text-success',
    logged_out: 'bg-surface-muted text-text-muted',
    inactive: 'bg-danger-bg text-danger',
    expired: 'bg-warning-bg text-warning',
    maintenance: 'bg-warning-bg text-warning',
    draft: 'bg-surface-muted text-text-muted',
    warning: 'bg-warning-bg text-warning',
    critical: 'bg-danger-bg text-danger',
    info: 'bg-info-bg text-info',
};

const classes = computed(() => tones[props.status] ?? 'bg-surface-muted text-text-muted');
const text = computed(() => props.label ?? props.status.replace(/_/g, ' '));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="classes">
        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ text }}
    </span>
</template>
