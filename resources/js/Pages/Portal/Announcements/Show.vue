<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Icon from '../../../Components/UI/Icon.vue';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import AnnouncementBody from '../../../Components/Announcements/AnnouncementBody.vue';
import { formatDate } from '../../../Utils/datetime';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    announcement: { type: Object, required: true },
});

// A rough reading time for the announcement (about 200 words a minute).
const readingMinutes = computed(() => {
    const text = `${props.announcement.summary ?? ''} ${String(props.announcement.body ?? '').replace(/<[^>]*>/g, ' ')}`;
    return Math.max(1, Math.round(text.split(/\s+/).filter(Boolean).length / 200));
});
</script>

<template>
    <Head :title="announcement.title" />

    <PageHeader
        :title="announcement.title"
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Announcements', href: '/announcements' }, { label: announcement.title }]"
    >
        <div class="flex flex-wrap items-center gap-2 text-sm text-text-muted">
            <Badge v-if="announcement.featured" tone="info">Featured</Badge>
            <time :datetime="announcement.publish_at">{{ formatDate(announcement.publish_at) }}</time>
            <span v-if="announcement.author">· {{ announcement.author }}</span>
            <span class="inline-flex items-center gap-1">· <Icon name="clock" size="xs" />{{ readingMinutes }} min read</span>
        </div>
    </PageHeader>

    <Card class="max-w-3xl" :class="announcement.featured ? 'border-t-4 border-t-accent' : ''">
        <article class="px-1 py-1 sm:px-3">
            <p v-if="announcement.summary" class="mb-5 text-base text-text-muted">{{ announcement.summary }}</p>
            <AnnouncementBody :body="announcement.body" />
        </article>
    </Card>

    <Link href="/announcements" class="mt-4 inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
        <Icon name="arrowRight" size="sm" class="rotate-180" />All announcements
    </Link>
</template>
