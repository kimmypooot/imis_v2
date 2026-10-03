<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import AdminCard from '../../Components/UI/AdminCard.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';
import { leaveDates } from '../../Components/HR/leaveStatus';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b: Leave Approvals — applications waiting for you (as their
 * supervisor, as HR, or as the Head or an officer-in-charge), oldest
 * first, and the ones you acted on lately.
 */
defineProps({
    queue: { type: Array, required: true },
    recent: { type: Array, required: true },
});

const queueColumns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'name', label: 'Employee' },
    { key: 'type_name', label: 'Type of leave' },
    { key: 'date_from', label: 'Dates', value: (row) => leaveDates(row, formatCalendarDate) },
    { key: 'step_label', label: 'Your step' },
    { key: 'waiting_since', label: 'Waiting since' },
];

const recentColumns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'name', label: 'Employee' },
    { key: 'type_name', label: 'Type of leave' },
    { key: 'my_action', label: 'Your action' },
    { key: 'status_label', label: 'Now' },
    { key: 'acted_at', label: 'When' },
];
</script>

<template>
    <Head title="Leave Approvals" />

    <PageHeader title="Leave Approvals" description="Applications waiting for your recommendation, certification or approval." :breadcrumbs="[{ label: 'Leave Approvals' }]" />

    <div class="space-y-6">
        <AdminCard :title="`Waiting for you (${queue.length})`" icon="checkCircle">
            <DataTable :columns="queueColumns" :rows="queue" caption="Leave applications waiting for you" export-name="imis-leave-queue" empty-text="Nothing is waiting for you.">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-primary hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-name="{ row }">
                    {{ row.name }}
                    <div v-if="row.office" class="text-xs text-text-muted">{{ row.office }}</div>
                </template>
                <template #cell-date_from="{ row }">{{ leaveDates(row, formatCalendarDate) }}</template>
                <template #cell-waiting_since="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.waiting_since) }}</span></template>
            </DataTable>
        </AdminCard>

        <AdminCard v-if="recent.length" title="Recently acted on" icon="activity">
            <DataTable :columns="recentColumns" :rows="recent" caption="Leave applications you acted on" export-name="imis-leave-acted" :searchable="false">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-primary hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-acted_at="{ row }"><span class="whitespace-nowrap">{{ row.acted_at ? formatDateTime(row.acted_at) : '—' }}</span></template>
            </DataTable>
        </AdminCard>
    </div>
</template>
