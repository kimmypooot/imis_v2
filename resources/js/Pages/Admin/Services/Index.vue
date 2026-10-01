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
    services: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    categoryOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const categoryId = ref(props.filters.category_id ?? '');
const organizationalUnitId = ref(props.filters.organizational_unit_id ?? '');

const applyFilters = () => {
    router.get(
        '/admin/services',
        {
            search: search.value,
            status: status.value,
            category_id: categoryId.value,
            organizational_unit_id: organizationalUnitId.value,
        },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Services" />

    <PageHeader title="Services" :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Services' }]">
        <template v-if="can('services.create')" #actions>
            <Link href="/admin/services/create">
                <Button>New service</Button>
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
            <label for="category-filter" class="block text-sm font-medium text-text">Category</label>
            <div class="mt-1">
                <Select
                    id="category-filter"
                    v-model="categoryId"
                    placeholder="All categories"
                    :options="categoryOptions.map((category) => ({ value: category.id, label: category.name }))"
                />
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

    <Table :empty="services.length === 0">
        <template #empty>
            <EmptyState
                title="No services found"
                description="Try a different search or filter, or create the first service."
            />
        </template>

        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Category</th>
                <th scope="col">Organizational unit</th>
                <th scope="col">Status</th>
                <th scope="col">Featured</th>
                <th scope="col">Sort Order</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="service in services" :key="service.id">
                <td>
                    <div class="font-medium text-text">{{ service.name }}</div>
                    <div v-if="service.short_name" class="text-text-muted">{{ service.short_name }}</div>
                </td>
                <td class="text-text-muted">{{ service.category?.name ?? '—' }}</td>
                <td class="text-text-muted">{{ service.organizational_unit?.name ?? '—' }}</td>
                <td>
                    <Badge :tone="service.status === 'active' ? 'success' : 'neutral'">{{ service.status }}</Badge>
                </td>
                <td class="text-text-muted">{{ service.featured ? 'Yes' : 'No' }}</td>
                <td class="text-text-muted">{{ service.sort_order }}</td>
                <td class="text-right">
                    <Link
                        v-if="can('services.update')"
                        :href="`/admin/services/${service.id}/edit`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        Edit
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>
</template>
