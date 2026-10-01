<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Table from '../../../Components/UI/Table.vue';
import Badge from '../../../Components/UI/Badge.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import Select from '../../../Components/Forms/Select.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    committees: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

const applyFilters = () => {
    router.get('/admin/committees', { search: search.value, status: status.value }, {
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <Head title="Committees" />

    <PageHeader title="Committees" :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Committees' }]">
        <template v-if="can('committees.create')" #actions>
            <Link href="/admin/committees/create">
                <Button>New committee</Button>
            </Link>
        </template>
    </PageHeader>

    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
        <div class="w-full max-w-xs">
            <TextInput id="search" v-model="search" label="Search" placeholder="Name or short name" />
        </div>
        <div class="w-48">
            <label for="status-filter" class="block text-sm font-medium text-text">Status</label>
            <div class="mt-1">
                <Select id="status-filter" v-model="status" placeholder="All statuses" :options="statusOptions" />
            </div>
        </div>
        <Button type="submit" variant="secondary">Filter</Button>
    </form>

    <Table :empty="committees.length === 0">
        <template #empty>
            <EmptyState
                title="No committees found"
                description="Try a different search or filter, or create the first committee."
            />
        </template>

        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Status</th>
                <th scope="col">Active members</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="committee in committees" :key="committee.id">
                <td>
                    <div class="font-medium text-text">{{ committee.name }}</div>
                    <div v-if="committee.short_name" class="text-text-muted">{{ committee.short_name }}</div>
                </td>
                <td>
                    <Badge :tone="committee.status === 'active' ? 'success' : 'neutral'">{{ committee.status }}</Badge>
                </td>
                <td class="text-text-muted">{{ committee.active_members_count }}</td>
                <td class="text-right">
                    <Link :href="`/admin/committees/${committee.id}`" class="font-medium text-primary hover:text-primary-hover">
                        View
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>
</template>
