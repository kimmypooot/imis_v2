<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { leaveDates, leaveStatusTones } from '../../../../Components/HR/leaveStatus';

defineOptions({ layout: AdminLayout });

/**
 * HR-6b: every leave application (legacy hrd/applications.php), by year
 * and status. Each opens its own page (opening one is on the access trail).
 */
const props = defineProps({
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    applications: { type: Array, required: true },
});

const apply = (changes) => {
    const query = { ...props.filters, ...changes };
    router.get('/admin/hr/leave/applications', Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== '')), { preserveScroll: true });
};

const years = Array.from({ length: 6 }, (_, index) => new Date().getFullYear() + 1 - index);

const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'name', label: 'Employee' },
    { key: 'type_name', label: 'Type of leave' },
    { key: 'date_from', label: 'Dates', value: (row) => leaveDates(row, formatCalendarDate) },
    { key: 'days', label: 'Days', align: 'right' },
    { key: 'status_label', label: 'Status' },
];

const selectClass = 'rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Leave Applications" />

    <PageHeader title="Leave Applications" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Applications' }]">
        <template #actions>
            <label class="sr-only" for="apps-year">Year</label>
            <select id="apps-year" :value="filters.year" :class="selectClass" @change="apply({ year: $event.target.value })">
                <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
            </select>
            <label class="sr-only" for="apps-status">Status</label>
            <select id="apps-status" :value="filters.status ?? ''" :class="selectClass" @change="apply({ status: $event.target.value || null })">
                <option value="">All statuses</option>
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </template>
    </PageHeader>

    <Card :title="`Applications — ${filters.year}`" icon="clipboard">
        <DataTable :columns="columns" :rows="applications" caption="Leave applications" :export-name="`imis-leave-applications-${filters.year}`" empty-text="No applications.">
            <template #cell-reference_number="{ row }">
                <Link :href="`/leave/applications/${row.id}`" class="font-medium text-primary hover:underline">{{ row.reference_number }}</Link>
            </template>
            <template #cell-name="{ row }">
                {{ row.name }}
                <div v-if="row.office" class="text-xs text-text-muted">{{ row.office }}</div>
            </template>
            <template #cell-date_from="{ row }">{{ leaveDates(row, formatCalendarDate) }}</template>
            <template #cell-status_label="{ row }"><StatusBadge :status="leaveStatusTones[row.status]" :label="row.status_label" /></template>
        </DataTable>
    </Card>
</template>
