<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Badge from '../UI/Badge.vue';
import Icon from '../UI/Icon.vue';
import { formatDate } from '../../Utils/datetime';

/**
 * Listing card for a live announcement (portal home, announcements index,
 * search). Shows the summary only — the body lives on the detail page.
 */
const props = defineProps({
    announcement: {
        type: Object,
        required: true,
    },
});

// Published within the last week.
const isNew = computed(() => props.announcement.publish_at && Date.now() - new Date(props.announcement.publish_at).getTime() < 7 * 86400000);
</script>

<template>
    <Link
        :href="`/announcements/${announcement.slug}`"
        class="group flex gap-3.5 rounded-lg border p-4 transition-colors duration-200 hover:border-primary/50 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
        :class="announcement.featured ? 'border-info-border bg-info-bg/40' : 'border-border bg-surface'"
    >
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="announcement.featured ? 'bg-primary text-white' : 'bg-info-bg text-primary'" aria-hidden="true">
            <Icon name="megaphone" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-center gap-2 text-xs text-text-muted">
                <Badge v-if="announcement.featured" tone="info">Featured</Badge>
                <Badge v-if="isNew" tone="success">New</Badge>
                <time :datetime="announcement.publish_at">{{ formatDate(announcement.publish_at) }}</time>
            </span>
            <span class="mt-1 block font-medium text-text group-hover:text-primary">{{ announcement.title }}</span>
            <span v-if="announcement.summary" class="mt-1 line-clamp-2 block text-sm text-text-muted">
                {{ announcement.summary }}
            </span>
            <span class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary">
                Read more
                <Icon name="arrowRight" size="xs" class="transition-transform group-hover:translate-x-0.5" />
            </span>
        </span>
    </Link>
</template>
