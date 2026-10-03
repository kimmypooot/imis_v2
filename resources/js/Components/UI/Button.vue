<script setup>
import { computed } from 'vue';
import Spinner from './Spinner.vue';

/**
 * The one button component for the app. Presentation/behavior only — it
 * must never know about users, roles, services, or any other future
 * business module.
 */
const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary', 'danger', 'success', 'ghost', 'link'].includes(value),
    },
    type: {
        type: String,
        default: 'button',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    /** Icon-only button: pass an accessible label since there is no visible text. */
    iconOnly: {
        type: Boolean,
        default: false,
    },
});

const isDisabled = computed(() => props.disabled || props.loading);

const variantClasses = {
    primary: 'bg-primary text-white hover:bg-primary-hover',
    secondary: 'border border-border bg-surface text-text hover:bg-surface-muted',
    danger: 'bg-danger text-white hover:bg-csc-red-dark',
    // Legacy "Add …" / "Save" actions were green.
    success: 'bg-success text-white hover:brightness-95',
    ghost: 'text-text hover:bg-surface-muted',
    link: 'text-primary hover:text-primary-hover underline-offset-2 hover:underline px-0 py-0',
};

const sizeClasses = computed(() =>
    props.iconOnly ? 'p-2' : props.variant === 'link' ? '' : 'px-4 py-2',
);
</script>

<template>
    <button
        :type="type"
        :disabled="isDisabled"
        :aria-busy="loading"
        class="inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        :class="[variantClasses[variant], sizeClasses]"
    >
        <Spinner v-if="loading" size="sm" label="" />
        <slot />
    </button>
</template>
