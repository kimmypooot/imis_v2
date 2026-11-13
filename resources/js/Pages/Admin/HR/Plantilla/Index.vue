<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import StatTile from '../../../../Components/UI/StatTile.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { plantillaItemDefaults, plantillaItemFields, plantillaItemNullable } from '../../../../Components/HR/plantillaFields';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-4: the plantilla of positions — every item with its holder and
 * monthly salary (from the salary schedule in force), filtered by
 * filled / vacant / abolished. Print and CSV from the table.
 */
const props = defineProps({
    items: { type: Array, required: true },
    counts: { type: Object, required: true },
    schedule: { type: Object, default: null },
    canManage: { type: Boolean, required: true },
    itemForm: { type: Object, default: null },
});

const views = [
    { key: 'all', label: 'All items' },
    { key: 'filled', label: 'Filled' },
    { key: 'vacant', label: 'Vacant' },
    { key: 'abolished', label: 'Abolished' },
    { key: 'elsewhere', label: 'Serving elsewhere' },
    { key: 'incomplete', label: 'Incomplete links' },
];
const view = ref('all');
const shown = computed(() => {
    if (view.value === 'elsewhere') return props.items.filter((item) => item.serving?.elsewhere);
    if (view.value === 'incomplete') return props.items.filter((item) => item.holder?.missing.length);

    return view.value === 'all' ? props.items.filter((item) => item.status !== 'abolished') : props.items.filter((item) => item.status === view.value);
});

const addOpen = ref(false);
const tones = { filled: 'active', vacant: 'warning', abolished: 'logged_out' };

const columns = [
    { key: 'item_number', label: 'Item No.' },
    { key: 'position_title', label: 'Position' },
    { key: 'salary_grade', label: 'SG', align: 'right' },
    { key: 'office', label: 'Place of assignment', value: (row) => row.office ?? '' },
    { key: 'holder', label: 'Incumbent', value: (row) => row.holder?.name ?? '' },
    { key: 'step', label: 'Step', align: 'right', value: (row) => row.holder?.step ?? '' },
    { key: 'monthly_salary', label: 'Monthly salary', align: 'right', value: (row) => row.monthly_salary ?? '' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <Head title="Plantilla" />

    <PageHeader title="Plantilla" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Plantilla' }]">
        <template v-if="canManage" #actions>
            <Link
                href="/admin/hr/plantilla-sheet"
                class="inline-flex items-center justify-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text transition-colors hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="download" size="sm" />Complete from spreadsheet
            </Link>
            <Button variant="success" @click="addOpen = true"><Icon name="plus" size="sm" />Add item</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <div class="stagger grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile label="Items" :value="counts.total" icon="grid" tone="primary">not abolished</StatTile>
            <StatTile label="Filled" :value="counts.filled" icon="users" tone="success" :progress="counts.total ? Math.round((counts.filled / counts.total) * 100) : 0">
                {{ counts.total ? Math.round((counts.filled / counts.total) * 100) : 0 }}% of items
            </StatTile>
            <StatTile label="Vacant" :value="counts.vacant" icon="clipboard" tone="warning">open for appointment</StatTile>
            <StatTile label="Abolished" :value="counts.abolished" icon="lock" tone="info">kept for the record</StatTile>
        </div>

        <p v-if="schedule" class="text-sm text-text-muted">
            Salaries from <Link href="/admin/hr/salary-schedules" class="font-medium text-link hover:underline">{{ schedule.name }}</Link>
            <template v-if="schedule.reference"> ({{ schedule.reference }})</template>, in force since {{ formatCalendarDate(schedule.effective_date) }}.
            Vacant items are priced at step 1.
        </p>
        <p v-else class="rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text">
            No salary schedule is in force yet, so salaries can't be shown.
            <Link href="/admin/hr/salary-schedules" class="font-medium text-link hover:underline">Enter the salary schedule</Link>.
        </p>

        <Card title="Items" icon="grid">
            <template #actions>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Show">
                    <button
                        v-for="option in views"
                        :key="option.key"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="view === option.key ? 'bg-primary text-white' : 'text-text hover:bg-surface-muted'"
                        :aria-pressed="view === option.key"
                        @click="view = option.key"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </template>

            <DataTable :columns="columns" :rows="shown" caption="Plantilla of positions" :export-name="`imis-plantilla-${view}`" empty-text="No items here.">
                <template #cell-item_number="{ row }">
                    <Link :href="`/admin/hr/plantilla/${row.id}`" class="font-medium text-link hover:underline">{{ row.item_number || 'No item no.' }}</Link>
                </template>
                <template #cell-position_title="{ row }">
                    {{ row.position_title }}
                    <div v-if="row.level_label" class="text-xs text-text-muted">{{ row.level_label }}</div>
                </template>
                <template #cell-office="{ row }">{{ row.office || '—' }}</template>
                <template #cell-holder="{ row }">
                    <Link v-if="row.holder" :href="`/admin/hr/employees/${row.holder.employee_id}`" class="text-link hover:underline">{{ row.holder.name }}</Link>
                    <span v-else class="text-text-muted">—</span>
                    <div v-if="row.serving?.elsewhere" class="text-xs text-info-fg">
                        Serving at {{ row.serving.office }}<template v-if="row.serving.order_number"> ({{ row.serving.order_number }})</template>
                    </div>
                    <div v-if="row.holder?.missing.length" class="text-xs text-warning-fg" :title="`Missing: ${row.holder.missing.join(', ')}`">Incomplete link</div>
                </template>
                <template #cell-step="{ row }"><span class="tabular-nums">{{ row.holder?.step ?? '—' }}</span></template>
                <template #cell-monthly_salary="{ row }"><span class="tabular-nums">{{ formatPeso(row.monthly_salary) }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="tones[row.status]" :label="row.status" /></template>
            </DataTable>
        </Card>
    </div>

    <RecordModal
        v-if="canManage"
        :open="addOpen"
        noun="Plantilla Item"
        :record="null"
        :fields="plantillaItemFields(itemForm)"
        :defaults="plantillaItemDefaults"
        :nullable="plantillaItemNullable"
        store-url="/admin/hr/plantilla"
        :update-url="(record) => `/admin/hr/plantilla/${record.id}`"
        @close="addOpen = false"
    />
</template>
