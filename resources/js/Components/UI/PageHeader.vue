<script setup>
import Breadcrumbs from '../Navigation/Breadcrumbs.vue';

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: null,
    },
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="mb-6">
        <Breadcrumbs v-if="breadcrumbs.length" :items="breadcrumbs" class="mb-3" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-lg font-semibold text-text sm:text-xl">{{ title }}</h1>
                <p v-if="description" class="mt-1 text-sm text-text-muted">{{ description }}</p>
            </div>

            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.default" class="mt-3">
            <slot />
        </div>
    </div>
</template>
