<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import StatTile from '@cscro8/ui/Components/UI/StatTile.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-4: the staffing picture for the recruitment and staffing plans — per
 * office, its plantilla items against who actually serves there; the
 * holders serving away from their item's place of assignment (on a
 * reassignment order or detail); offices headed by designation in a
 * concurrent capacity; and the vacant items.
 */
defineProps({
    offices: { type: Array, required: true },
    elsewhere: { type: Array, required: true },
    vacancies: { type: Array, required: true },
    designations: { type: Array, required: true },
    totals: { type: Object, required: true },
});

// Related figures share a cell (CellFacts); the hidden columns keep each one in the CSV.
const officeColumns = [
    { key: 'name', label: 'Office' },
    { key: 'plantilla', label: 'Plantilla', value: (row) => row.items, export: false },
    { key: 'movement', label: 'Movement', value: (row) => row.reassigned_out + row.reassigned_in, export: false },
    { key: 'present', label: 'Actually serving here', align: 'right' },
    { key: 'without_item', label: 'Without an item', align: 'right' },
    { key: 'items', label: 'Plantilla items', hidden: true },
    { key: 'filled', label: 'Filled', hidden: true },
    { key: 'vacant', label: 'Vacant', hidden: true },
    { key: 'reassigned_out', label: 'Serving elsewhere', hidden: true },
    { key: 'reassigned_in', label: 'From other offices', hidden: true },
];

const elsewhereColumns = [
    { key: 'name', label: 'Name' },
    { key: 'position_title', label: 'Position (item)', value: (row) => `${row.position_title} ${row.item}` },
    { key: 'office', label: 'Assignment', value: (row) => `${row.place_of_assignment} ${row.office}`, export: false },
    { key: 'since', label: 'Since', value: (row) => row.since ?? '' },
    { key: 'place_of_assignment', label: 'Place of assignment', hidden: true },
    { key: 'serving_at', label: 'Serving at', value: (row) => row.office, hidden: true },
    { key: 'order_number', label: 'Order', value: (row) => [row.kind, row.order_number].filter(Boolean).join(' '), hidden: true },
];

const designationColumns = [
    { key: 'office', label: 'Office' },
    { key: 'name', label: 'Headed by' },
    { key: 'position_title', label: 'Position (item)', value: (row) => [row.position_title, row.item].filter(Boolean).join(' ') },
    { key: 'since', label: 'Since' },
    { key: 'capacity', label: 'As', hidden: true },
    { key: 'home_office', label: 'Their own office', value: (row) => row.home_office ?? '', hidden: true },
    { key: 'order_number', label: 'Order', value: (row) => row.order_number ?? '', hidden: true },
];

const vacancyColumns = [
    { key: 'place_of_assignment', label: 'Place of assignment', value: (row) => row.place_of_assignment ?? '' },
    { key: 'position_title', label: 'Position', value: (row) => `${row.position_title} ${row.item_number ?? ''}`, export: false },
    { key: 'salary_grade', label: 'SG', align: 'right', value: (row) => row.salary_grade ?? '' },
    { key: 'monthly_salary', label: 'Monthly salary', align: 'right', value: (row) => row.monthly_salary ?? '' },
    { key: 'selected', label: 'Selected (Recruitment)', value: (row) => row.selected ?? '' },
    { key: 'position', label: 'Position', value: (row) => row.position_title, hidden: true },
    { key: 'item_number', label: 'Item No.', value: (row) => row.item_number ?? '', hidden: true },
];
</script>

<template>
    <Head title="Staffing" />

    <PageHeader title="Staffing" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Staffing' }]" />

    <div class="space-y-6">
        <div class="stagger grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile label="Plantilla items" :value="totals.items" icon="grid" tone="primary">{{ totals.filled }} filled</StatTile>
            <StatTile label="Vacant" :value="totals.vacant" icon="clipboard" tone="warning">for the recruitment plan</StatTile>
            <StatTile label="Serving elsewhere" :value="totals.elsewhere" icon="refresh" tone="info">away from their place of assignment</StatTile>
            <StatTile label="Staff without an item" :value="totals.without_item" icon="users" tone="info">not yet on the plantilla</StatTile>
        </div>

        <p v-if="totals.no_place" class="rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text">
            {{ totals.no_place }} {{ totals.no_place === 1 ? 'item has' : 'items have' }} no place of assignment, so {{ totals.no_place === 1 ? 'it isn’t' : 'they aren’t' }} counted
            under any office. Set it on the <Link href="/admin/hr/plantilla" class="font-medium text-link hover:underline">Plantilla</Link>.
        </p>

        <p v-if="totals.headed_by_designation" class="rounded-md border border-info-border bg-info-bg px-4 py-3 text-sm text-text">
            {{ totals.headed_by_designation }} {{ totals.headed_by_designation === 1 ? 'office is' : 'offices are' }} headed by designation, by someone who also
            heads their own office. See <a href="#headed-by-designation" class="font-medium text-link hover:underline">Offices headed by designation</a> for the staffing plan.
        </p>

        <Card title="By office" icon="building" description="Where each office's positions are, against who actually serves there today.">
            <DataTable
                :columns="officeColumns"
                :rows="offices"
                caption="Staffing by office"
                export-name="imis-staffing-by-office"
                :default-sort="{ key: 'name', direction: 'asc' }"
                :numbered="false"
                empty-text="Nothing to show yet. Add plantilla items (Positions or Plantilla) and link staff to them."
            >
                <template #cell-name="{ row }">
                    {{ row.name }}
                    <div v-if="row.headed_by.length" class="mt-0.5 text-xs text-text-muted">
                        <span class="rounded-full bg-info-bg px-2 py-0.5 text-link">headed by designation</span>
                        {{ row.headed_by.join(', ') }}
                    </div>
                    <div v-if="row.also_heads.length" class="mt-0.5 text-xs text-text-muted">Its head also heads {{ row.also_heads.join(', ') }}</div>
                </template>
                <template #cell-plantilla="{ row }">
                    <CellFacts :facts="[{ label: 'Items', value: row.items }, { label: 'Filled', value: row.filled }, { label: 'Vacant', value: row.vacant }]" />
                </template>
                <template #cell-movement="{ row }">
                    <CellFacts :facts="[{ label: 'Serving elsewhere', value: row.reassigned_out }, { label: 'From other offices', value: row.reassigned_in }]" />
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
        </Card>

        <Card title="Serving away from their place of assignment" icon="refresh">
            <DataTable
                :columns="elsewhereColumns"
                :rows="elsewhere"
                row-key="item_id"
                caption="Personnel serving away from their place of assignment"
                export-name="imis-serving-elsewhere"
                :default-sort="{ key: 'name', direction: 'asc' }"
                :numbered="false"
                empty-text="Everyone serves in their item's place of assignment."
            >
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :href="`/admin/hr/employees/${row.employee_id}`" />
                </template>
                <template #cell-position_title="{ row }">
                    {{ row.position_title }}
                    <div class="text-xs"><Link :href="`/admin/hr/plantilla/${row.item_id}`" class="text-link hover:underline">{{ row.item }}</Link></div>
                </template>
                <template #cell-office="{ row }">
                    <CellFacts wrap :facts="[{ label: 'Place of assignment', value: row.place_of_assignment }, { label: 'Serving at', value: row.office }]" />
                    <span v-if="row.detail" class="mt-1 inline-block rounded-full bg-info-bg px-2 py-0.5 text-xs text-link">detail</span>
                </template>
                <template #cell-since="{ row }">
                    <span class="whitespace-nowrap">{{ row.since ? formatCalendarDate(row.since) : '—' }}</span>
                    <div class="text-xs text-text-muted">{{ row.order_number ? `${row.kind ?? ''} ${row.order_number}`.trim() : row.kind ? `${row.kind}, no order no.` : '' }}</div>
                </template>
            </DataTable>
        </Card>

        <Card
            v-if="designations.length"
            id="headed-by-designation"
            title="Offices headed by designation"
            icon="users"
            description="Headed by designation by someone who stays in, and holds an item in, their own office (or shares it as one place of assignment). They aren't counted as serving here."
        >
            <DataTable
                :columns="designationColumns"
                :rows="designations"
                caption="Offices headed by designation"
                export-name="imis-headed-by-designation"
                :default-sort="{ key: 'office', direction: 'asc' }"
                :numbered="false"
                empty-text="No office is headed by designation."
            >
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :href="`/admin/hr/employees/${row.employee_id}`" :subtitle="[row.capacity, row.home_office && `own office: ${row.home_office}`].filter(Boolean).join(' · ')" />
                </template>
                <template #cell-position_title="{ row }">
                    {{ row.position_title || '—' }}
                    <div v-if="row.item_id" class="text-xs"><Link :href="`/admin/hr/plantilla/${row.item_id}`" class="text-link hover:underline">{{ row.item }}</Link></div>
                </template>
                <template #cell-since="{ row }">
                    {{ formatCalendarDate(row.since) }}
                    <div class="text-xs text-text-muted">{{ row.until ? `until ${formatCalendarDate(row.until)}` : 'until further notice' }}</div>
                    <div class="text-xs text-text-muted">{{ row.order_number ? `Order ${row.order_number}` : 'No order no.' }}</div>
                </template>
            </DataTable>
        </Card>

        <Card title="Vacant items" icon="clipboard" description="For the recruitment plan. Salaries are step 1 of the grade.">
            <DataTable
                :columns="vacancyColumns"
                :rows="vacancies"
                row-key="item_id"
                caption="Vacant plantilla items"
                export-name="imis-vacancies"
                :default-sort="{ key: 'place_of_assignment', direction: 'asc' }"
                :numbered="false"
                empty-text="No vacant items."
            >
                <template #cell-place_of_assignment="{ row }">{{ row.place_of_assignment || '—' }}</template>
                <template #cell-position_title="{ row }">
                    {{ row.position_title }}
                    <div class="text-xs"><Link :href="`/admin/hr/plantilla/${row.item_id}`" class="text-link hover:underline">{{ row.item_number || 'No item no.' }}</Link></div>
                </template>
                <template #cell-salary_grade="{ row }"><span class="tabular-nums">{{ row.salary_grade ?? '—' }}</span></template>
                <template #cell-monthly_salary="{ row }">
                    <span class="tabular-nums">{{ row.monthly_salary !== null ? formatPeso(row.monthly_salary) : '—' }}</span>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
