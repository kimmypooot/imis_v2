<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import ActionButton from '../../../Components/UI/ActionButton.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import Icon from '../../../Components/UI/Icon.vue';
import RecordModal from '../../../Components/Admin/RecordModal.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { confirm } from '../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * The categories services are grouped under, in the same one-table,
 * Add / Edit-in-a-modal layout as Services.
 */
const props = defineProps({
    categories: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'description', label: 'Description' },
    { key: 'services_count', label: 'Services', align: 'center' },
    { key: 'sort_order', label: 'Display order', align: 'center' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];

const modalOpen = ref(false);
const editing = ref(null);

const fields = computed(() => [
    { key: 'name', label: 'Name', required: true },
    { key: 'status', label: 'Status', type: 'select', required: true, options: props.statusOptions },
    { key: 'sort_order', label: 'Display order', type: 'number', required: true },
    { key: 'description', label: 'Description', type: 'textarea' },
]);

const defaults = { name: '', status: 'active', sort_order: 0, description: '' };

const nullable = ['description'];

const openAdd = () => {
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (category) => {
    editing.value = category;
    modalOpen.value = true;
};

const toggleStatus = async (category) => {
    const deactivating = category.status === 'active';
    const confirmed = await confirm({
        title: deactivating ? `Deactivate ${category.name}?` : `Activate ${category.name}?`,
        text: deactivating
            ? 'It will no longer be offered when managing services or filtering the portal. Services already in it keep it.'
            : 'It can be picked for services and filtered by in the portal again.',
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) router.patch(`/admin/service-categories/${category.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Service Categories" />

    <PageHeader
        title="Service Categories"
        description="How services are grouped in the portal directory."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Services', href: '/admin/services' }, { label: 'Categories' }]"
    />

    <Card title="List of Service Categories" icon="tag">
        <template v-if="can('services.create')" #actions>
            <Button variant="success" class="rounded-full" @click="openAdd"><Icon name="plus" size="sm" />Add Category</Button>
        </template>

        <DataTable :columns="columns" :rows="categories" caption="Service categories" export-name="imis-service-categories">
            <template #cell-name="{ row }"><span class="font-medium text-text">{{ row.name }}</span></template>
            <template #cell-description="{ row }"><span class="text-text-muted">{{ row.description }}</span></template>
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-actions="{ row }">
                <div class="flex justify-center gap-1">
                    <ActionButton v-if="can('services.update')" icon="pencil" :label="`Edit ${row.name}`" @click="openEdit(row)" />
                    <ActionButton
                        v-if="can('services.archive')"
                        icon="power"
                        :tone="row.status === 'active' ? 'danger' : 'success'"
                        :label="`${row.status === 'active' ? 'Deactivate' : 'Activate'} ${row.name}`"
                        @click="toggleStatus(row)"
                    />
                </div>
            </template>
        </DataTable>
    </Card>

    <RecordModal
        :open="modalOpen"
        noun="Category"
        :record="editing"
        :fields="fields"
        :defaults="defaults"
        :nullable="nullable"
        store-url="/admin/service-categories"
        :update-url="(category) => `/admin/service-categories/${category.id}`"
        @close="modalOpen = false"
    />
</template>
