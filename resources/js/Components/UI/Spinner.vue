<script setup>
/**
 * The one spinner: a faint track with an arc that turns and stretches as it
 * goes (the arc keeps moving when the turning is switched off by "reduce
 * motion", it simply holds still as a three-quarter ring). It takes the
 * current text color, so it suits any button or surface. `label` is what
 * screen readers hear; pass '' when the surrounding control already says it.
 */
defineProps({
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    label: {
        type: String,
        default: 'Loading…',
    },
});

const sizeClasses = { sm: 'h-4 w-4', md: 'h-5 w-5', lg: 'h-8 w-8' };
</script>

<template>
    <span role="status" class="inline-flex items-center">
        <svg :class="sizeClasses[size]" class="animate-spin text-current [animation-duration:1.6s] motion-reduce:animate-none" viewBox="0 0 50 50" fill="none" aria-hidden="true">
            <circle cx="25" cy="25" r="20" stroke="currentColor" stroke-width="5" class="opacity-20" />
            <circle
                cx="25"
                cy="25"
                r="20"
                stroke="currentColor"
                stroke-width="5"
                stroke-linecap="round"
                class="animate-dash motion-reduce:animate-none"
                style="stroke-dasharray: 90 150"
            />
        </svg>
        <span v-if="label" class="sr-only">{{ label }}</span>
    </span>
</template>
