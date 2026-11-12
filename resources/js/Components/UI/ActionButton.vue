<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from './Icon.vue';
import Tooltip from './Tooltip.vue';

/**
 * The legacy table row actions (`btn-action`): small icon-only buttons —
 * edit (blue), delete/deactivate (red), view (grey) — with the label as
 * the accessible name and as a `Tooltip` (hover and keyboard focus).
 * Renders a Link when given `href`. On touch
 * screens the button is larger, since there is no hover to read the label:
 * give it a label that names the row ("Edit Holiday X").
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
    primary: 'border-primary/30 text-link hover:bg-primary hover:text-white',
    danger: 'border-danger/30 text-danger-fg hover:bg-danger hover:text-white',
    success: 'border-success/30 text-success-fg hover:bg-success hover:text-white',
    warning: 'border-warning/30 text-warning-fg hover:bg-warning hover:text-white',
    neutral: 'border-border text-text-muted hover:bg-surface-muted hover:text-text',
};

const base =
    'inline-flex h-7 w-7 items-center justify-center rounded-md border bg-surface transition-colors pointer-coarse:h-10 pointer-coarse:w-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-not-allowed disabled:opacity-40';
</script>

<template>
    <Tooltip :label="label">
        <a v-if="href && external && !disabled" :href="href" target="_blank" rel="noopener noreferrer" :class="[base, tones[tone]]" :aria-label="`${label} (opens in a new tab)`">
            <Icon :name="icon" size="sm" />
        </a>
        <Link v-else-if="href && !disabled" :href="href" :class="[base, tones[tone]]" :aria-label="label">
            <Icon :name="icon" size="sm" />
        </Link>
        <button v-else type="button" :class="[base, tones[tone]]" :aria-label="label" :disabled="disabled" @click="$emit('click')">
            <Icon :name="icon" size="sm" />
        </button>
    </Tooltip>
</template>
