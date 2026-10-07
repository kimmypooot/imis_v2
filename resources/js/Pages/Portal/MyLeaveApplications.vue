<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Icon from '../../Components/UI/Icon.vue';
import { formatCalendarDate } from '../../Utils/datetime';
import { leaveDates, leaveStatusTones } from '../../Components/HR/leaveStatus';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b self-service: your leave applications (legacy my-applications.php).
 */
defineProps({
    applications: { type: Array, required: true },
});

const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'type_name', label: 'Type of leave' },
    { key: 'date_from', label: 'Dates', value: (row) => leaveDates(row, formatCalendarDate) },
    { key: 'status_label', label: 'Status' },
];
</script>

<template>
    <Head title="My Leave Applications" />

    <PageHeader title="My Leave Applications" :breadcrumbs="[{ label: 'My Leave', href: '/my/leave' }, { label: 'My Leave Applications' }]">
        <template #actions>
            <Link href="/my/leave/apply" class="inline-flex items-center gap-2 rounded-md bg-success px-4 py-2 text-sm font-medium text-white hover:brightness-95">
                <Icon name="plus" size="sm" />File leave
            </Link>
        </template>
    </PageHeader>

    <Card title="Applications" icon="clipboard">
        <DataTable :columns="columns" :rows="applications" caption="My leave applications" export-name="imis-my-leave-applications" empty-text="You haven't filed any leave yet.">
            <template #cell-reference_number="{ row }">
                <Link :href="`/leave/applications/${row.id}`" class="font-medium text-primary hover:underline">{{ row.reference_number }}</Link>
            </template>
            <template #cell-date_from="{ row }">{{ leaveDates(row, formatCalendarDate) }}</template>
            <template #cell-status_label="{ row }"><StatusBadge :status="leaveStatusTones[row.status]" :label="row.status_label" /></template>
        </DataTable>
    </Card>
</template>
