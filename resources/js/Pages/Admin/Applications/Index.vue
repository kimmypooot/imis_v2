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
import SystemModal from '../../../Components/Admin/SystemModal.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { confirm } from '../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Manage Projects" page: every registered system, with Add /
 * Edit in one extra-large modal (details, dashboard listing, roles,
 * maintenance) and an activate/deactivate toggle per row.
 */
const props = defineProps({
    applications: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    visibilityOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
    committeeOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const options = computed(() => ({
    statusOptions: props.statusOptions,
    visibilityOptions: props.visibilityOptions,
    organizationalUnitOptions: props.organizationalUnitOptions,
    committeeOptions: props.committeeOptions,
}));

const columns = [
    { key: 'name', label: 'System name', value: (system) => `${system.name} ${system.short_name ?? ''}` },
    { key: 'description', label: 'Description' },
    { key: 'short_name', label: 'Code', align: 'center' },
    { key: 'office', label: 'Office', align: 'center' },
    { key: 'roles', label: 'Roles', align: 'center', value: (system) => system.roles.length },
    { key: 'users_count', label: 'Users', align: 'center' },
    { key: 'status', label: 'Status', align: 'center', value: (system) => (system.under_maintenance ? 'maintenance' : system.status) },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '8rem' },
];

const modalOpen = ref(false);
const editingId = ref(null);
const editing = computed(() => props.applications.find((system) => system.id === editingId.value) ?? null);

const openAdd = () => {
    editingId.value = null;
    modalOpen.value = true;
};

const openEdit = (system) => {
    editingId.value = system.id;
    modalOpen.value = true;
};

const toggleStatus = async (system) => {
    const deactivating = system.status === 'active';
    const confirmed = await confirm({
        title: deactivating ? `Deactivate ${system.name}?` : `Activate ${system.name}?`,
        text: deactivating ? 'It will disappear from the portal for everyone. Roles and access are kept.' : 'It will appear in the portal again.',
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) router.patch(`/admin/applications/${system.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Manage Systems" />

    <PageHeader
        title="Manage Systems"
        description="The CSC RO VIII systems IMIS launches. They stay independent applications — IMIS lists them, knows each one's roles, and controls who can open them."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Systems' }]"
    />

    <AdminCard title="Registered Systems" icon="grid">
        <template v-if="can('applications.create')" #actions>
            <Button variant="success" class="rounded-full" @click="openAdd">
                <Icon name="plus" size="sm" />
                Add System
            </Button>
        </template>

        <DataTable :columns="columns" :rows="applications" caption="Registered systems" export-name="imis-systems">
            <template #cell-name="{ row }">
                <div class="flex items-center gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                        <Icon name="grid" size="sm" />
                    </span>
                    <div class="min-w-0">
                        <div class="font-medium text-text">{{ row.name }}</div>
                        <div v-if="row.requires_access" class="text-xs text-text-muted">Restricted</div>
                    </div>
                </div>
            </template>
            <template #cell-description="{ row }">
                <p class="line-clamp-2 max-w-sm text-text-muted">{{ row.description || '—' }}</p>
            </template>
            <template #cell-short_name="{ row }">
                <span v-if="row.short_name" class="rounded bg-surface-muted px-1.5 py-0.5 font-mono text-xs">{{ row.short_name }}</span>
                <span v-else class="text-text-muted">—</span>
            </template>
            <template #cell-roles="{ row }">{{ row.roles.length }}</template>
            <template #cell-status="{ row }">
                <div class="flex flex-col items-center gap-1">
                    <StatusBadge :status="row.status" />
                    <StatusBadge v-if="row.under_maintenance" status="maintenance" label="Maintenance" />
                </div>
            </template>
            <template #cell-actions="{ row }">
                <div class="flex justify-center gap-1">
                    <ActionButton icon="pencil" :label="`Edit ${row.name}`" @click="openEdit(row)" />
                    <ActionButton
                        v-if="can('applications.manage_access')"
                        icon="key"
                        tone="success"
                        :label="`Manage access to ${row.name}`"
                        :href="`/admin/system-access?system=${row.id}`"
                    />
                    <ActionButton
                        v-if="can('applications.archive')"
                        icon="power"
                        :tone="row.status === 'active' ? 'danger' : 'success'"
                        :label="`${row.status === 'active' ? 'Deactivate' : 'Activate'} ${row.name}`"
                        @click="toggleStatus(row)"
                    />
                </div>
            </template>
        </DataTable>
    </AdminCard>

    <SystemModal :open="modalOpen" :system="editing" :options="options" @close="modalOpen = false" />
</template>
