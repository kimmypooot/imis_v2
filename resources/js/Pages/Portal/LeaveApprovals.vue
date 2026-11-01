<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import QueueSummary from '../../Components/Portal/QueueSummary.vue';
import { daysSince, formatCalendarDate, formatDateTime } from '../../Utils/datetime';
import { leaveDates } from '../../Components/HR/leaveStatus';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b: Leave Approvals — applications waiting for you (as their
 * supervisor, as HR, or as the Head or an officer-in-charge), oldest
 * first, and the ones you acted on lately.
 */
const props = defineProps({
    queue: { type: Array, required: true },
    recent: { type: Array, required: true },
});

const waitingSince = () => props.queue.map((row) => row.waiting_since);

// How long something has waited: calm at first, then amber, then red.
const ageClass = (iso) => (daysSince(iso) >= 7 ? "bg-danger-bg text-danger" : daysSince(iso) >= 3 ? "bg-warning-bg text-warning" : "bg-surface-muted text-text-muted");
const ageLabel = (iso) => (daysSince(iso) === 0 ? "today" : `${daysSince(iso)} d`);

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

    <PageHeader title="Leave Approvals" description="Applications waiting for your recommendation, certification or approval." :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Leave Approvals' }]" />

    <div class="space-y-6">
        <QueueSummary :since="waitingSince()" noun="leave application" />

        <Card :title="`Waiting for you (${queue.length})`" icon="checkCircle">
            <DataTable :columns="queueColumns" :rows="queue" caption="Leave applications waiting for you" export-name="imis-leave-queue" empty-text="Nothing is waiting for you.">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-primary hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-name="{ row }">
                    {{ row.name }}
                    <div v-if="row.office" class="text-xs text-text-muted">{{ row.office }}</div>
                </template>
                <template #cell-date_from="{ row }">{{ leaveDates(row, formatCalendarDate) }}</template>
                <template #cell-waiting_since="{ row }">
                    <span class="whitespace-nowrap">{{ formatDateTime(row.waiting_since) }}</span>
                    <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium" :class="ageClass(row.waiting_since)">{{ ageLabel(row.waiting_since) }}</span>
                </template>
            </DataTable>
        </Card>

        <Card v-if="recent.length" title="Recently acted on" icon="activity">
            <DataTable :columns="recentColumns" :rows="recent" caption="Leave applications you acted on" export-name="imis-leave-acted" :searchable="false">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-primary hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-acted_at="{ row }"><span class="whitespace-nowrap">{{ row.acted_at ? formatDateTime(row.acted_at) : '—' }}</span></template>
            </DataTable>
        </Card>
    </div>
</template>
