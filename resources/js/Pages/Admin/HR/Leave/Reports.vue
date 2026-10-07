<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import { formatCalendarDate, formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-6c: leave reports (legacy hrd/reports.php). The balance summary is
 * Leave Credits and the applications register is Leave Applications.
 */
const props = defineProps({
    reports: { type: Array, required: true },
    filters: { type: Object, required: true },
    rows: { type: Array, required: true },
});

const apply = (changes) => router.get('/admin/hr/leave/reports', { ...props.filters, ...changes }, { preserveScroll: true });
const years = Array.from({ length: 6 }, (_, index) => new Date().getFullYear() + 1 - index);
const days = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
const range = (row) => (row.date_from === row.date_to ? formatCalendarDate(row.date_from) : `${formatCalendarDate(row.date_from)} – ${formatCalendarDate(row.date_to)}`);

const columns = computed(() => ({
    on_leave: [
        { key: 'name', label: 'Employee' },
        { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
        { key: 'type', label: 'Leave' },
        { key: 'date_from', label: 'Dates', value: range },
        { key: 'reference_number', label: 'Reference' },
    ],
    utilization: [
        { key: 'type', label: 'Leave type' },
        { key: 'filed', label: 'Filed', align: 'right' },
        { key: 'approved', label: 'Approved', align: 'right' },
        { key: 'disapproved', label: 'Disapproved', align: 'right' },
        { key: 'pending', label: 'Open', align: 'right' },
        { key: 'days_with_pay', label: 'Days with pay', align: 'right' },
        { key: 'days_without_pay', label: 'Days without pay', align: 'right' },
    ],
    forced_leave: [
        { key: 'name', label: 'Employee' },
        { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
        { key: 'vl_balance', label: 'VL balance', align: 'right' },
        { key: 'taken', label: 'VL/FL taken this year', align: 'right' },
        { key: 'short', label: 'Still to take', align: 'right' },
    ],
    without_pay: [
        { key: 'name', label: 'Employee' },
        { key: 'type', label: 'Leave' },
        { key: 'date_from', label: 'Dates', value: range },
        { key: 'days_without_pay', label: 'Days without pay', align: 'right' },
        { key: 'reference_number', label: 'Reference' },
    ],
    adjustments: [
        { key: 'posted_at', label: 'Posted' },
        { key: 'name', label: 'Employee' },
        { key: 'code', label: 'Type' },
        { key: 'kind', label: 'Kind' },
        { key: 'amount', label: 'Days', align: 'right' },
        { key: 'particulars', label: 'Particulars' },
        { key: 'recorded_by', label: 'By', value: (row) => row.recorded_by ?? '' },
    ],
})[props.filters.report]);

const title = computed(() => props.reports.find((report) => report.value === props.filters.report)?.label);
const subtitle = computed(() => (props.filters.report === 'on_leave' ? formatCalendarDate(props.filters.day) : String(props.filters.year)));
const selectClass = 'rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Leave Reports" />

    <PageHeader title="Leave Reports" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Reports' }]">
        <template #actions>
            <label class="sr-only" for="report-kind">Report</label>
            <select id="report-kind" :value="filters.report" :class="selectClass" @change="apply({ report: $event.target.value })">
                <option v-for="report in reports" :key="report.value" :value="report.value">{{ report.label }}</option>
            </select>
            <template v-if="filters.report === 'on_leave'">
                <label class="sr-only" for="report-day">Day</label>
                <input id="report-day" type="date" :value="filters.day" :class="selectClass" @change="apply({ day: $event.target.value })" />
            </template>
            <template v-else>
                <label class="sr-only" for="report-year">Year</label>
                <select id="report-year" :value="filters.year" :class="selectClass" @change="apply({ year: $event.target.value })">
                    <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
                </select>
            </template>
        </template>
    </PageHeader>

    <p class="mb-4 text-sm text-text-muted">
        Also: the balance summary is <Link href="/admin/hr/leave" class="text-primary hover:underline">Leave Credits</Link>,
        and the applications register is <Link href="/admin/hr/leave/applications" class="text-primary hover:underline">Leave Applications</Link>.
    </p>

    <Card :title="title" icon="printer" :description="subtitle">
        <DataTable :key="filters.report" :columns="columns" :rows="rows" :caption="`${title}, ${subtitle}`" :export-name="`imis-leave-${filters.report}`" empty-text="Nothing to report.">
            <template #cell-date_from="{ row }"><span class="whitespace-nowrap">{{ range(row) }}</span></template>
            <template #cell-posted_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.posted_at) }}</span></template>
            <template v-for="key in ['vl_balance', 'taken', 'short', 'days_with_pay', 'days_without_pay', 'amount']" :key="key" #[`cell-${key}`]="{ row }">
                <span class="tabular-nums">{{ days(row[key]) }}</span>
            </template>
        </DataTable>
    </Card>
</template>
