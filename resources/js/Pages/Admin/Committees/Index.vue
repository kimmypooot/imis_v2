<script setup>
import { ref } from 'vue';
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
import { committeeDefaults, committeeFields, committeeNullable } from '../../../Components/Admin/committeeFields';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { confirm } from '../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * Committees (legacy "committee" dashboard category). Add / Edit in a
 * modal; members are managed on each committee's page.
 */
const props = defineProps({
    committees: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'active_members_count', label: 'Active members', align: 'center' },
    { key: 'effective', label: 'Effective', value: (committee) => [committee.effective_from, committee.effective_until ?? 'present'].filter(Boolean).join(' – ') },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '7rem' },
];

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => {
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (committee) => {
    editing.value = committee;
    modalOpen.value = true;
};

const toggleStatus = async (committee) => {
    const deactivating = committee.status === 'active';
    const confirmed = await confirm({
        title: deactivating ? `Deactivate ${committee.name}?` : `Activate ${committee.name}?`,
        text: deactivating ? 'It will be hidden from the portal and the dashboard directory. Members are kept.' : 'It will appear again.',
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) router.patch(`/admin/committees/${committee.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Committees" />

    <PageHeader title="Committees" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Committees' }]" />

    <AdminCard title="List of Committees" icon="users">
        <template v-if="can('committees.create')" #actions>
            <Button variant="success" class="rounded-full" @click="openAdd"><Icon name="plus" size="sm" />Add Committee</Button>
        </template>

        <DataTable :columns="columns" :rows="committees" caption="Committees" export-name="imis-committees">
            <template #cell-name="{ row }">
                <div class="font-medium text-text">{{ row.name }}</div>
                <div v-if="row.short_name" class="text-xs text-text-muted">{{ row.short_name }}</div>
            </template>
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-actions="{ row }">
                <div class="flex justify-center gap-1">
                    <ActionButton icon="users" tone="neutral" :label="`Members of ${row.name}`" :href="`/admin/committees/${row.id}`" />
                    <ActionButton v-if="can('committees.update')" icon="pencil" :label="`Edit ${row.name}`" @click="openEdit(row)" />
                    <ActionButton
                        v-if="can('committees.archive')"
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
        noun="Committee"
        :record="editing"
        :fields="committeeFields(statusOptions)"
        :defaults="committeeDefaults"
        :nullable="committeeNullable"
        store-url="/admin/committees"
        :update-url="(committee) => `/admin/committees/${committee.id}`"
        @close="modalOpen = false"
    />
</template>
