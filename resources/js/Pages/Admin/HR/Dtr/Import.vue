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
const allSelected = computed(() => props.unmatched.length > 0 && selectedIds.value.length === props.unmatched.length);
const selectedPunches = computed(() => props.unmatched.filter((row) => selectedIds.value.includes(row.biometric_id)).reduce((sum, row) => sum + row.punches, 0));
const toggleAll = (checked) => (selectedIds.value = checked ? props.unmatched.map((row) => row.biometric_id) : []);
const toggleId = (id, checked) => (selectedIds.value = checked ? [...selectedIds.value, id] : selectedIds.value.filter((value) => value !== id));
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
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Offices whose biometric logs are on file for {{ monthLabel }}</caption>
                    <thead class="border-b border-border text-xs text-text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">Office</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">With punches</th>
                            <th scope="col" class="hidden px-3 py-2 text-right font-medium sm:table-cell">With a Biometric ID</th>
                            <th scope="col" class="hidden px-3 py-2 text-right font-medium sm:table-cell">Employees</th>
                            <th scope="col" class="hidden py-2 pl-3 font-medium sm:table-cell">Last punch</th>
                            <th scope="col" class="py-2 pl-3 text-right font-medium"><span class="sr-only">Export</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template v-for="row in monitor" :key="row.key">
                            <tr>
                                <td class="py-2 pr-4 font-medium">
                                    <button v-if="row.people.length" type="button" class="inline-flex items-center gap-1 text-left hover:text-link" :aria-expanded="open === row.key" :aria-controls="`monitor-${row.key}`" @click="open = open === row.key ? null : row.key">
                                        <Icon :name="open === row.key ? 'chevronDown' : 'chevronRight'" size="sm" />{{ row.office }}
                                    </button>
                                    <template v-else>{{ row.office }}</template>
                                </td>
                                <td class="px-3 py-2">
                                    <Badge :tone="statusTone[row.status]">{{ statusLabel[row.status] }}</Badge>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ row.with_punches }}<span class="text-text-muted sm:hidden"> / {{ row.employees }}</span></td>
                                <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ row.with_id }}</td>
                                <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">{{ row.employees }}</td>
                                <td class="hidden whitespace-nowrap py-2 pl-3 text-text-muted sm:table-cell">{{ row.last_punch ? formatCalendarDate(row.last_punch) : '—' }}</td>
                                <td class="py-2 pl-3 text-right">
                                    <Button v-if="row.employees" size="sm" variant="secondary" icon="download" :aria-label="`Export ${row.office} DTRs for ${monthLabel} to Excel`" @click="exporting = row">Excel</Button>
                                </td>
                            </tr>
                            <tr v-if="open === row.key" :id="`monitor-${row.key}`">
                                <td colspan="7" class="bg-surface-muted px-3 py-3">
                                    <p class="text-xs font-medium text-text-muted">{{ row.people.length }} {{ row.people.length === 1 ? 'employee has' : 'employees have' }} no punches for {{ monthLabel }}:</p>
                                    <ul class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                        <li v-for="person in row.people" :key="person.name" class="flex items-center justify-between gap-2">
                                            <span class="truncate">{{ person.name }}</span>
                                            <span class="shrink-0 text-xs" :class="person.reason === 'No Biometric ID' ? 'text-warning-fg' : 'text-text-muted'">{{ person.reason }}</span>
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
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
            <div v-if="canManage && unmatched.length" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-text-muted" aria-live="polite">
                    {{ selectedIds.length ? `${selectedIds.length} selected, ${number(selectedPunches)} punches` : 'Select the IDs to delete.' }}
                </p>
                <Button variant="danger" :disabled="!selectedIds.length" :loading="clearing" @click="clearSelected"><Icon name="trash" size="sm" />Delete selected punches</Button>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Biometric IDs in the logs that no employee has</caption>
                    <thead class="border-b border-border text-xs text-text-muted">
                        <tr>
                            <th v-if="canManage" scope="col" class="w-8 py-2 pr-3">
                                <input type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" aria-label="Select all IDs" :checked="allSelected" @change="toggleAll($event.target.checked)" />
                            </th>
                            <th scope="col" class="py-2 pr-4 font-medium">Biometric ID</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Punches</th>
                            <th scope="col" class="py-2 pl-3 font-medium">Seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in unmatched" :key="row.biometric_id">
                            <td v-if="canManage" class="py-2 pr-3">
                                <input type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" :aria-label="`Select ID ${row.biometric_id}`" :checked="selectedIds.includes(row.biometric_id)" @change="toggleId(row.biometric_id, $event.target.checked)" />
                            </td>
                            <td class="py-2 pr-4 font-medium tabular-nums">{{ row.biometric_id }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number(row.punches) }}</td>
                            <td class="whitespace-nowrap py-2 pl-3 text-text-muted">{{ formatDateTime(row.first_at) }} to {{ formatDateTime(row.last_at) }}</td>
                        </tr>
                        <tr v-if="!unmatched.length">
                            <td :colspan="canManage ? 4 : 3" class="py-4 text-center text-text-muted">Every ID in the logs belongs to an employee.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </details>
        </Card>

        <Card title="Recent uploads" icon="clipboard">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">The latest uploaded attendance logs</caption>
                    <thead class="border-b border-border text-xs text-text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">Uploaded</th>
                            <th scope="col" class="py-2 pr-4 font-medium">File</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Read</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">New</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Already on file</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Left out</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Skipped</th>
                            <th scope="col" class="py-2 pl-3 font-medium">Covers</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="item in imports" :key="item.id">
                            <td class="whitespace-nowrap py-2 pr-4">
                                {{ formatDateTime(item.created_at) }}
                                <div class="text-xs text-text-muted">{{ item.uploader }}</div>
                            </td>
                            <td class="py-2 pr-4">{{ item.file_name }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number(item.rows_read) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ number(item.rows_new) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number(item.rows_duplicate) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-text-muted">{{ number(item.rows_ignored) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums" :class="item.rows_skipped ? 'text-warning-fg' : 'text-text-muted'">{{ number(item.rows_skipped) }}</td>
                            <td class="whitespace-nowrap py-2 pl-3 text-text-muted">
                                <template v-if="item.first_punch_at">{{ formatDateTime(item.first_punch_at) }} to {{ formatDateTime(item.last_punch_at) }}</template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                        <tr v-if="!imports.length">
                            <td colspan="8" class="py-4 text-center text-text-muted">Nothing uploaded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>

    <DtrPrintChoice action="export" :url="exporting ? `/admin/hr/dtr/export?office=${exporting.key}&month=${month}` : null" :subject="exporting?.office ?? ''" :period="monthLabel" @close="exporting = null" />
</template>
