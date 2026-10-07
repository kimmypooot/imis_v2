<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: everyone in service, with how many recurring items and running
 * loans their pay profile has. Automatic items (PERA, GSIS, PhilHealth,
 * Pag-IBIG) apply without being listed.
 */
defineProps({
    employees: { type: Array, required: true },
});

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'position', label: 'Position', value: (row) => row.position ?? '' },
    { key: 'items', label: 'Recurring items', align: 'right' },
    { key: 'loans', label: 'Running loans', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="Pay Profiles" />

    <PageHeader
        title="Pay Profiles"
        description="Each person's recurring allowances and deductions, and their loans. Automatic items apply to everyone without being listed."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: 'Pay Profiles' }]"
    />

    <Card title="Staff in service" icon="users">
        <DataTable :columns="columns" :rows="employees" caption="Pay profiles" export-name="imis-pay-profiles" empty-text="No staff in service.">
            <template #cell-name="{ row }">
                <Link :href="`/admin/hr/payroll/profiles/${row.id}`" class="font-medium text-text hover:text-primary">{{ row.name }}</Link>
            </template>
            <template #cell-position="{ row }">{{ row.position || '—' }}</template>
            <template #cell-items="{ row }"><span class="tabular-nums">{{ row.items }}</span></template>
            <template #cell-loans="{ row }"><span class="tabular-nums">{{ row.loans }}</span></template>
            <template #cell-actions="{ row }">
                <Link :href="`/admin/hr/payroll/profiles/${row.id}`" class="text-sm text-primary hover:underline">Open<span class="sr-only"> {{ row.name }}</span></Link>
            </template>
        </DataTable>
    </Card>
</template>
