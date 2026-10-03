<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-3c: the L&D training report (ldms's hrd/training_report.php) —
 * approved trainings for a year, optionally one month and one office.
 * Print and CSV come from the DataTable.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    period: { type: String, required: true },
    years: { type: Array, required: true },
    officeOptions: { type: Array, required: true },
    rows: { type: Array, required: true },
});

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const apply = (changes) => {
    const query = { ...props.filters, ...changes };
    router.get(
        '/admin/hr/learning-development/report',
        Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== '')),
        { preserveScroll: true },
    );
};

const officeName = computed(() => props.officeOptions.find((office) => office.value === props.filters.office)?.label);
const title = computed(() => `L&D training report — ${props.period}${officeName.value ? `, ${officeName.value}` : ''}`);

const hours = (value) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
const totalHours = computed(() => props.rows.reduce((sum, row) => sum + row.hours, 0));
const people = computed(() => new Set(props.rows.map((row) => row.employee_id)).size);

const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'sex', label: 'Sex', value: (row) => row.sex ?? '' },
    { key: 'position', label: 'Position', value: (row) => row.position ?? '' },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'title', label: 'Training' },
    { key: 'sponsor', label: 'Provider', value: (row) => row.sponsor ?? '' },
    { key: 'type_label', label: 'Type' },
    { key: 'modality_label', label: 'Modality' },
    { key: 'hours', label: 'Hours', align: 'right' },
    { key: 'venue', label: 'Venue', value: (row) => row.venue ?? '' },
    { key: 'resource_speaker', label: 'Speaker(s)', value: (row) => row.resource_speaker ?? '' },
    // ISO dates sort correctly and export cleanly; the cells format them.
    { key: 'date_from', label: 'Start' },
    { key: 'date_to', label: 'End' },
];

const selectClass =
    'rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Training Report" />

    <PageHeader
        title="Training Report"
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources' }, { label: 'Training Report' }]"
    />

    <div class="space-y-6">
        <AdminCard title="Filters" icon="funnel">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label for="report-year" class="mb-1 block text-xs font-medium text-text-muted">Year</label>
                    <select id="report-year" :value="filters.year" :class="selectClass" @change="apply({ year: $event.target.value })">
                        <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
                    </select>
                </div>
                <div>
                    <label for="report-month" class="mb-1 block text-xs font-medium text-text-muted">Month</label>
                    <select id="report-month" :value="filters.month ?? ''" :class="selectClass" @change="apply({ month: $event.target.value || null })">
                        <option value="">All months</option>
                        <option v-for="(name, index) in MONTHS" :key="name" :value="index + 1">{{ name }}</option>
                    </select>
                </div>
                <div>
                    <label for="report-office" class="mb-1 block text-xs font-medium text-text-muted">Office</label>
                    <select id="report-office" :value="filters.office ?? ''" :class="selectClass" @change="apply({ office: $event.target.value || null })">
                        <option value="">All offices</option>
                        <option v-for="office in officeOptions" :key="office.value" :value="office.value">{{ office.label }}</option>
                    </select>
                </div>
                <Link
                    v-if="filters.month || filters.office"
                    :href="`/admin/hr/learning-development/report?year=${filters.year}`"
                    class="pb-2 text-sm text-primary hover:underline"
                >
                    Clear filters
                </Link>
            </div>
        </AdminCard>

        <AdminCard :title="title" icon="printer" subtitle="Approved trainings only, by surname and then most recent first.">
            <template #actions>
                <p class="text-sm text-text-muted">
                    <span class="font-semibold text-text tabular-nums">{{ rows.length }}</span> {{ rows.length === 1 ? 'training' : 'trainings' }} ·
                    <span class="font-semibold text-text tabular-nums">{{ people }}</span> {{ people === 1 ? 'employee' : 'employees' }} ·
                    <span class="font-semibold text-text tabular-nums">{{ hours(totalHours) }}</span> hours
                </p>
            </template>

            <DataTable
                :columns="columns"
                :rows="rows"
                :caption="title"
                :export-name="`imis-ld-report-${filters.year}${filters.month ? `-${String(filters.month).padStart(2, '0')}` : ''}`"
                :default-page-size="50"
                empty-text="No approved trainings for this period."
            >
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.employee_id}/learning-development?year=${filters.year}`" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                </template>
                <template #cell-hours="{ row }"><span class="tabular-nums">{{ hours(row.hours) }}</span></template>
                <template #cell-date_from="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.date_from) }}</span></template>
                <template #cell-date_to="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.date_to) }}</span></template>
            </DataTable>
        </AdminCard>
    </div>
</template>
