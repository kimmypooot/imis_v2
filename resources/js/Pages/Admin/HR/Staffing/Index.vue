<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import MetricCard from '../../../../Components/Admin/MetricCard.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-4: the staffing picture for the recruitment and staffing plans — per
 * office, its plantilla items against who actually serves there; the
 * holders serving away from their item's place of assignment (on a
 * reassignment order or detail); and the vacant items.
 */
defineProps({
    offices: { type: Array, required: true },
    elsewhere: { type: Array, required: true },
    vacancies: { type: Array, required: true },
    totals: { type: Object, required: true },
});

const officeColumns = [
    { key: 'name', label: 'Office' },
    { key: 'items', label: 'Plantilla items', align: 'right' },
    { key: 'filled', label: 'Filled', align: 'right' },
    { key: 'vacant', label: 'Vacant', align: 'right' },
    { key: 'reassigned_out', label: 'Serving elsewhere', align: 'right' },
    { key: 'reassigned_in', label: 'From other offices', align: 'right' },
    { key: 'present', label: 'Actually serving here', align: 'right' },
    { key: 'without_item', label: 'Staff without an item', align: 'right' },
];

const elsewhereColumns = [
    { key: 'name', label: 'Name' },
    { key: 'position_title', label: 'Position (item)', value: (row) => `${row.position_title} ${row.item}` },
    { key: 'place_of_assignment', label: 'Place of assignment' },
    { key: 'office', label: 'Serving at' },
    { key: 'since', label: 'Since', value: (row) => row.since ?? '' },
    { key: 'order_number', label: 'Order', value: (row) => [row.kind, row.order_number].filter(Boolean).join(' ') },
];

const vacancyColumns = [
    { key: 'place_of_assignment', label: 'Place of assignment', value: (row) => row.place_of_assignment ?? '' },
    { key: 'position_title', label: 'Position' },
    { key: 'item_number', label: 'Item No.', value: (row) => row.item_number ?? '' },
    { key: 'salary_grade', label: 'SG', align: 'right', value: (row) => row.salary_grade ?? '' },
    { key: 'monthly_salary', label: 'Monthly salary', align: 'right', value: (row) => row.monthly_salary ?? '' },
];
</script>

<template>
    <Head title="Staffing" />

    <PageHeader title="Staffing" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources' }, { label: 'Staffing' }]" />

    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard label="Plantilla items" :value="totals.items" icon="grid" tone="primary">{{ totals.filled }} filled</MetricCard>
            <MetricCard label="Vacant" :value="totals.vacant" icon="clipboard" tone="warning">for the recruitment plan</MetricCard>
            <MetricCard label="Serving elsewhere" :value="totals.elsewhere" icon="refresh" tone="info">away from their place of assignment</MetricCard>
            <MetricCard label="Staff without an item" :value="totals.without_item" icon="users" tone="danger">not yet on the plantilla</MetricCard>
        </div>

        <p v-if="totals.no_place" class="rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text">
            {{ totals.no_place }} {{ totals.no_place === 1 ? 'item has' : 'items have' }} no place of assignment, so {{ totals.no_place === 1 ? 'it isn’t' : 'they aren’t' }} counted
            under any office. Set it on the <Link href="/admin/hr/plantilla" class="font-medium text-primary hover:underline">Plantilla</Link>.
        </p>

        <AdminCard title="By office" icon="building" subtitle="Where each office's positions are, against who actually serves there today.">
            <DataTable
                :columns="officeColumns"
                :rows="offices"
                caption="Staffing by office"
                export-name="imis-staffing-by-office"
                :default-sort="{ key: 'name', direction: 'asc' }"
                empty-text="Nothing to show yet. Add plantilla items (Positions or Plantilla) and link staff to them."
            >
                <template #cell-vacant="{ row }">
                    <span class="tabular-nums" :class="row.vacant ? 'font-semibold text-warning' : ''">{{ row.vacant }}</span>
                </template>
                <template #cell-present="{ row }">
                    <span class="font-semibold tabular-nums">{{ row.present }}</span>
                    <span v-if="row.present !== row.filled" class="ml-1 text-xs text-text-muted">({{ row.present > row.filled ? '+' : '' }}{{ row.present - row.filled }})</span>
                </template>
            </DataTable>
            <p class="mt-3 text-xs text-text-muted">
                Actually serving here = filled − serving elsewhere + from other offices. “Serving at” follows the reassignment history: the last reassignment, or a running detail.
                Staff without an item are counted where they serve.
            </p>
        </AdminCard>

        <AdminCard title="Serving away from their place of assignment" icon="refresh">
            <DataTable
                :columns="elsewhereColumns"
                :rows="elsewhere"
                row-key="item_id"
                caption="Personnel serving away from their place of assignment"
                export-name="imis-serving-elsewhere"
                :default-sort="{ key: 'name', direction: 'asc' }"
                empty-text="Everyone serves in their item's place of assignment."
            >
                <template #cell-name="{ row }">
                    <Link :href="`/admin/hr/employees/${row.employee_id}`" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                </template>
                <template #cell-position_title="{ row }">
                    {{ row.position_title }}
                    <div class="text-xs"><Link :href="`/admin/hr/plantilla/${row.item_id}`" class="text-primary hover:underline">{{ row.item }}</Link></div>
                </template>
                <template #cell-office="{ row }">
                    {{ row.office }}
                    <span v-if="row.detail" class="ml-1 rounded-full bg-info-bg px-2 py-0.5 text-xs text-primary">detail</span>
                </template>
                <template #cell-since="{ row }">{{ row.since ? formatCalendarDate(row.since) : '—' }}</template>
                <template #cell-order_number="{ row }">
                    <span v-if="row.order_number">{{ row.kind }} {{ row.order_number }}</span>
                    <span v-else class="text-text-muted">{{ row.kind ? `${row.kind}, no order no.` : '—' }}</span>
                </template>
            </DataTable>
        </AdminCard>

        <AdminCard title="Vacant items" icon="clipboard" subtitle="For the recruitment plan. Salaries are step 1 of the grade.">
            <DataTable
                :columns="vacancyColumns"
                :rows="vacancies"
                row-key="item_id"
                caption="Vacant plantilla items"
                export-name="imis-vacancies"
                :default-sort="{ key: 'place_of_assignment', direction: 'asc' }"
                empty-text="No vacant items."
            >
                <template #cell-place_of_assignment="{ row }">{{ row.place_of_assignment || '—' }}</template>
                <template #cell-item_number="{ row }">
                    <Link :href="`/admin/hr/plantilla/${row.item_id}`" class="text-primary hover:underline">{{ row.item_number || 'No item no.' }}</Link>
                </template>
                <template #cell-salary_grade="{ row }"><span class="tabular-nums">{{ row.salary_grade ?? '—' }}</span></template>
                <template #cell-monthly_salary="{ row }">
                    <span class="tabular-nums">{{ row.monthly_salary !== null ? formatPeso(row.monthly_salary) : '—' }}</span>
                </template>
            </DataTable>
        </AdminCard>
    </div>
</template>
