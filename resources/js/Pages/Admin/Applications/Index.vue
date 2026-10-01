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
    applications: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const organizationalUnitId = ref(props.filters.organizational_unit_id ?? '');

const applyFilters = () => {
    router.get(
        '/admin/applications',
        {
            search: search.value,
            status: status.value,
            organizational_unit_id: organizationalUnitId.value,
        },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Systems / Applications" />

    <PageHeader
        title="Systems / Applications"
        description="The catalog of CSC RO VIII information systems IMIS launches. These remain independent applications — IMIS only lists and links to them."
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Systems / Applications' }]"
    >
        <template v-if="can('applications.create')" #actions>
            <Link href="/admin/applications/create">
                <Button>New system</Button>
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
        <div class="w-56">
            <label for="unit-filter" class="block text-sm font-medium text-text">Organizational unit</label>
            <div class="mt-1">
                <Select
                    id="unit-filter"
                    v-model="organizationalUnitId"
                    placeholder="All units"
                    :options="organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                />
            </div>
        </div>
        <Button type="submit" variant="secondary">Filter</Button>
    </form>

    <Table :empty="applications.length === 0">
        <template #empty>
            <EmptyState
                title="No systems found"
                description="Try a different search or filter, or add the first system to the directory."
            />
        </template>

        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Organizational unit</th>
                <th scope="col">Status</th>
                <th scope="col">Visibility</th>
                <th scope="col">Featured</th>
                <th scope="col">Sort Order</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="application in applications" :key="application.id">
                <td>
                    <div class="font-medium text-text">{{ application.name }}</div>
                    <div v-if="application.short_name" class="text-text-muted">{{ application.short_name }}</div>
                </td>
                <td class="text-text-muted">{{ application.organizational_unit?.name ?? '—' }}</td>
                <td>
                    <Badge :tone="application.status === 'active' ? 'success' : 'neutral'">{{ application.status }}</Badge>
                    <Badge v-if="application.under_maintenance" tone="warning" class="ml-1">maintenance</Badge>
                </td>
                <td class="text-text-muted">{{ application.visibility }}</td>
                <td class="text-text-muted">{{ application.featured ? 'Yes' : 'No' }}</td>
                <td class="text-text-muted">{{ application.sort_order }}</td>
                <td class="text-right whitespace-nowrap">
                    <Link
                        :href="`/admin/applications/${application.id}/access`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        Access
                    </Link>
                    <Link
                        v-if="can('applications.update')"
                        :href="`/admin/applications/${application.id}/edit`"
                        class="ml-3 font-medium text-primary hover:text-primary-hover"
                    >
                        Edit
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>
</template>
