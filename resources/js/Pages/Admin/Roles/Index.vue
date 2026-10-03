<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../Components/UI/AdminCard.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import ActionButton from '../../../Components/UI/ActionButton.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import Icon from '../../../Components/UI/Icon.vue';
import RoleModal from '../../../Components/Admin/RoleModal.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

/**
 * IMIS roles — what people can do in IMIS itself (the legacy
 * user/admin/superadmin levels, made configurable). Roles inside each
 * system are separate: see Manage Systems and System Access.
 */
const props = defineProps({
    roles: { type: Array, required: true },
    permissions: { type: Array, required: true },
});

const { can } = useAuthorization();

const columns = [
    { key: 'display_name', label: 'Role' },
    { key: 'description', label: 'Description' },
    { key: 'permissions_count', label: 'Permissions', align: 'center' },
    { key: 'users_count', label: 'People', align: 'center' },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '5rem' },
];

const modalOpen = ref(false);
const editingId = ref(null);
const editing = computed(() => props.roles.find((role) => role.id === editingId.value) ?? null);

const openAdd = () => {
    editingId.value = null;
    modalOpen.value = true;
};

const openEdit = (role) => {
    editingId.value = role.id;
    modalOpen.value = true;
};
</script>

<template>
    <Head title="IMIS Roles" />

    <PageHeader
        title="IMIS Roles"
        description="What people can do in IMIS itself. Roles inside each external system are managed under Manage Systems."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'IMIS Roles' }]"
    />

    <AdminCard title="List of Roles" icon="users">
        <template v-if="can('roles.create')" #actions>
            <Button variant="success" class="rounded-full" @click="openAdd"><Icon name="plus" size="sm" />Add Role</Button>
        </template>

        <DataTable :columns="columns" :rows="roles" caption="IMIS roles" export-name="imis-roles">
            <template #cell-display_name="{ row }">
                <div class="font-medium text-text">{{ row.display_name }}</div>
                <code class="text-xs text-text-muted">{{ row.name }}</code>
            </template>
            <template #cell-description="{ row }">
                <span class="line-clamp-2 max-w-md text-text-muted">{{ row.description || '—' }}</span>
            </template>
            <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
            <template #cell-actions="{ row }">
                <ActionButton v-if="can('roles.update') || can('permissions.manage')" icon="pencil" :label="`Edit ${row.display_name}`" @click="openEdit(row)" />
            </template>
        </DataTable>
    </AdminCard>

    <RoleModal :open="modalOpen" :role="editing" :permissions="permissions" @close="modalOpen = false" />
</template>
