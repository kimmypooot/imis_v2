<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import Pagination from '../../../Components/UI/Pagination.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import AnnouncementCard from '../../../Components/Announcements/AnnouncementCard.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    announcements: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const search = ref(props.filters.search ?? '');

const applyFilters = () => {
    router.get('/announcements', { search: search.value }, { preserveState: true, replace: true });
};
</script>

<template>
    <Head title="Announcements" />

    <PageHeader
        title="Announcements"
        description="News and notices from CSC RO VIII."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Announcements' }]"
    />

    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
        <div class="w-full max-w-xs">
            <TextInput id="search" v-model="search" label="Search" placeholder="Title or summary" />
        </div>
        <Button type="submit" variant="secondary">Search</Button>
    </form>

    <div v-if="announcements.data.length" class="space-y-3">
        <AnnouncementCard
            v-for="announcement in announcements.data"
            :key="announcement.id"
            :announcement="announcement"
        />
    </div>
    <EmptyState
        v-else
        icon="megaphone"
        :title="filters.search ? 'No matching announcements' : 'No announcements yet'"
        :description="
            filters.search
                ? 'Try a different keyword.'
                : 'Announcements published by CSC RO VIII will appear here.'
        "
    />

    <Pagination
        :prev-url="announcements.links.prev"
        :next-url="announcements.links.next"
        :current-page="announcements.meta.current_page"
        :last-page="announcements.meta.last_page"
    />
</template>
