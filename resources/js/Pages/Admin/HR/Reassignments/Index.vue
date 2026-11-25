<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-1b reports: where everyone was assigned on a date (office, and any
 * detail running), and the movements recorded in a period. Supervisors
 * see their own office's people only.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    stations: { type: Array, required: true },
    movements: { type: Array, required: true },
    kindOptions: { type: Array, required: true },
    officeOptions: { type: Array, required: true },
});

const draft = reactive({ ...props.filters, kind: props.filters.kind ?? '', office: props.filters.office ?? '' });

const apply = () =>
    router.get(
        '/admin/hr/reassignments',
        Object.fromEntries(Object.entries(draft).filter(([, value]) => value !== null && value !== '')),
        { preserveScroll: true },
    );

const tones = { reassignment: 'info', detail: 'warning', designation: 'draft', return: 'logged_out' };

const stationColumns = [
    { key: 'name', label: 'Employee' },
    { key: 'position', label: 'Position', value: (row) => row.position ?? '', hidden: true },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'detailed_to', label: 'Detailed to', value: (row) => row.detailed_to ?? '' },
];

// Kind, from and to share the "Movement" cell; the end date and order date sit under
// their neighbours. Hidden columns keep each in the CSV.
const movementColumns = [
    { key: 'effective_date', label: 'Effective' },
    { key: 'name', label: 'Employee' },
    { key: 'movement', label: 'Movement', value: (row) => `${row.kind_label} ${row.from_unit ?? ''} ${row.to_unit}`, export: false },
    { key: 'order_number', label: 'Order No.', value: (row) => row.order_number ?? '' },
    { key: 'kind_label', label: 'Kind', hidden: true },
    { key: 'from_unit', label: 'From', value: (row) => row.from_unit ?? '', hidden: true },
    { key: 'to_unit', label: 'To', hidden: true },
    { key: 'end_date', label: 'Until', value: (row) => row.end_date ?? '', hidden: true },
    { key: 'order_date', label: 'Order date', value: (row) => row.order_date ?? '', hidden: true },
];

</script>

<template>
    <Head title="Reassignments" />

    <PageHeader
        title="Reassignments"
        description="Who was assigned where, and the movements recorded between offices."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Reassignments' }]"
    />

    <div class="space-y-6">
        <Card title="Filters" icon="funnel">
            <form class="flex flex-wrap items-end gap-4" @submit.prevent="apply">
                <div>
                    <label for="filter-as-of" class="block text-xs font-medium text-text-muted">Stations as of</label>
                    <input id="filter-as-of" v-model="draft.as_of" type="date" class="filter-control" />
                </div>
                <div>
                    <label for="filter-from" class="block text-xs font-medium text-text-muted">Movements from</label>
                    <input id="filter-from" v-model="draft.from" type="date" class="filter-control" />
                </div>
                <div>
                    <label for="filter-to" class="block text-xs font-medium text-text-muted">to</label>
                    <input id="filter-to" v-model="draft.to" type="date" class="filter-control" />
                </div>
                <div>
                    <label for="filter-kind" class="block text-xs font-medium text-text-muted">Kind</label>
                    <select id="filter-kind" v-model="draft.kind" class="filter-control">
                        <option value="">All kinds</option>
                        <option v-for="option in kindOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div>
                    <label for="filter-office" class="block text-xs font-medium text-text-muted">Office</label>
                    <select id="filter-office" v-model="draft.office" class="filter-control">
                        <option value="">All offices</option>
                        <option v-for="office in officeOptions" :key="office.value" :value="office.value">{{ office.label }}</option>
                    </select>
                </div>
                <Button type="submit" icon="funnel">Apply</Button>
            </form>
        </Card>

        <Card :title="`Stations as of ${formatCalendarDate(filters.as_of)}`" icon="building" description="Office from the reassignment history; details shown separately. Separated employees are left out.">
            <DataTable :columns="stationColumns" :rows="stations" row-key="employee_id" :caption="`Stations as of ${filters.as_of}`" :export-name="`imis-stations-${filters.as_of}`" :numbered="false">
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :href="`/admin/hr/employees/${row.employee_id}`" :subtitle="row.position" />
                </template>
                <template #cell-office="{ row }">{{ row.office || '—' }}</template>
                <template #cell-detailed_to="{ row }">{{ row.detailed_to || '—' }}</template>
            </DataTable>
        </Card>

        <Card
            :title="`Movements, ${formatCalendarDate(filters.from)} – ${formatCalendarDate(filters.to)}`"
            icon="activity"
            :description="`${movements.length} recorded`"
        >
            <DataTable
                :columns="movementColumns"
                :rows="movements"
                :caption="`Movements ${filters.from} to ${filters.to}`"
                :export-name="`imis-movements-${filters.from}-${filters.to}`"
                :numbered="false"
                empty-text="No movements recorded in this period."
            >
                <template #cell-effective_date="{ row }">
                    <span class="whitespace-nowrap">{{ formatCalendarDate(row.effective_date) }}</span>
                    <div v-if="row.end_date" class="text-xs whitespace-nowrap text-text-muted">until {{ formatCalendarDate(row.end_date) }}</div>
                </template>
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :href="`/admin/hr/employees/${row.employee_id}`" />
                </template>
                <template #cell-movement="{ row }">
                    <StatusBadge :status="tones[row.kind]" :label="row.kind_label" />
                    <CellFacts wrap class="mt-1.5" :facts="[{ label: 'From', value: row.from_unit || '—' }, { label: 'To', value: row.to_unit }]" />
                </template>
                <template #cell-order_number="{ row }">
                    {{ row.order_number || '—' }}
                    <a v-if="row.scan" :href="row.scan.url" target="_blank" rel="noopener" class="ml-1 text-link hover:underline">scan</a>
                    <div v-if="row.order_date" class="text-xs whitespace-nowrap text-text-muted">{{ formatCalendarDate(row.order_date) }}</div>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
