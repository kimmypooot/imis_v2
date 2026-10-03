<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from './Icon.vue';

/**
 * The legacy table row actions (`btn-action`): small icon-only buttons —
 * edit (blue), delete/deactivate (red), view (grey) — with the label as
 * both tooltip and accessible name. Renders a Link when given `href`.
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
    'inline-flex h-7 w-7 items-center justify-center rounded-md border bg-surface transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-not-allowed disabled:opacity-40';
</script>

<template>
    <Link v-if="href && !disabled" :href="href" :class="[base, tones[tone]]" :title="label" :aria-label="label">
        <Icon :name="icon" size="sm" />
    </Link>
    <button v-else type="button" :class="[base, tones[tone]]" :title="label" :aria-label="label" :disabled="disabled" @click="$emit('click')">
        <Icon :name="icon" size="sm" />
    </button>
</template>
