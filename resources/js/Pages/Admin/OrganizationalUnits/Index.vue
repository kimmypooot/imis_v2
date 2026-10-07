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
 * Offices (Regional Support Units, field and satellite offices) — the
 * legacy divisions list. Add / Edit in a modal; activate / deactivate per
 * row. A unit can't be made its own ancestor (checked on the server).
 */
const props = defineProps({
    organizationalUnits: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    unitTypeOptions: { type: Array, required: true },
    parentOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const typeLabel = (value) => props.unitTypeOptions.find((option) => option.value === value)?.label ?? value;

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'code', label: 'Code', align: 'center' },
    { key: 'unit_type', label: 'Type', value: (unit) => typeLabel(unit.unit_type) },
    { key: 'head', label: 'Head', value: (unit) => (unit.head ? [unit.head.name, unit.head.capacity ?? unit.head.position].filter(Boolean).join(', ') : '') },
    { key: 'parent', label: 'Parent', value: (unit) => unit.parent?.name ?? '' },
    { key: 'sort_order', label: 'Order', align: 'center' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];

const modalOpen = ref(false);
const editing = ref(null);

const fields = computed(() => [
    { key: 'code', label: 'Code', required: true },
    { key: 'name', label: 'Name', required: true },
    { key: 'short_name', label: 'Short name' },
    {
        key: 'parent_id',
        label: 'Parent unit',
        type: 'select',
        options: [
            { value: '', label: 'None (top-level)' },
            ...props.parentOptions.filter((unit) => unit.id !== editing.value?.id).map((unit) => ({ value: unit.id, label: unit.name })),
        ],
    },
    { key: 'unit_type', label: 'Type', type: 'select', required: true, options: props.unitTypeOptions },
    { key: 'status', label: 'Status', type: 'select', required: true, options: props.statusOptions },
    { key: 'sort_order', label: 'Display order', type: 'number', required: true },
    { key: 'email', label: 'Email', type: 'email' },
    { key: 'telephone', label: 'Telephone', type: 'tel' },
    { key: 'website_url', label: 'Website URL', type: 'url' },
    { key: 'address', label: 'Address', span: 'full' },
    { key: 'description', label: 'Description', type: 'textarea' },
]);

const defaults = {
    code: '', name: '', short_name: '', parent_id: '', unit_type: 'regional_support_unit', status: 'active', sort_order: 0,
    email: '', telephone: '', website_url: '', address: '', description: '',
};

const openAdd = () => {
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (unit) => {
    editing.value = unit;
    modalOpen.value = true;
};

const toggleStatus = async (unit) => {
    const deactivating = unit.status === 'active';
    const confirmed = await confirm({
        title: deactivating ? `Deactivate ${unit.name}?` : `Activate ${unit.name}?`,
        text: deactivating ? 'It will be hidden from the portal and the dashboard directory.' : 'It will appear in the portal again.',
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) router.patch(`/admin/organizational-units/${unit.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Organizational Units" />

    <PageHeader
        title="Organizational Units"
        description="CSC RO VIII's Regional Support Units, field offices, and satellite office. They group users and systems, and form the dashboard directory."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Organizational Units' }]"
    />

    <Card title="List of Offices" icon="building">
        <template v-if="can('organizational_units.create')" #actions>
            <Button variant="success" class="rounded-full" @click="openAdd"><Icon name="plus" size="sm" />Add Office</Button>
        </template>

        <DataTable :columns="columns" :rows="organizationalUnits" caption="Organizational units" export-name="imis-offices">
            <template #cell-name="{ row }">
                <div class="font-medium text-text">{{ row.name }}</div>
                <div v-if="row.short_name && row.short_name !== row.code" class="text-xs text-text-muted">{{ row.short_name }}</div>
            </template>
            <template #cell-code="{ row }">
                <span class="rounded bg-surface-muted px-1.5 py-0.5 font-mono text-xs">{{ row.code }}</span>
            </template>
            <template #cell-head="{ row }">
                <template v-if="row.head">
                    <div class="text-text">{{ row.head.name }}</div>
                    <div class="text-xs text-text-muted">
                        <template v-if="row.head.capacity">{{ row.head.capacity }}<template v-if="row.head.position"> ({{ row.head.position }})</template></template>
                        <template v-else>{{ row.head.position }}</template>
                    </div>
                </template>
                <span v-else class="text-text-muted">—</span>
            </template>
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-actions="{ row }">
                <div class="flex justify-center gap-1">
                    <ActionButton v-if="can('organizational_units.update')" icon="pencil" :label="`Edit ${row.name}`" @click="openEdit(row)" />
                    <ActionButton
                        v-if="can('organizational_units.archive')"
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
        noun="Office"
        :record="editing"
        :fields="fields"
        :defaults="defaults"
        :nullable="['parent_id', 'short_name', 'email', 'telephone', 'website_url', 'address', 'description']"
        store-url="/admin/organizational-units"
        :update-url="(unit) => `/admin/organizational-units/${unit.id}`"
        @close="modalOpen = false"
    />
</template>
