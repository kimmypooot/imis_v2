<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import AnnouncementBody from '../../../Components/Announcements/AnnouncementBody.vue';
import { formatDate } from '../../../Utils/datetime';

defineOptions({ layout: PortalLayout });

defineProps({
    announcement: { type: Object, required: true },
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
        </div>
    </PageHeader>

    <Card class="max-w-3xl">
        <article>
            <p v-if="announcement.summary" class="mb-5 text-base text-text-muted">{{ announcement.summary }}</p>
            <AnnouncementBody :body="announcement.body" />
        </article>
    </Card>
</template>
