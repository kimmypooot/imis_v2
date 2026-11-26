<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import LoadingState from '@cscro8/ui/Components/UI/LoadingState.vue';
import Tabs from '@cscro8/ui/Components/UI/Tabs.vue';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';

/**
 * Biometric logs: what uploading the chosen .dat would do, before it is
 * uploaded. The server reads it with the same rules as the upload and
 * saves nothing: new punches against those already on file, what would be
 * skipped, whose punches it holds, and its first lines.
 */
const props = defineProps({
    // The chosen file; the dialog is open while this is set.
    file: { type: Object, default: null },
    knownOnly: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'upload']);

const loading = ref(false);
const failed = ref('');
const preview = ref(null);
const tab = ref('people');

const xsrfToken = () => decodeURIComponent(document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

const load = async () => {
    loading.value = true;
    failed.value = '';
    preview.value = null;
    tab.value = 'people';
    const body = new FormData();
    body.append('file', props.file);
    body.append('known_only', props.knownOnly ? '1' : '0');
    try {
        const response = await fetch('/admin/hr/dtr/import/preview', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body,
        });
        const data = await response.json().catch(() => null);
        if (!response.ok) {
            failed.value = data?.errors?.file?.[0] ?? data?.message ?? 'The file could not be read. Try again, or upload it as it is.';
            return;
        }
        preview.value = data;
    } catch {
        failed.value = 'The preview could not be loaded. Check your connection and try again.';
    } finally {
        loading.value = false;
    }
};

watch(() => props.file, (file) => file && load());

const number = (value) => Number(value ?? 0).toLocaleString();
const monthName = (key) => new Date(`${key}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
const counts = computed(() => preview.value?.counts ?? {});

const tiles = computed(() => [
    { label: 'Lines read', value: counts.value.read },
    { label: 'New', value: counts.value.new, tone: 'text-success-fg' },
    { label: 'Already on file', value: counts.value.already },
    { label: 'Left out (no profile)', value: counts.value.left_out, hideZero: true },
    { label: 'Before the start date', value: counts.value.before_start, hideZero: true },
    { label: 'Unreadable', value: counts.value.unreadable, tone: counts.value.unreadable ? 'text-warning-fg' : '' },
].filter((tile) => !(tile.hideZero && !tile.value)));

const tabs = computed(() => [
    { key: 'people', label: `By Biometric ID (${preview.value?.people.length ?? 0})`, icon: 'users' },
    { key: 'sample', label: 'First lines', icon: 'documentText' },
]);

const peopleColumns = [
    { key: 'biometric_id', label: 'Biometric ID' },
    { key: 'name', label: 'Employee', value: (row) => row.name ?? 'Not on any profile' },
    { key: 'figures', label: 'In this file', sortable: false, export: false },
    { key: 'new', label: 'New', align: 'right' },
    { key: 'punches', label: 'Punches', hidden: true },
    { key: 'first_at', label: 'First', hidden: true },
    { key: 'last_at', label: 'Last', hidden: true },
];
const sampleStatus = {
    new: { label: 'New', tone: 'success' },
    already: { label: 'Already on file', tone: 'neutral' },
    left_out: { label: 'Left out', tone: 'warning' },
    before_start: { label: 'Before the start date', tone: 'neutral' },
};
const sampleColumns = [
    { key: 'punched_at', label: 'Punch', value: (row) => row.punched_at },
    { key: 'biometric_id', label: 'Biometric ID' },
    { key: 'name', label: 'Employee', value: (row) => row.name ?? 'Not on any profile' },
    { key: 'status', label: 'On upload', value: (row) => sampleStatus[row.status].label },
];
</script>

<template>
    <Modal :open="file !== null" :title="file ? `Preview: ${file.name}` : 'Preview'" icon="eye" size="xl" @close="emit('close')">
        <LoadingState v-if="loading" label="Reading the file and checking each punch against those already on file…" />
        <Alert v-else-if="failed" tone="danger" title="No preview">{{ failed }}</Alert>
        <div v-else-if="preview" class="space-y-4">
            <p class="text-sm text-text-muted">
                Nothing is saved yet.
                <template v-if="preview.first_at">The punches to keep run from {{ formatCalendarDate(preview.first_at.slice(0, 10)) }} to {{ formatCalendarDate(preview.last_at.slice(0, 10)) }}.</template>
            </p>

            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="tile in tiles" :key="tile.label" class="rounded-md border border-border p-3">
                    <dt class="text-xs text-text-muted">{{ tile.label }}</dt>
                    <dd class="text-lg font-semibold tabular-nums" :class="tile.tone">{{ number(tile.value) }}</dd>
                </div>
            </dl>

            <div v-if="Object.keys(preview.new_by_month).length" class="text-sm">
                <p class="text-xs font-medium text-text-muted">New punches, month by month</p>
                <ul class="mt-1 flex flex-wrap gap-x-6 gap-y-1">
                    <li v-for="(count, key) in preview.new_by_month" :key="key"><span class="text-text-muted">{{ monthName(key) }}:</span> <span class="font-medium tabular-nums">{{ number(count) }}</span></li>
                </ul>
            </div>
            <Alert v-else tone="info">Nothing in this file is new: every punch is already on file or would be skipped.</Alert>

            <Tabs v-model="tab" :tabs="tabs">
                <template #people>
                    <div class="pt-3">
                        <DataTable :columns="peopleColumns" :rows="preview.people" row-key="biometric_id" caption="Biometric IDs in the file" export-name="imis-dat-preview-ids" :numbered="false" :default-page-size="10" density="compact">
                            <template #cell-biometric_id="{ row }"><span class="font-medium tabular-nums">{{ row.biometric_id }}</span></template>
                            <template #cell-name="{ row }">
                                <span v-if="row.name" class="text-text">{{ row.name }}</span>
                                <Badge v-else tone="warning">Not on any profile</Badge>
                            </template>
                            <template #cell-figures="{ row }">
                                <CellFacts :facts="[{ label: 'Punches', value: number(row.punches) }, { label: 'From', value: formatCalendarDate(row.first_at.slice(0, 10)) }, { label: 'To', value: formatCalendarDate(row.last_at.slice(0, 10)) }]" />
                            </template>
                            <template #cell-new="{ row }"><span class="font-medium tabular-nums" :class="row.new ? 'text-success-fg' : 'text-text-muted'">{{ number(row.new) }}</span></template>
                        </DataTable>
                    </div>
                </template>
                <template #sample>
                    <div class="pt-3">
                        <p class="mb-2 text-xs text-text-muted">The first {{ number(preview.sample.length) }} readable lines of the file, as the upload would treat them.</p>
                        <DataTable :columns="sampleColumns" :rows="preview.sample.map((row, index) => ({ ...row, key: index }))" row-key="key" caption="First lines of the file" export-name="imis-dat-preview-lines" :numbered="false" :default-page-size="10" density="compact">
                            <template #cell-punched_at="{ row }"><span class="whitespace-nowrap tabular-nums">{{ formatDateTime(row.punched_at) }}</span></template>
                            <template #cell-name="{ row }">{{ row.name ?? '—' }}</template>
                            <template #cell-status="{ row }"><Badge :tone="sampleStatus[row.status].tone">{{ sampleStatus[row.status].label }}</Badge></template>
                        </DataTable>
                    </div>
                </template>
            </Tabs>
        </div>

        <template #footer>
            <Button variant="secondary" icon="close" @click="emit('close')">Close</Button>
            <Button v-if="preview && counts.new" icon="download" @click="emit('upload')">Upload {{ number(counts.new) }} new {{ counts.new === 1 ? 'punch' : 'punches' }}</Button>
        </template>
    </Modal>
</template>
