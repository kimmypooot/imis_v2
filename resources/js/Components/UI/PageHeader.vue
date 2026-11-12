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
    <!-- Legacy IMIS ".pagetitle": bold navy title, breadcrumb underneath. No accent bar: the navy title carries the page on its own. -->
    <div class="mb-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-link sm:text-2xl">{{ title }}</h1>
                <Breadcrumbs v-if="breadcrumbs.length" :items="breadcrumbs" class="mt-1" />
                <p v-if="description" class="mt-2 max-w-3xl text-sm text-text-muted">{{ description }}</p>
            </div>

            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 sm:shrink-0">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.default" class="mt-3">
            <slot />
        </div>
    </div>
</template>
