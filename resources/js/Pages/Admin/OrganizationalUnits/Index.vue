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
    organizationalUnits: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    unitTypeOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const unitType = ref(props.filters.unit_type ?? '');

const applyFilters = () => {
    router.get(
        '/admin/organizational-units',
        { search: search.value, status: status.value, unit_type: unitType.value },
        { preserveState: true, replace: true },
    );
};

const unitTypeLabel = (value) => props.unitTypeOptions.find((option) => option.value === value)?.label ?? value;
</script>

<template>
    <Head title="Organizational Units" />

    <PageHeader
        title="Organizational Units"
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Organizational Units' }]"
    >
        <template v-if="can('organizational_units.create')" #actions>
            <Link href="/admin/organizational-units/create">
                <Button>New unit</Button>
            </Link>
        </template>
    </PageHeader>

    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
        <div class="w-full max-w-xs">
            <TextInput id="search" v-model="search" label="Search" placeholder="Name or code" />
        </div>
        <div class="w-48">
            <label for="status-filter" class="block text-sm font-medium text-text">Status</label>
            <div class="mt-1">
                <Select
                    id="status-filter"
                    v-model="status"
                    placeholder="All statuses"
                    :options="statusOptions"
                />
            </div>
        </div>
        <div class="w-56">
            <label for="type-filter" class="block text-sm font-medium text-text">Type</label>
            <div class="mt-1">
                <Select
                    id="type-filter"
                    v-model="unitType"
                    placeholder="All types"
                    :options="unitTypeOptions"
                />
            </div>
        </div>
        <Button type="submit" variant="secondary">Filter</Button>
    </form>

    <Table :empty="organizationalUnits.length === 0">
        <template #empty>
            <EmptyState
                title="No organizational units found"
                description="Try a different search or filter, or create the first unit."
            />
        </template>

        <thead>
            <tr>
                <th scope="col">Code</th>
                <th scope="col">Name</th>
                <th scope="col">Type</th>
                <th scope="col">Parent</th>
                <th scope="col">Status</th>
                <th scope="col">Sort Order</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="unit in organizationalUnits" :key="unit.id">
                <td class="text-text-muted">{{ unit.code }}</td>
                <td>
                    <div class="font-medium text-text">{{ unit.name }}</div>
                    <div v-if="unit.children_count" class="text-text-muted">
                        {{ unit.children_count }} sub-unit{{ unit.children_count === 1 ? '' : 's' }}
                    </div>
                </td>
                <td class="text-text-muted">{{ unitTypeLabel(unit.unit_type) }}</td>
                <td class="text-text-muted">{{ unit.parent?.name ?? '—' }}</td>
                <td>
                    <Badge :tone="unit.status === 'active' ? 'success' : 'neutral'">{{ unit.status }}</Badge>
                </td>
                <td class="text-text-muted">{{ unit.sort_order }}</td>
                <td class="text-right">
                    <Link
                        v-if="can('organizational_units.update')"
                        :href="`/admin/organizational-units/${unit.id}/edit`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        Edit
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>
</template>
