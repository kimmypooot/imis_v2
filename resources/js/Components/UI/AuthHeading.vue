<script setup>
import { useBranding } from '../../Composables/useBranding';

/**
 * The heading of every sign-in page's card (login, forgot / reset password,
 * verify email): the IMIS logo beside a blue title and one line under it,
 * left-aligned with the form below.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});

const brand = useBranding();
</script>

<template>
    <div class="mb-6 flex items-center gap-3.5">
        <img :src="brand.app_logo" alt="IMIS" class="pointer-events-none h-10 w-auto shrink-0 select-none" draggable="false" />
        <div class="min-w-0">
            <h1 class="text-lg leading-snug font-semibold text-link">{{ title }}</h1>
            <p v-if="subtitle" class="text-sm text-link">{{ subtitle }}</p>
        </div>
    </div>
</template>
