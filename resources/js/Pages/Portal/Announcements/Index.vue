<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import BusyRegion from '../../../Components/UI/BusyRegion.vue';
import { useBusy } from '../../../Composables/useBusy';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Icon from '../../../Components/UI/Icon.vue';
import Pagination from '../../../Components/UI/Pagination.vue';
import AnnouncementCard from '../../../Components/Announcements/AnnouncementCard.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    announcements: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const { busy, handlers } = useBusy();
const search = ref(props.filters.search ?? '');

// Search as you type, a moment after the last key.
let timer = null;
watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(applyFilters, 350);
});
onBeforeUnmount(() => window.clearTimeout(timer));

const applyFilters = () => {
    router.get('/announcements', { search: search.value }, { preserveState: true, replace: true, ...handlers });
};
</script>

<template>
    <Head title="Announcements" />

    <PageHeader
        title="Announcements"
        description="News and notices from CSC RO VIII."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Announcements' }]"
    />

    <form role="search" class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-2" @submit.prevent="applyFilters">
        <div class="relative w-full max-w-md">
            <label for="search" class="sr-only">Search announcements</label>
            <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
            <input
                id="search"
                v-model="search"
                type="search"
                autocomplete="off"
                placeholder="Search by title or summary…"
                class="block w-full form-control py-2.5 pr-3 pl-9"
            />
        </div>
        <p class="text-sm text-text-muted" role="status">
            {{ announcements.meta.total }} announcement{{ announcements.meta.total === 1 ? '' : 's' }}<template v-if="filters.search"> matching “{{ filters.search }}”</template>
        </p>
    </form>

    <BusyRegion :busy="busy">
        <div v-if="announcements.data.length" class="grid gap-4 lg:grid-cols-2">
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
    </BusyRegion>
</template>
