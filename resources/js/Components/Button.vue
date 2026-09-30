<script setup>
defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary'].includes(value),
    },
});
</script>

<template>
    <button
        class="inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        :class="variant === 'primary'
            ? 'bg-primary text-white hover:bg-primary-hover'
            : 'border border-border bg-surface text-text hover:bg-surface-muted'"
    >
        <slot />
    </button>
</template>
