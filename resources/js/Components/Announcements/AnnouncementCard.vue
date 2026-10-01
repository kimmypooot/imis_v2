<script setup>
import { Link } from '@inertiajs/vue3';
import Badge from '../UI/Badge.vue';
import { formatDate } from '../../Utils/datetime';

/**
 * Listing card for a live announcement (portal home, announcements index,
 * search). Shows the summary only — the body lives on the detail page.
 */
defineProps({
    announcement: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Link
        :href="`/announcements/${announcement.slug}`"
        class="block rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
    >
        <span class="flex flex-wrap items-center gap-2 text-xs text-text-muted">
            <Badge v-if="announcement.featured" tone="info">Featured</Badge>
            <time :datetime="announcement.publish_at">{{ formatDate(announcement.publish_at) }}</time>
        </span>
        <span class="mt-1 block font-medium text-text">{{ announcement.title }}</span>
        <span v-if="announcement.summary" class="mt-1 line-clamp-2 block text-sm text-text-muted">
            {{ announcement.summary }}
        </span>
    </Link>
</template>
