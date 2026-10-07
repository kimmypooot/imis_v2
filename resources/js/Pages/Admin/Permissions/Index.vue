<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';

defineOptions({ layout: AdminLayout });

/**
 * Every IMIS permission (read-only — permissions are defined in code and
 * granted to roles on the IMIS Roles page).
 */
defineProps({
    permissions: { type: Array, required: true },
});

const groupOf = (permission) =>
    permission.name
        .split('.')[0]
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');

const columns = [
    { key: 'group', label: 'Module', value: groupOf },
    { key: 'display_name', label: 'Permission' },
    { key: 'name', label: 'Identifier' },
    { key: 'roles_count', label: 'Roles', align: 'center' },
];
</script>

<template>
    <Head title="Permissions" />

    <PageHeader
        title="Permissions"
        description="What each permission allows. Grant them to IMIS roles on the IMIS Roles page."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Permissions' }]"
    />

    <Card title="All Permissions" icon="key">
        <DataTable :columns="columns" :rows="permissions" caption="Permissions" export-name="imis-permissions" :default-sort="{ key: 'group', direction: 'asc' }" :default-page-size="50">
            <template #cell-name="{ row }">
                <code class="text-xs text-text-muted">{{ row.name }}</code>
            </template>
        </DataTable>
    </Card>
</template>
