<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
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

// Five columns on screen, every field in the CSV: the hidden columns are folded
// into the person, training and details cells.
const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'sex', label: 'Sex', value: (row) => row.sex ?? '', hidden: true },
    { key: 'position', label: 'Position', value: (row) => row.position ?? '', hidden: true },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '', hidden: true },
    { key: 'title', label: 'Training' },
    { key: 'sponsor', label: 'Provider', value: (row) => row.sponsor ?? '', hidden: true },
    { key: 'type_label', label: 'Type' },
    { key: 'modality_label', label: 'Modality', hidden: true },
    { key: 'hours', label: 'Hours', align: 'right' },
    { key: 'venue', label: 'Venue', value: (row) => row.venue ?? '', hidden: true },
    { key: 'resource_speaker', label: 'Speaker(s)', value: (row) => row.resource_speaker ?? '', hidden: true },
    // ISO dates sort correctly and export cleanly; the cells format them.
    { key: 'dates', label: 'Dates', value: (row) => row.date_from, export: false },
    { key: 'date_from', label: 'Start', hidden: true },
    { key: 'date_to', label: 'End', hidden: true },
];

</script>

<template>
    <Head title="Training Report" />

    <PageHeader
        title="Training Report"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Training Report' }]"
    />

    <div class="space-y-6">
        <Card title="Filters" icon="funnel">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label for="report-year" class="mb-1 block text-xs font-medium text-text-muted">Year</label>
                    <select id="report-year" :value="filters.year" class="filter-control" @change="apply({ year: $event.target.value })">
                        <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
                    </select>
                </div>
                <div>
                    <label for="report-month" class="mb-1 block text-xs font-medium text-text-muted">Month</label>
                    <select id="report-month" :value="filters.month ?? ''" class="filter-control" @change="apply({ month: $event.target.value || null })">
                        <option value="">All months</option>
                        <option v-for="(name, index) in MONTHS" :key="name" :value="index + 1">{{ name }}</option>
                    </select>
                </div>
                <div>
                    <label for="report-office" class="mb-1 block text-xs font-medium text-text-muted">Office</label>
                    <select id="report-office" :value="filters.office ?? ''" class="filter-control" @change="apply({ office: $event.target.value || null })">
                        <option value="">All offices</option>
                        <option v-for="office in officeOptions" :key="office.value" :value="office.value">{{ office.label }}</option>
                    </select>
                </div>
                <Link
                    v-if="filters.month || filters.office"
                    :href="`/admin/hr/learning-development/report?year=${filters.year}`"
                    class="pb-2 text-sm text-link hover:underline"
                >
                    Clear filters
                </Link>
            </div>
        </Card>

        <Card :title="title" icon="printer" description="Approved trainings only, by surname and then most recent first.">
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
                :numbered="false"
                empty-text="No approved trainings for this period."
            >
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :href="`/admin/hr/employees/${row.employee_id}/learning-development?year=${filters.year}`" :subtitle="[row.position, row.office, row.sex].filter(Boolean).join(' · ')" />
                </template>
                <template #cell-title="{ row }">
                    {{ row.title }}
                    <div v-if="row.sponsor" class="text-xs text-text-muted">{{ row.sponsor }}</div>
                    <div v-if="row.venue" class="text-xs text-text-muted">{{ row.venue }}</div>
                    <div v-if="row.resource_speaker" class="text-xs text-text-muted">Speaker: {{ row.resource_speaker }}</div>
                </template>
                <template #cell-type_label="{ row }">
                    <CellFacts :facts="[{ label: 'Type', value: row.type_label }, { label: 'Modality', value: row.modality_label }]" />
                </template>
                <template #cell-hours="{ row }"><span class="tabular-nums">{{ hours(row.hours) }}</span></template>
                <template #cell-dates="{ row }">
                    <span class="whitespace-nowrap">{{ formatCalendarDate(row.date_from) }}</span>
                    <div v-if="row.date_to !== row.date_from" class="text-xs whitespace-nowrap text-text-muted">to {{ formatCalendarDate(row.date_to) }}</div>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
