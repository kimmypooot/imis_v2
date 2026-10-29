<script setup>
import { Link } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import Badge from '../UI/Badge.vue';

/**
 * Shared compact card for Applications/Services/Organizational Units.
 * Always links to the entry's own internal detail page (`href`) — the
 * actual external application URL, if any, is only ever exposed on that
 * detail page itself, never directly on a listing/grid card.
 */
defineProps({
    href: {
        type: String,
        required: true,
    },
    icon: {
        type: String,
        default: 'grid',
    },
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: null,
    },
    description: {
        type: String,
        default: null,
    },
    featured: {
        type: Boolean,
        default: false,
    },
    maintenance: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <Link
        :href="href"
        class="group flex gap-3 rounded-lg border border-border bg-surface p-4 transition duration-200 hover:border-primary hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring motion-safe:hover:-translate-y-0.5"
    >
        <span
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary transition-colors duration-200 group-hover:bg-primary group-hover:text-white"
            aria-hidden="true"
        >
            <Icon :name="icon" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
                <span class="truncate font-medium text-text">{{ title }}</span>
                <Badge v-if="maintenance" tone="warning" class="shrink-0 whitespace-nowrap">Under maintenance</Badge>
                <Badge v-else-if="featured" tone="info">Featured</Badge>
            </span>
            <span v-if="subtitle" class="block text-xs text-text-muted">{{ subtitle }}</span>
            <span v-if="description" class="mt-1 line-clamp-2 block text-sm text-text-muted">
                {{ description }}
            </span>
        </span>
    </Link>
</template>
