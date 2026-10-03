<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import { formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-2b: signed PDSs awaiting verification, oldest first.
 */
defineProps({
    pending: { type: Array, required: true },
});

const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'unit', label: 'Office', value: (row) => row.unit ?? '' },
    { key: 'kind', label: 'Type', align: 'center' },
    { key: 'signed_at', label: 'Signed', value: (row) => formatDateTime(row.signed_at) },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '5rem' },
];
</script>

<template>
    <Head title="PDS Verification" />

    <PageHeader title="PDS Verification" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources' }, { label: 'PDS Verification' }]" />

    <AdminCard title="Awaiting Verification" icon="checkCircle" subtitle="Each is the PDS exactly as the employee e-signed it. Opening one is logged.">
        <DataTable :columns="columns" :rows="pending" caption="PDS submissions awaiting verification" export-name="imis-pds-verifications">
            <template #cell-name="{ row }">
                <div class="font-medium text-text">{{ row.name }}</div>
                <div v-if="row.position" class="text-xs text-text-muted">{{ row.position }}</div>
            </template>
            <template #cell-unit="{ row }">{{ row.unit || '—' }}</template>
            <template #cell-kind="{ row }">
                <StatusBadge :status="row.kind === 'Update' ? 'info' : 'draft'" :label="row.kind" />
            </template>
            <template #cell-actions="{ row }">
                <ActionButton icon="eye" tone="neutral" :label="`Review the PDS of ${row.name}`" :href="`/admin/hr/pds-verifications/${row.id}`" />
            </template>
        </DataTable>
    </AdminCard>
</template>
