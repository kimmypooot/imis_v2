<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../Components/UI/AdminCard.vue';
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
 * The CSC services directory (what the public can request), in the
 * legacy admin layout: one table, Add / Edit in a modal.
 */
const props = defineProps({
    services: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    visibilityOptions: { type: Array, required: true },
    categoryOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'category', label: 'Category', value: (service) => service.category?.name ?? '' },
    { key: 'office', label: 'Office', value: (service) => service.organizational_unit?.name ?? '' },
    { key: 'visibility', label: 'Visible to' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];

const modalOpen = ref(false);
const editing = ref(null);

const select = (options, none) => [{ value: '', label: none }, ...options.map((option) => ({ value: option.id, label: option.name }))];

const fields = computed(() => [
    { key: 'name', label: 'Name', required: true },
    { key: 'short_name', label: 'Short name' },
    { key: 'category_id', label: 'Category', type: 'select', options: select(props.categoryOptions, '— None —') },
    { key: 'organizational_unit_id', label: 'Office', type: 'select', options: select(props.organizationalUnitOptions, '— None —') },
    { key: 'status', label: 'Status', type: 'select', required: true, options: props.statusOptions },
    { key: 'visibility', label: 'Visible to', type: 'select', required: true, options: props.visibilityOptions },
    { key: 'processing_time', label: 'Processing time' },
    { key: 'fees', label: 'Fees' },
    { key: 'external_url', label: 'External link', type: 'url', span: 'full' },
    { key: 'sort_order', label: 'Display order', type: 'number', required: true },
    { key: 'featured', label: 'Featured', type: 'checkbox' },
    { key: 'description', label: 'Description', type: 'textarea' },
    { key: 'purpose', label: 'Purpose', type: 'textarea' },
    { key: 'requirements', label: 'Requirements', type: 'textarea' },
    { key: 'procedure', label: 'Procedure', type: 'textarea' },
    { key: 'contact_information', label: 'Contact information', type: 'textarea' },
]);

const defaults = {
    name: '', short_name: '', category_id: '', organizational_unit_id: '', status: 'active', visibility: 'authenticated',
    processing_time: '', fees: '', external_url: '', sort_order: 0, featured: false,
    description: '', purpose: '', requirements: '', procedure: '', contact_information: '',
};

const nullable = ['short_name', 'category_id', 'organizational_unit_id', 'processing_time', 'fees', 'external_url', 'description', 'purpose', 'requirements', 'procedure', 'contact_information'];

const openAdd = () => {
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (service) => {
    editing.value = service;
    modalOpen.value = true;
};

const toggleStatus = async (service) => {
    const deactivating = service.status === 'active';
    const confirmed = await confirm({
        title: deactivating ? `Deactivate ${service.name}?` : `Activate ${service.name}?`,
        text: deactivating ? 'It will be hidden from the portal.' : 'It will appear in the portal again.',
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) router.patch(`/admin/services/${service.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Services" />

    <PageHeader title="Services" description="The CSC RO VIII services directory shown in the portal." :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Services' }]" />

    <AdminCard title="List of Services" icon="clipboard">
        <template v-if="can('services.create')" #actions>
            <Button variant="success" class="rounded-full" @click="openAdd"><Icon name="plus" size="sm" />Add Service</Button>
        </template>

        <DataTable :columns="columns" :rows="services" caption="Services" export-name="imis-services">
            <template #cell-name="{ row }">
                <div class="font-medium text-text">{{ row.name }}</div>
                <div v-if="row.featured" class="text-xs text-primary">Featured</div>
            </template>
            <template #cell-visibility="{ row }"><span class="capitalize">{{ row.visibility }}</span></template>
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
    </AdminCard>

    <RecordModal
        :open="modalOpen"
        noun="Service"
        :record="editing"
        :fields="fields"
        :defaults="defaults"
        :nullable="nullable"
        size="xl"
        store-url="/admin/services"
        :update-url="(service) => `/admin/services/${service.id}`"
        @close="modalOpen = false"
    />
</template>
