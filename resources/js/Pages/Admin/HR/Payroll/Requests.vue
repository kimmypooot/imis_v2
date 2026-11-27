<script setup>
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import { formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-8 monitoring: payslip requests by status. Read-only — the head of
 * HRD decides them under Payslip Approvals.
 */
defineProps({
    status: { type: String, required: true },
    requests: { type: Array, required: true },
    counts: { type: Object, required: true },
});

const tabs = ['pending', 'approved', 'rejected', 'cancelled'];
const show = (status) => router.get('/admin/hr/payroll/requests', { status }, { preserveScroll: true });

const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'employee', label: 'Employee' },
    { key: 'period', label: 'Month' },
    { key: 'purpose', label: 'Purpose', value: (row) => row.purpose ?? '' },
    { key: 'requested_at', label: 'Requested' },
    { key: 'decided', label: 'Decided', value: (row) => row.decided_by ?? '' },
];
</script>

<template>
    <Head title="Payslip Requests" />

    <PageHeader
        title="Payslip Requests"
        description="Requests from staff for their payslips. The head of HRD approves them under Payslip Approvals."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: 'Payslip Requests' }]"
    />

    <Card title="Requests" icon="clipboard">
        <template #actions>
            <FilterButtons :model-value="status" label="Status" :options="tabs.map((tab) => ({ value: tab, label: tab.charAt(0).toUpperCase() + tab.slice(1), count: counts[tab] ?? 0 }))" @update:model-value="show" />
        </template>

        <DataTable :columns="columns" :rows="requests" caption="Payslip requests" :export-name="`imis-payslip-requests-${status}`" :empty-text="`No ${status} requests.`">
            <template #cell-employee="{ row }">
                <PersonCell :name="row.employee" :photo-url="row.photo_url" :subtitle="[row.position, row.office].filter(Boolean).join(' · ')" />
            </template>
            <template #cell-purpose="{ row }">{{ row.purpose || '—' }}</template>
            <template #cell-requested_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.requested_at) }}</span></template>
            <template #cell-decided="{ row }">
                <template v-if="row.decided_by">
                    {{ row.decided_by }}<span v-if="row.acting_for" class="text-text-muted"> for {{ row.acting_for }}</span>
                    <div class="text-xs whitespace-nowrap text-text-muted">{{ formatDateTime(row.decided_at) }}</div>
                    <div v-if="row.remarks" class="text-xs text-text-muted">{{ row.remarks }}</div>
                </template>
                <span v-else class="text-text-muted">—</span>
            </template>
        </DataTable>
    </Card>
</template>
