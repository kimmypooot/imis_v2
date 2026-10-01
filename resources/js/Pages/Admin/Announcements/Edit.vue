<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import AnnouncementForm from '../../../Components/Announcements/AnnouncementForm.vue';
import VisibilityBadge from '../../../Components/Announcements/VisibilityBadge.vue';
import { fromLocalInput, toLocalInput } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    announcement: { type: Object, required: true },
});

const form = useForm({
    title: props.announcement.title,
    summary: props.announcement.summary ?? '',
    body: props.announcement.body,
    publish_at: toLocalInput(props.announcement.publish_at),
    expires_at: toLocalInput(props.announcement.expires_at),
    featured: props.announcement.featured,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        publish_at: fromLocalInput(data.publish_at),
        expires_at: fromLocalInput(data.expires_at),
    })).put(`/admin/announcements/${props.announcement.id}`);
};
</script>

<template>
    <Head :title="`Edit announcement — ${announcement.title}`" />

    <PageHeader
        :title="`Edit announcement — ${announcement.title}`"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Announcements', href: '/admin/announcements' },
            { label: announcement.title, href: `/admin/announcements/${announcement.id}` },
            { label: 'Edit' },
        ]"
    >
        <div class="flex items-center gap-2 text-sm text-text-muted">
            <VisibilityBadge :state="announcement.visibility_state" />
            <span v-if="announcement.visibility_state === 'live'">Changes are visible on the portal as soon as you save.</span>
        </div>
    </PageHeader>

    <Card class="max-w-3xl">
        <AnnouncementForm
            :form="form"
            submit-label="Save changes"
            :publish-date-required="announcement.status === 'published'"
            @submit="submit"
        />
    </Card>
</template>
