<script setup>
import { computed, ref } from 'vue';
import AdminCard from '../UI/AdminCard.vue';
import DataTable from '../UI/DataTable.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import Icon from '../UI/Icon.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * One person's Learning & Development log for a year (ldms's "My L&D
 * Interventions"): summary cards, month tabs, filters and the trainings.
 * Shared by "My Learning & Development" and HRD's per-employee page; each
 * fills the row actions through the `actions` slot.
 */
const props = defineProps({
    year: { type: Number, required: true },
    records: { type: Array, required: true },
    stats: { type: Object, required: true },
    typeOptions: { type: Array, required: true },
    modalityOptions: { type: Array, required: true },
    caption: { type: String, required: true },
    exportName: { type: String, required: true },
    pendingNote: { type: String, default: 'waiting for HRD review' },
});

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const month = ref(null); // null = all months
const type = ref('');
const modality = ref('');

const monthOf = (record) => Number(record.date_from.slice(5, 7)) - 1;
const monthCounts = computed(() => MONTHS.map((_, index) => props.records.filter((record) => monthOf(record) === index).length));

const shown = computed(() =>
    props.records.filter(
        (record) =>
            (month.value === null || monthOf(record) === month.value) &&
            (!type.value || record.ld_type === type.value) &&
            (!modality.value || record.modality === modality.value),
    ),
);

const cards = computed(() => [
    { label: 'Trainings', value: props.stats.total, icon: 'clipboard' },
    { label: 'Total hours', value: props.stats.hours.toLocaleString(undefined, { maximumFractionDigits: 2 }), icon: 'clock' },
    ...props.typeOptions.filter((option) => option.value !== 'other').map((option) => ({ label: option.label, value: props.stats.by_type[option.value] ?? 0, icon: 'chartBar' })),
]);

const badge = { pending: 'info', approved: 'active', rejected: 'inactive' };

const columns = [
    { key: 'title', label: 'Training', value: (row) => row.title },
    { key: 'dates', label: 'Dates', value: (row) => `${row.date_from} – ${row.date_to}` },
    { key: 'hours', label: 'Hours', align: 'right', value: (row) => Number(row.hours) },
    { key: 'type_label', label: 'Type' },
    { key: 'modality_label', label: 'Modality' },
    { key: 'venue', label: 'Venue', value: (row) => row.venue ?? '' },
    { key: 'resource_speaker', label: 'Speaker/s', value: (row) => row.resource_speaker ?? '' },
    { key: 'status', label: 'Status', align: 'center', value: (row) => row.status_label },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '8rem' },
];
</script>

<template>
    <div class="space-y-6">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div v-for="card in cards" :key="card.label" class="rounded-lg bg-surface p-4 shadow-sm ring-1 ring-border/60">
                <dt class="flex items-center gap-1.5 text-xs font-medium text-text-muted"><Icon :name="card.icon" size="sm" />{{ card.label }}</dt>
                <dd class="mt-1 text-2xl font-bold text-text tabular-nums">{{ card.value }}</dd>
            </div>
        </dl>
        <p v-if="stats.pending" class="-mt-3 text-xs text-text-muted">
            {{ stats.pending }} of these {{ stats.pending === 1 ? 'is' : 'are' }} {{ pendingNote }}. Rejected trainings aren't counted.
        </p>

        <AdminCard :title="`Training records — ${year}`" icon="clipboard">
            <div class="mb-4 flex flex-wrap gap-1" role="group" aria-label="Filter by month">
                <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-medium"
                    :class="month === null ? 'bg-primary text-white' : 'bg-surface-muted text-text hover:bg-info-bg'"
                    :aria-pressed="month === null"
                    @click="month = null"
                >
                    All ({{ records.length }})
                </button>
                <button
                    v-for="(name, index) in MONTHS"
                    :key="name"
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-medium"
                    :class="month === index ? 'bg-primary text-white' : monthCounts[index] ? 'bg-surface-muted text-text hover:bg-info-bg' : 'bg-surface-muted text-text-muted/70 hover:bg-info-bg'"
                    :aria-pressed="month === index"
                    @click="month = index"
                >
                    {{ name.slice(0, 3) }}<span v-if="monthCounts[index]"> ({{ monthCounts[index] }})</span>
                </button>
            </div>

            <div class="mb-4 flex flex-wrap gap-3">
                <div>
                    <label for="ld-filter-type" class="sr-only">Type</label>
                    <select id="ld-filter-type" v-model="type" class="rounded-md border border-border bg-surface px-3 py-1.5 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring">
                        <option value="">All types</option>
                        <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <div>
                    <label for="ld-filter-modality" class="sr-only">Modality</label>
                    <select id="ld-filter-modality" v-model="modality" class="rounded-md border border-border bg-surface px-3 py-1.5 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring">
                        <option value="">All modalities</option>
                        <option v-for="option in modalityOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
            </div>

            <DataTable :columns="columns" :rows="shown" :caption="caption" :export-name="exportName">
                <template #cell-title="{ row }">
                    <div class="font-medium text-text">{{ row.title }}</div>
                    <div class="text-xs text-text-muted">{{ row.sponsor }}</div>
                    <a
                        v-if="row.certificate"
                        :href="`/hr/trainings/${row.id}/certificate`"
                        target="_blank"
                        rel="noopener"
                        class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                    >
                        <Icon name="clipboard" size="sm" />Certificate<span class="sr-only"> (opens in a new tab)</span>
                    </a>
                </template>
                <template #cell-dates="{ row }">
                    <span class="whitespace-nowrap">{{ formatCalendarDate(row.date_from) }}</span>
                    <template v-if="row.date_to !== row.date_from"><br /><span class="whitespace-nowrap text-text-muted">to {{ formatCalendarDate(row.date_to) }}</span></template>
                </template>
                <template #cell-venue="{ row }">{{ row.venue || '—' }}</template>
                <template #cell-resource_speaker="{ row }">{{ row.resource_speaker || '—' }}</template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="badge[row.approval_status]" :label="row.status_label" />
                    <p v-if="row.rejection_reason" class="mt-1 max-w-48 text-left text-xs text-danger">{{ row.rejection_reason }}</p>
                </template>
                <template #cell-actions="{ row }">
                    <div class="flex justify-center gap-1">
                        <slot name="actions" :row="row" />
                    </div>
                </template>
            </DataTable>
        </AdminCard>
    </div>
</template>
