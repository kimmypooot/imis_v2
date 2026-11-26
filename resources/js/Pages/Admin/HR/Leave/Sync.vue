<script setup>
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import FileInput from '@cscro8/ui/Components/Forms/FileInput.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Syncing IMIS with the leave card workbook HR keeps as its master file.
 * Upload it, see each person's card against the system, choose who to
 * sync, and apply: the card history is refreshed and any difference in
 * the Vacation and Sick Leave balance is posted as one visible entry on
 * the person's leave card (which can be reversed there).
 */
const props = defineProps({
    upload: { type: Object, default: null },
    rows: { type: Array, default: null },
    summary: { type: Object, default: null },
    large: { type: Number, required: true },
});

const form = useForm({ file: null });
const send = () => form.post('/admin/hr/leave/sync', { forceFormData: true, onSuccess: () => form.reset() });

const matched = computed(() => (props.rows ?? []).filter((row) => row.ready));
const unmatched = computed(() => (props.rows ?? []).filter((row) => !row.ready));

// Everyone is selected except large differences, which want a second look first.
const selected = ref([]);
watch(
    () => props.rows,
    () => {
        selected.value = matched.value.filter((row) => row.sync.status !== 'large').map((row) => row.employee_id);
    },
    { immediate: true },
);

const show = ref('attention');
const shown = computed(() => (show.value === 'attention' ? matched.value.filter((row) => row.sync.status !== 'in_sync' || row.warnings.length) : matched.value));

const days = (value) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const signed = (value) => (Math.abs(value) < 0.0005 ? '—' : (value > 0 ? '+' : '') + days(value));
const diffClass = (value) => (Math.abs(value) >= 0.0005 ? (Math.abs(value) > props.large ? 'text-warning-fg' : 'text-link') : 'text-text-muted');
const statuses = {
    in_sync: { label: 'In sync', tone: 'success' },
    adjust: { label: 'Will adjust', tone: 'info' },
    large: { label: 'Check first', tone: 'warning' },
    check: { label: 'History only', tone: 'neutral' },
};

// Card, system and difference fold into one cell per leave; hidden columns keep each in the export.
const figure = (group, part) => (row) => (row.sync[group] ? days(row.sync[group][part]) : '');
const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'vl', label: 'Vacation leave', sortable: false, export: false },
    { key: 'sl', label: 'Sick leave', sortable: false, export: false },
    { key: 'status', label: 'Status', value: (row) => statuses[row.sync.status].label },
    { key: 'card_to', label: 'Card to', hidden: true, value: (row) => row.sync.date ?? '' },
    { key: 'vl_card', label: 'VL card', hidden: true, value: figure('vl', 'card') },
    { key: 'vl_system', label: 'VL system', hidden: true, value: figure('vl', 'system') },
    { key: 'vl_diff', label: 'VL difference', hidden: true, value: figure('vl', 'diff') },
    { key: 'sl_card', label: 'SL card', hidden: true, value: figure('sl', 'card') },
    { key: 'sl_system', label: 'SL system', hidden: true, value: figure('sl', 'system') },
    { key: 'sl_diff', label: 'SL difference', hidden: true, value: figure('sl', 'diff') },
];

const applying = ref(false);
const apply = async () => {
    const adjusting = matched.value.filter((row) => selected.value.includes(row.employee_id) && ['adjust', 'large'].includes(row.sync.status)).length;
    const ok = await confirm({
        title: `Sync ${selected.value.length} ${selected.value.length === 1 ? 'person' : 'people'} with the workbook?`,
        text: `Their card history is refreshed, and ${adjusting} ${adjusting === 1 ? 'person has' : 'people have'} a balance difference posted as an entry on their leave card. You can reverse any entry there.`,
        confirmText: 'Sync',
    });
    if (ok) {
        router.post('/admin/hr/leave/sync/apply', { employee_ids: selected.value }, { onStart: () => (applying.value = true), onFinish: () => (applying.value = false) });
    }
};
const discard = () => router.delete('/admin/hr/leave/sync');
</script>

<template>
    <Head title="Sync leave cards" />

    <PageHeader
        title="Sync with the leave card file"
        description="HR's leave card workbook is the master. Upload it to bring each person's card history and Vacation and Sick Leave balance in line."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Cards', href: '/admin/hr/leave' }, { label: 'Sync with the leave card file' }]"
    />

    <div class="space-y-6">
        <Card title="1. Upload the workbook" icon="download">
            <p class="text-sm text-text">
                Save the workbook from Excel as <strong>.xlsx</strong> and upload it. Nothing is saved until you review the comparison below and apply it. Reading a large workbook can
                take a minute.
            </p>
            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="send">
                <div class="min-w-64 flex-1">
                    <InputLabel for="sync-file">Leave card workbook (.xlsx)</InputLabel>
                    <FileInput id="sync-file" class="mt-1" accept=".xlsx" required :invalid="!!form.errors.file" @update:model-value="(file) => (form.file = file)" />
                    <InputError :message="form.errors.file" />
                </div>
                <Button type="submit" :loading="form.processing" :disabled="!form.file"><Icon name="checkCircle" size="sm" />Check the file</Button>
            </form>
        </Card>

        <Card v-if="upload && summary" :title="`2. Review: ${upload.name}`" icon="clipboard">
            <template #actions>
                <FilterButtons v-model="show" label="Show" :options="[{ value: 'attention', label: 'Needs attention' }, { value: 'all', label: 'Everyone' }]" />
            </template>

            <p class="text-sm text-text">
                {{ summary.sheets }} sheets: <strong>{{ summary.in_sync }}</strong> already in sync, <strong>{{ summary.adjust }}</strong> with a balance difference,
                <strong v-if="summary.check">{{ summary.check }} to check, </strong>
                <strong :class="summary.errors ? 'text-danger-fg' : ''">{{ summary.errors }}</strong> that could not be matched to an employee.
            </p>
            <p class="mt-1 text-xs text-text-muted">
                Difference = what the card says minus what the system has at the card's last month. Differences over {{ large }} days are not selected until you look at them.
            </p>

            <DataTable
                v-model:selected="selected"
                class="mt-4"
                :columns="columns"
                :rows="shown"
                row-key="employee_id"
                selectable
                caption="Each person's leave card compared with the system"
                :export-name="`imis-leave-card-sync`"
                :numbered="false"
                empty-text="Nothing needs attention: every matched card already agrees with the system."
            >
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :href="`/admin/hr/employees/${row.employee_id}/leave`" :subtitle="`${row.months} card months, to ${formatCalendarDate(row.sync.date)}`">
                        <ul v-if="row.warnings.length" class="mt-1 space-y-0.5 text-xs text-warning-fg">
                            <li v-for="warning in row.warnings" :key="warning">{{ warning }}</li>
                        </ul>
                    </PersonCell>
                </template>
                <template v-for="group in ['vl', 'sl']" :key="group" #[`cell-${group}`]="{ row }">
                    <template v-if="row.sync[group]">
                        <CellFacts class="tabular-nums" :facts="[{ label: 'Card', value: days(row.sync[group].card) }, { label: 'System', value: days(row.sync[group].system) }]" />
                        <p class="mt-1.5 text-2xs text-text-muted">Difference:</p>
                        <p class="text-xs font-medium tabular-nums" :class="diffClass(row.sync[group].diff)">{{ signed(row.sync[group].diff) }}</p>
                    </template>
                    <span v-else class="text-xs text-text-muted">Not on the card</span>
                </template>
                <template #cell-status="{ row }">
                    <Badge :tone="statuses[row.sync.status].tone" pill dot>{{ statuses[row.sync.status].label }}</Badge>
                </template>
            </DataTable>


            <details v-if="unmatched.length" class="mt-4 rounded-md border border-border p-3 text-sm">
                <summary class="cursor-pointer font-medium text-text">{{ unmatched.length }} sheets not matched to an employee (skipped)</summary>
                <ul class="mt-2 space-y-1 text-text-muted">
                    <li v-for="row in unmatched" :key="row.sheet"><span class="font-medium text-text">{{ row.sheet }}:</span> {{ row.errors[0] }}</li>
                </ul>
            </details>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-text-muted">{{ selected.length }} selected</p>
                <div class="flex flex-wrap gap-2">
                    <Button variant="secondary" @click="discard" icon="close">Discard</Button>
                    <Button variant="success" :disabled="!selected.length" :loading="applying" @click="apply">
                        Sync {{ selected.length }} {{ selected.length === 1 ? 'person' : 'people' }}
                    </Button>
                </div>
            </div>
        </Card>
    </div>
</template>
