<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import AnnouncementForm from '../../../Components/Announcements/AnnouncementForm.vue';
import { fromLocalInput } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

const form = useForm({
    title: '',
    summary: '',
    body: '',
    publish_at: '',
    expires_at: '',
    featured: false,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        publish_at: fromLocalInput(data.publish_at),
        expires_at: fromLocalInput(data.expires_at),
    })).post('/admin/announcements');
};
</script>

<template>
    <Head title="New announcement" />

    <PageHeader
        title="New announcement"
        description="New announcements are saved as drafts. Preview, then publish when ready."
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Announcements', href: '/admin/announcements' },
            { label: 'New announcement' },
        ]"
    />

    <Card class="max-w-3xl">
        <AnnouncementForm :form="form" submit-label="Save draft" @submit="submit" />
    </Card>
</template>
