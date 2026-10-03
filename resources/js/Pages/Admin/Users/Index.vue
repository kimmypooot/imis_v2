<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../Components/UI/AdminCard.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import ActionButton from '../../../Components/UI/ActionButton.vue';
import UserAvatar from '../../../Components/UI/UserAvatar.vue';
import Icon from '../../../Components/UI/Icon.vue';
import Tabs from '../../../Components/UI/Tabs.vue';
import Select from '../../../Components/Forms/Select.vue';
import AddUserModal from '../../../Components/Admin/AddUserModal.vue';
import EditUserModal from '../../../Components/Admin/EditUserModal.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { confirm } from '../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Manage Users" page: one table of accounts; Add and Edit open
 * modals. Active and Inactive tabs split the accounts by status, and the
 * role filter narrows the table further, all in the browser.
 */
const props = defineProps({
    users: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    roleOptions: { type: Array, required: true },
    officeOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const statusTab = ref(props.filters.status === 'inactive' ? 'inactive' : 'active');
const statusTabs = computed(() =>
    props.statusOptions.map((option) => ({
        key: option.value,
        label: `${option.label} (${props.users.filter((user) => user.status === option.value).length})`,
        icon: option.value === 'active' ? 'users' : 'power',
    })),
);
const roleFilter = ref(props.filters.role_id ? Number(props.filters.role_id) : '');

const rows = computed(() =>
    props.users.filter(
        (user) =>
            user.status === statusTab.value &&
            (!roleFilter.value || user.roles.some((role) => role.id === Number(roleFilter.value))),
    ),
);

const columns = [
    { key: 'name', label: 'Name', value: (user) => `${user.name} ${user.email}` },
    { key: 'username', label: 'Username' },
    { key: 'office', label: 'Office / Division', value: (user) => user.office_name ?? '' },
    { key: 'position', label: 'Position' },
    { key: 'roles', label: 'IMIS Role', align: 'center', value: (user) => user.roles.map((role) => role.display_name).join(', ') },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '7rem' },
];

const adding = ref(false);
const editingId = ref(null);
const editing = computed(() => props.users.find((user) => user.id === editingId.value) ?? null);

const toggleStatus = async (user) => {
    const deactivating = user.status === 'active';
    const confirmed = await confirm({
        title: deactivating ? 'Deactivate this account?' : 'Activate this account?',
        text: deactivating ? `${user.name} will no longer be able to sign in. Nothing is deleted.` : `${user.name} will be able to sign in again.`,
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) router.patch(`/users/${user.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
};
</script>

<template>
    <Head title="User Management" />

    <PageHeader title="User Management" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Users' }]" />

    <AdminCard title="List of Users" icon="users">
        <template v-if="can('users.create')" #actions>
            <Button variant="success" class="rounded-full" @click="adding = true">
                <Icon name="userPlus" size="sm" />
                Add User
            </Button>
        </template>

        <Tabs v-model="statusTab" :tabs="statusTabs">
            <!-- One table, shown in whichever tab is selected. -->
            <template v-for="tab in statusTabs" :key="tab.key" #[tab.key]>
                <DataTable
                    v-if="tab.key === statusTab"
                    :columns="columns"
                    :rows="rows"
                    :caption="`${tab.key === 'active' ? 'Active' : 'Inactive'} users`"
                    :export-name="`imis-users-${tab.key}`"
                    :default-sort="{ key: 'name', direction: 'asc' }"
                >
                    <template #toolbar>
                        <div class="w-44">
                            <label for="role-filter" class="sr-only">Filter by IMIS role</label>
                            <Select
                                id="role-filter"
                                v-model="roleFilter"
                                :options="[{ value: '', label: 'All IMIS roles' }, ...roleOptions.map((role) => ({ value: role.id, label: role.display_name }))]"
                            />
                        </div>
                    </template>

                    <template #cell-name="{ row }">
                        <div class="flex items-center gap-3">
                            <UserAvatar :name="row.name" :photo-url="row.profile_photo_url" size="sm" />
                            <div class="min-w-0">
                                <div class="truncate font-medium text-text">{{ row.name }}</div>
                                <div class="truncate text-xs text-text-muted">{{ row.email }}</div>
                            </div>
                        </div>
                    </template>
                    <template #cell-office="{ row }">
                        <span :title="row.office_name">{{ row.office || '—' }}</span>
                    </template>
                    <template #cell-roles="{ row }">
                        <div class="flex flex-wrap justify-center gap-1">
                            <span v-for="role in row.roles" :key="role.id" class="rounded-full bg-info-bg px-2 py-0.5 text-xs font-medium text-primary">
                                {{ role.display_name }}
                            </span>
                            <span v-if="!row.roles.length" class="text-text-muted">—</span>
                        </div>
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-center gap-1">
                            <ActionButton icon="pencil" :label="`Edit ${row.name}`" @click="editingId = row.id" />
                            <ActionButton
                                v-if="row.can.status"
                                icon="power"
                                :tone="row.status === 'active' ? 'danger' : 'success'"
                                :label="`${row.status === 'active' ? 'Deactivate' : 'Activate'} ${row.name}`"
                                @click="toggleStatus(row)"
                            />
                            <ActionButton icon="eye" tone="neutral" :label="`View ${row.name}`" :href="`/users/${row.id}`" />
                        </div>
                    </template>
                </DataTable>
            </template>
        </Tabs>
    </AdminCard>

    <AddUserModal :open="adding" :role-options="roleOptions" :office-options="officeOptions" @close="adding = false" />
    <EditUserModal
        :open="editing !== null"
        :user="editing"
        :role-options="roleOptions"
        :office-options="officeOptions"
        @close="editingId = null"
    />
</template>
