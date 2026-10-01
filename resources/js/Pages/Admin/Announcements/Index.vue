<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Table from '../../../Components/UI/Table.vue';
import Badge from '../../../Components/UI/Badge.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import Pagination from '../../../Components/UI/Pagination.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import Select from '../../../Components/Forms/Select.vue';
import VisibilityBadge from '../../../Components/Announcements/VisibilityBadge.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    announcements: { type: Object, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const featured = ref(props.filters.featured ?? '');

const featuredOptions = [
    { value: '1', label: 'Featured only' },
    { value: '0', label: 'Not featured' },
];

const applyFilters = () => {
    router.get(
        '/admin/announcements',
        { search: search.value, status: status.value, featured: featured.value },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Announcements" />

    <PageHeader
        title="Announcements"
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Announcements' }]"
    >
        <template v-if="can('announcements.create')" #actions>
            <Link href="/admin/announcements/create">
                <Button>New announcement</Button>
            </Link>
        </template>
    </PageHeader>

    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
        <div class="w-full max-w-xs">
            <TextInput id="search" v-model="search" label="Search" placeholder="Title or summary" />
        </div>
        <div class="w-48">
            <label for="status-filter" class="block text-sm font-medium text-text">Status</label>
            <div class="mt-1">
                <Select id="status-filter" v-model="status" placeholder="All statuses" :options="statusOptions" />
            </div>
        </div>
        <div class="w-48">
            <label for="featured-filter" class="block text-sm font-medium text-text">Featured</label>
            <div class="mt-1">
                <Select id="featured-filter" v-model="featured" placeholder="All" :options="featuredOptions" />
            </div>
        </div>
        <Button type="submit" variant="secondary">Filter</Button>
    </form>

    <Table :empty="announcements.data.length === 0">
        <template #empty>
            <EmptyState
                icon="megaphone"
                title="No announcements found"
                description="Try a different search or filter, or write the first announcement."
            />
        </template>

        <thead>
            <tr>
                <th scope="col">Title</th>
                <th scope="col">Status</th>
                <th scope="col">On portal</th>
                <th scope="col">Publish date</th>
                <th scope="col">Expires</th>
                <th scope="col">Author</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="announcement in announcements.data" :key="announcement.id">
                <td>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="`/admin/announcements/${announcement.id}`"
                            class="font-medium text-text hover:text-primary"
                        >
                            {{ announcement.title }}
                        </Link>
                        <Badge v-if="announcement.featured" tone="info">Featured</Badge>
                    </div>
                </td>
                <td class="text-text-muted capitalize">{{ announcement.status }}</td>
                <td><VisibilityBadge :state="announcement.visibility_state" /></td>
                <td class="text-text-muted">{{ formatDateTime(announcement.publish_at) || '—' }}</td>
                <td class="text-text-muted">{{ formatDateTime(announcement.expires_at) || '—' }}</td>
                <td class="text-text-muted">{{ announcement.author ?? '—' }}</td>
                <td class="space-x-3 text-right whitespace-nowrap">
                    <Link
                        :href="`/admin/announcements/${announcement.id}`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        Preview
                    </Link>
                    <Link
                        v-if="can('announcements.update')"
                        :href="`/admin/announcements/${announcement.id}/edit`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        Edit
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>

    <Pagination
        :prev-url="announcements.prev_page_url"
        :next-url="announcements.next_page_url"
        :current-page="announcements.current_page"
        :last-page="announcements.last_page"
    />
</template>
