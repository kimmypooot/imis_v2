<script setup>
import { computed } from 'vue';

/**
 * A user's profile photo, or their initials on navy when there is none.
 */
const props = defineProps({
    name: { type: String, required: true },
    photoUrl: { type: String, default: null },
    size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) },
});

const sizes = { sm: 'h-7 w-7 text-[0.65rem]', md: 'h-9 w-9 text-xs', lg: 'h-14 w-14 text-base', xl: 'h-24 w-24 text-2xl' };

const initials = computed(() =>
    props.name
        .split(/\s+/)
        .filter((part) => part && part.length > 1 && !part.endsWith('.'))
        .map((part) => part[0])
        .filter((_, index, all) => index === 0 || index === all.length - 1)
        .join('')
        .toUpperCase(),
);
</script>

<template>
    <img v-if="photoUrl" :src="photoUrl" alt="" class="shrink-0 rounded-full object-cover ring-2 ring-surface" :class="sizes[size]" />
    <span v-else class="flex shrink-0 items-center justify-center rounded-full bg-primary font-semibold text-white" :class="sizes[size]" aria-hidden="true">
        {{ initials || '?' }}
    </span>
</template>
