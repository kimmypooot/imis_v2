<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
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
    { key: 'position', label: 'Position', value: (row) => row.position ?? '' },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'detailed_to', label: 'Detailed to', value: (row) => row.detailed_to ?? '' },
];

const movementColumns = [
    { key: 'effective_date', label: 'Effective' },
    { key: 'name', label: 'Employee' },
    { key: 'kind_label', label: 'Kind' },
    { key: 'from_unit', label: 'From', value: (row) => row.from_unit ?? '' },
    { key: 'to_unit', label: 'To' },
    { key: 'end_date', label: 'Until', value: (row) => row.end_date ?? '' },
    { key: 'order_number', label: 'Order No.', value: (row) => row.order_number ?? '' },
    { key: 'order_date', label: 'Order date', value: (row) => row.order_date ?? '' },
];

const selectClass =
    'mt-1 block rounded-md border border-border bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Reassignments" />

    <PageHeader
        title="Reassignments"
        description="Who was assigned where, and the movements recorded between offices."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources' }, { label: 'Reassignments' }]"
    />

    <div class="space-y-6">
        <AdminCard title="Filters" icon="funnel">
            <form class="flex flex-wrap items-end gap-4" @submit.prevent="apply">
                <div>
                    <label for="filter-as-of" class="block text-xs font-medium text-text-muted">Stations as of</label>
                    <input id="filter-as-of" v-model="draft.as_of" type="date" :class="selectClass" />
                </div>
                <div>
                    <label for="filter-from" class="block text-xs font-medium text-text-muted">Movements from</label>
                    <input id="filter-from" v-model="draft.from" type="date" :class="selectClass" />
                </div>
                <div>
                    <label for="filter-to" class="block text-xs font-medium text-text-muted">to</label>
                    <input id="filter-to" v-model="draft.to" type="date" :class="selectClass" />
                </div>
                <div>
                    <label for="filter-kind" class="block text-xs font-medium text-text-muted">Kind</label>
                    <select id="filter-kind" v-model="draft.kind" :class="selectClass">
                        <option value="">All kinds</option>
                        <option v-for="option in kindOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div>
                    <label for="filter-office" class="block text-xs font-medium text-text-muted">Office</label>
                    <select id="filter-office" v-model="draft.office" :class="selectClass">
                        <option value="">All offices</option>
                        <option v-for="office in officeOptions" :key="office.value" :value="office.value">{{ office.label }}</option>
                    </select>
                </div>
                <Button type="submit">Apply</Button>
            </form>
        </AdminCard>

        <AdminCard :title="`Stations as of ${formatCalendarDate(filters.as_of)}`" icon="building" subtitle="Office from the reassignment history; details shown separately. Separated employees are left out.">
            <DataTable :columns="stationColumns" :rows="stations" row-key="employee_id" :caption="`Stations as of ${filters.as_of}`" :export-name="`imis-stations-${filters.as_of}`">
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.employee_id}`" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                </template>
                <template #cell-office="{ row }">{{ row.office || '—' }}</template>
                <template #cell-detailed_to="{ row }">{{ row.detailed_to || '—' }}</template>
            </DataTable>
        </AdminCard>

        <AdminCard
            :title="`Movements, ${formatCalendarDate(filters.from)} – ${formatCalendarDate(filters.to)}`"
            icon="activity"
            :subtitle="`${movements.length} recorded`"
        >
            <DataTable
                :columns="movementColumns"
                :rows="movements"
                :caption="`Movements ${filters.from} to ${filters.to}`"
                :export-name="`imis-movements-${filters.from}-${filters.to}`"
                empty-text="No movements recorded in this period."
            >
                <template #cell-effective_date="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.effective_date) }}</span></template>
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.employee_id}`" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                </template>
                <template #cell-kind_label="{ row }"><StatusBadge :status="tones[row.kind]" :label="row.kind_label" /></template>
                <template #cell-from_unit="{ row }">{{ row.from_unit || '—' }}</template>
                <template #cell-end_date="{ row }"><span class="whitespace-nowrap">{{ row.end_date ? formatCalendarDate(row.end_date) : '—' }}</span></template>
                <template #cell-order_number="{ row }">
                    {{ row.order_number || '—' }}
                    <a v-if="row.scan" :href="row.scan.url" target="_blank" rel="noopener" class="ml-1 text-primary hover:underline">scan</a>
                </template>
                <template #cell-order_date="{ row }"><span class="whitespace-nowrap">{{ row.order_date ? formatCalendarDate(row.order_date) : '—' }}</span></template>
            </DataTable>
        </AdminCard>
    </div>
</template>
