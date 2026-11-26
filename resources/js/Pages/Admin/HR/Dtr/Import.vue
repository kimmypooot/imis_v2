<script setup>
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import ProgressBar from '@cscro8/ui/Components/UI/ProgressBar.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import Checkbox from '@cscro8/ui/Components/Forms/Checkbox.vue';
import FileInput from '@cscro8/ui/Components/Forms/FileInput.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import DtrPrintChoice from '../../../../Components/HR/DtrPrintChoice.vue';
import { formatCalendarDate, formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * DTR: HRD uploads the .dat attendance log from a biometric device. Every
 * punch is kept once, so a device's full history can be uploaded again
 * safely: only what is new is added. A punch belongs to the employee whose
 * record carries its Biometric ID (set on their profile, like the employee
 * number); IDs nobody has yet are listed below.
 */
const props = defineProps({
    month: { type: String, required: true },
    monthLabel: { type: String, required: true },
    monitor: { type: Array, required: true },
    staffCount: { type: Number, default: 0 },
    result: { type: Object, default: null },
    imports: { type: Array, required: true },
    unmatched: { type: Array, required: true },
    punches: { type: Number, default: 0 },
    canManage: { type: Boolean, default: false },
});

const form = useForm({ file: null, known_only: true });
const send = async () => {
    const ok = await confirm({
        title: 'Upload this log?',
        text: `${form.file?.name ?? 'The file'} will be added, and all ${props.staffCount} employees in service are told under the bell that the logs are in, with your name as the uploader.`,
        confirmText: 'Upload and notify',
    });
    if (ok) {
        form.post('/admin/hr/dtr/import', { forceFormData: true, onSuccess: () => form.reset('file') });
    }
};

const selectedIds = ref([]);
const clearing = ref(false);
const selectedPunches = computed(() => props.unmatched.filter((row) => selectedIds.value.includes(row.biometric_id)).reduce((sum, row) => sum + row.punches, 0));
const clearSelected = async () => {
    const ok = await confirm({
        title: 'Delete these punches?',
        text: `${number(selectedPunches.value)} punches from ${selectedIds.value.length} ${selectedIds.value.length === 1 ? 'ID' : 'IDs'} will be permanently deleted. Uploading the log again would bring them back. IDs that have since been given to an employee are skipped.`,
        confirmText: 'Delete punches',
        tone: 'danger',
    });
    if (!ok) return;
    router.delete('/admin/hr/dtr/import/ids', {
        data: { ids: selectedIds.value },
        preserveScroll: true,
        onStart: () => (clearing.value = true),
        onFinish: () => (clearing.value = false),
        onSuccess: () => (selectedIds.value = []),
    });
};

const open = ref(null);
// The office whose "Excel" is open, to choose a single or double layout.
const exporting = ref(null);
const goToMonth =(value) => router.get('/admin/hr/dtr/import', { month: value }, { preserveScroll: true, preserveState: true, only: ['month', 'monthLabel', 'monitor'], onSuccess: () => (open.value = null) });
const shiftMonth = (step) => {
    const [year, mon] = props.month.split('-').map(Number);
    const date = new Date(year, mon - 1 + step, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
};
const received = computed(() => props.monitor.filter((row) => row.status === 'received').length);
const statusLabel = { received: 'Complete', partial: 'Partial', missing: 'Not yet' };
const statusTone = { received: 'success', partial: 'warning', missing: 'danger' };

const number = (value) => Number(value).toLocaleString();

// Folded figures keep their own hidden columns, so the export still has each one.
const monitorColumns = [
    { key: 'office', label: 'Office' },
    { key: 'status', label: 'Status', value: (row) => statusLabel[row.status] },
    { key: 'coverage', label: 'Employees', sortable: false, export: false },
    { key: 'last_punch', label: 'Last punch', value: (row) => row.last_punch ?? '' },
    { key: 'export', label: '', sortable: false, export: false, align: 'right' },
    { key: 'with_punches', label: 'With punches', hidden: true },
    { key: 'with_id', label: 'With a Biometric ID', hidden: true },
    { key: 'employees', label: 'Employees', hidden: true },
];
const unmatchedColumns = [
    { key: 'biometric_id', label: 'Biometric ID' },
    { key: 'punches', label: 'Punches', align: 'right' },
    { key: 'seen', label: 'Seen', sortable: false, export: false },
    { key: 'first_at', label: 'First seen', hidden: true },
    { key: 'last_at', label: 'Last seen', hidden: true },
];
const importColumns = [
    { key: 'created_at', label: 'Uploaded', value: (row) => `${row.created_at} ${row.uploader ?? ''}` },
    { key: 'file_name', label: 'File' },
    { key: 'counts', label: 'Rows', sortable: false, export: false },
    { key: 'covers', label: 'Covers', sortable: false, export: false },
    { key: 'uploader', label: 'Uploaded by', hidden: true },
    { key: 'rows_read', label: 'Read', hidden: true },
    { key: 'rows_new', label: 'New', hidden: true },
    { key: 'rows_duplicate', label: 'Already on file', hidden: true },
    { key: 'rows_ignored', label: 'Left out', hidden: true },
    { key: 'rows_skipped', label: 'Skipped', hidden: true },
    { key: 'first_punch_at', label: 'First punch', value: (row) => row.first_punch_at ?? '', hidden: true },
    { key: 'last_punch_at', label: 'Last punch', value: (row) => row.last_punch_at ?? '', hidden: true },
];
</script>

<template>
    <Head title="Biometric logs" />

    <PageHeader
        title="Biometric logs"
        description="Upload the attendance log (.dat) from a biometric device. Punches already on file are skipped, so the whole history can be uploaded again."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Biometric logs' }]"
    />

    <div class="space-y-6">
        <Card :title="`Who has submitted: ${monthLabel}`" icon="clipboard" description="The Regional Support Units count as one Regional Office; each Field Office stands on its own. An office counts once its employees' punches for the month are on file.">
            <div class="mb-3 flex flex-wrap items-end gap-3">
                <div>
                    <InputLabel for="monitor-month">Month</InputLabel>
                    <div class="mt-1 flex items-center gap-1">
                        <ActionButton icon="chevronLeft" :label="`Previous month`" @click="goToMonth(shiftMonth(-1))" />
                        <input id="monitor-month" type="month" class="filter-control" :value="month" @change="(event) => event.target.value && goToMonth(event.target.value)" />
                        <ActionButton icon="chevronRight" :label="`Next month`" @click="goToMonth(shiftMonth(1))" />
                    </div>
                </div>
                <p class="pb-2 text-sm text-text-muted" aria-live="polite">{{ received }} of {{ monitor.length }} offices complete.</p>
            </div>
            <DataTable :columns="monitorColumns" :rows="monitor" row-key="key" :caption="`Offices whose biometric logs are on file for ${monthLabel}`" :export-name="`imis-dtr-submissions-${month}`" :numbered="false" :searchable="false" :default-page-size="50">
                <template #cell-office="{ row }">
                    <button v-if="row.people.length" type="button" class="inline-flex items-center gap-1 text-left font-medium text-text hover:text-link" :aria-expanded="open === row.key" :aria-controls="`monitor-${row.key}`" @click="open = open === row.key ? null : row.key">
                        <Icon :name="open === row.key ? 'chevronDown' : 'chevronRight'" size="sm" />{{ row.office }}
                    </button>
                    <span v-else class="font-medium text-text">{{ row.office }}</span>
                    <div v-if="open === row.key" :id="`monitor-${row.key}`" class="mt-2 rounded-md bg-surface-muted px-3 py-2">
                        <p class="text-xs font-medium text-text-muted">{{ row.people.length }} {{ row.people.length === 1 ? 'employee has' : 'employees have' }} no punches for {{ monthLabel }}:</p>
                        <ul class="mt-1 space-y-0.5 text-sm">
                            <li v-for="person in row.people" :key="person.name" class="flex items-center justify-between gap-3">
                                <span class="truncate">{{ person.name }}</span>
                                <span class="shrink-0 text-xs" :class="person.reason === 'No Biometric ID' ? 'text-warning-fg' : 'text-text-muted'">{{ person.reason }}</span>
                            </li>
                        </ul>
                    </div>
                </template>
                <template #cell-status="{ row }">
                    <Badge :tone="statusTone[row.status]">{{ statusLabel[row.status] }}</Badge>
                </template>
                <template #cell-coverage="{ row }">
                    <CellFacts :facts="[{ label: 'With punches', value: `${row.with_punches} of ${row.employees}` }, { label: 'With a Biometric ID', value: String(row.with_id) }]" />
                </template>
                <template #cell-last_punch="{ row }"><span class="whitespace-nowrap text-text-muted">{{ row.last_punch ? formatCalendarDate(row.last_punch) : '—' }}</span></template>
                <template #cell-export="{ row }">
                    <span v-if="!row.employees" />
                    <Button v-else size="sm" variant="secondary" icon="download" :aria-label="`Export ${row.office} DTRs for ${monthLabel} to Excel`" @click="exporting = row">Excel</Button>
                </template>
            </DataTable>
        </Card>

        <Card v-if="canManage" title="Upload the consolidated log" icon="download" :description="`Everyone in service (${number(staffCount)} employees) is notified under the bell when an upload adds new punches.`">
            <form class="space-y-4" @submit.prevent="send">
                <div>
                    <InputLabel for="dtr-file">Attendance log (.dat)</InputLabel>
                    <FileInput id="dtr-file" class="mt-1" accept=".dat,.txt" required :invalid="!!form.errors.file" @update:model-value="(file) => (form.file = file)" />
                    <InputError :message="form.errors.file" />
                </div>

                <div>
                    <Checkbox id="dtr-known-only" v-model="form.known_only" label="Keep only IDs already on an employee's profile" />
                    <p class="mt-1 pl-6 text-xs text-text-muted">Keeps the database small. Punches from other IDs are left out; upload the file again after adding an ID to a profile to bring them in.</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                    <p class="text-sm text-text-muted">{{ number(punches) }} punches on file.</p>
                    <Button type="submit" :loading="form.processing" :disabled="!form.file"><Icon name="download" size="sm" />Upload</Button>
                </div>
            </form>
            <div v-if="form.processing" class="mt-3" role="status">
                <ProgressBar :value="form.progress?.percentage ?? null" :label="form.progress && form.progress.percentage < 100 ? 'Sending the file' : 'Reading the file and notifying employees'" show-label show-value />
            </div>

            <div v-if="result" class="mt-4 rounded-md border border-border bg-surface-muted p-4 text-sm" role="status">
                <p class="font-medium text-text">Uploaded {{ result.file_name }}<template v-if="result.first_punch_at">, covering {{ formatCalendarDate(result.first_punch_at) }} to {{ formatCalendarDate(result.last_punch_at) }}</template></p>
                <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fit,minmax(8rem,1fr))]">
                    <div><dt class="text-xs text-text-muted">Read</dt><dd class="font-semibold tabular-nums">{{ number(result.rows_read) }}</dd></div>
                    <div><dt class="text-xs text-text-muted">New</dt><dd class="font-semibold tabular-nums">{{ number(result.rows_new) }}</dd></div>
                    <div><dt class="text-xs text-text-muted">Already on file</dt><dd class="font-semibold tabular-nums">{{ number(result.rows_duplicate) }}</dd></div>
                    <div v-if="result.ignored"><dt class="text-xs text-text-muted">Left out (no profile)</dt><dd class="font-semibold tabular-nums">{{ number(result.ignored) }}</dd></div>
                    <div><dt class="text-xs text-text-muted">Unreadable</dt><dd class="font-semibold tabular-nums" :class="result.rows_skipped ? 'text-warning-fg' : ''">{{ number(result.rows_skipped) }}</dd></div>
                    <div><dt class="text-xs text-text-muted">Employees notified</dt><dd class="font-semibold tabular-nums">{{ number(result.notified) }}</dd></div>
                </dl>
                <p v-if="result.unmatched" class="mt-2 text-warning-fg">{{ result.unmatched }} {{ result.unmatched === 1 ? 'ID' : 'IDs' }} in the logs {{ result.unmatched === 1 ? 'is' : 'are' }} not on any employee's profile yet (see below).</p>
                <p v-if="!result.rows_new" class="mt-2 text-text-muted">Nothing new in this file, so no one was notified.</p>
            </div>
        </Card>

        <Card title="IDs not on any employee (separated, resigned, retired)" icon="users">
            <details class="group">
            <summary class="cursor-pointer text-sm font-medium text-text">
                {{ unmatched.length ? `${number(unmatched.length)} ${unmatched.length === 1 ? 'ID' : 'IDs'} not on any profile` : 'Every ID in the logs belongs to an employee' }}
            </summary>
            <p class="mt-3 text-sm text-text-muted">
                These numbers are in the uploaded logs but are not the Biometric ID on any employee's profile. They are usually staff who have separated, resigned or retired, whose enrolment is still on the device. Tick the ones you no longer need and delete their stored punches to free space. If one belongs to a current employee, open them in
                <Link href="/admin/hr/employees" class="text-link hover:underline">Employees</Link>, choose Edit, and fill in their Biometric ID: their punches, including the ones already uploaded, then count toward their DTR.
            </p>
            <DataTable
                v-model:selected="selectedIds"
                class="mt-3"
                :columns="unmatchedColumns"
                :rows="unmatched"
                row-key="biometric_id"
                :selectable="canManage"
                caption="Biometric IDs in the logs that no employee has"
                export-name="imis-unmatched-biometric-ids"
                :numbered="false"
                empty-text="Every ID in the logs belongs to an employee."
            >
                <template #cell-biometric_id="{ row }"><span class="font-medium tabular-nums">{{ row.biometric_id }}</span></template>
                <template #cell-punches="{ row }"><span class="tabular-nums">{{ number(row.punches) }}</span></template>
                <template #cell-seen="{ row }">
                    <CellFacts :facts="[{ label: 'First', value: formatDateTime(row.first_at) }, { label: 'Last', value: formatDateTime(row.last_at) }]" />
                </template>
                <template #bulk>
                    <span class="text-text-muted">{{ number(selectedPunches) }} punches</span>
                    <Button size="sm" variant="danger" icon="trash" :loading="clearing" @click="clearSelected">Delete selected punches</Button>
                </template>
            </DataTable>
            </details>
        </Card>

        <Card title="Recent uploads" icon="clipboard">
            <DataTable :columns="importColumns" :rows="imports" caption="The latest uploaded attendance logs" export-name="imis-biometric-uploads" :numbered="false" empty-text="Nothing uploaded yet.">
                <template #cell-created_at="{ row }">
                    <span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span>
                    <div class="text-xs text-text-muted">{{ row.uploader }}</div>
                </template>
                <template #cell-counts="{ row }">
                    <CellFacts
                        :facts="[
                            { label: 'Read', value: number(row.rows_read) },
                            { label: 'New', value: number(row.rows_new) },
                            { label: 'Already on file', value: number(row.rows_duplicate) },
                            { label: 'Left out', value: row.rows_ignored ? number(row.rows_ignored) : null },
                            { label: 'Skipped', value: row.rows_skipped ? number(row.rows_skipped) : null },
                        ]"
                    />
                </template>
                <template #cell-covers="{ row }">
                    <CellFacts v-if="row.first_punch_at" :facts="[{ label: 'From', value: formatDateTime(row.first_punch_at) }, { label: 'To', value: formatDateTime(row.last_punch_at) }]" />
                    <span v-else class="text-text-muted">—</span>
                </template>
            </DataTable>
        </Card>
    </div>

    <DtrPrintChoice action="export" :url="exporting ? `/admin/hr/dtr/export?office=${exporting.key}&month=${month}` : null" :subject="exporting?.office ?? ''" :period="monthLabel" @close="exporting = null" />
</template>
