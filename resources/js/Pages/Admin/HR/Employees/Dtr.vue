<script setup>
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import DtrSheetTable from '../../../../Components/HR/DtrSheetTable.vue';
import DtrPunchesModal from '../../../../Components/HR/DtrPunchesModal.vue';
import DtrPrintChoice from '../../../../Components/HR/DtrPrintChoice.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatCalendarDate, formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * DTR for HRD: one person's month, a sheet per office served, with a
 * correction (a full-day credit, a travel day, or the day's in and out
 * times) that can be added to any day that has happened. The punches
 * themselves are never edited. Print opens the CS Form No. 48 for a sheet.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    month: { type: String, required: true },
    label: { type: String, required: true },
    sheets: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
    printBase: { type: String, required: true },
});

const base = computed(() => `/admin/hr/employees/${props.employee.id}/dtr`);
const changeMonth = (event) => event.target.value && router.get(base.value, { month: event.target.value }, { preserveScroll: true });
const printUrl = (sheet) => `${props.printBase}?from=${sheet.from}&to=${sheet.to}`;
// The sheet whose "Print CS Form 48" is open, to choose a single or double DTR.
const printing = ref(null);
// The sheet whose "Export to Excel" is open, to choose a single or double layout.
const exporting = ref(null);
const exportUrl = (sheet) => `${props.printBase.replace(/\/print$/, '/export')}?from=${sheet.from}&to=${sheet.to}`;

const kinds = [
    { value: 'credit', label: 'Credit a full day (event, official business)' },
    { value: 'travel', label: 'Travel day' },
    { value: 'times', label: 'Correct the time in or out' },
];

// The day whose raw punches are open.
const punchDay = ref(null);
const open = ref(false);
const day = ref(null);
const form = useForm({ date: '', kind: 'credit', time_in: '', time_out: '', remark: '' });

const adjust = (row) => {
    day.value = row;
    form.reset();
    form.clearErrors();
    form.date = row.date;
    // An adjustment already on the day comes back for editing; otherwise start from the punches.
    if (row.adjustment) {
        form.kind = row.adjustment.kind;
        form.time_in = row.adjustment.time_in ?? '';
        form.time_out = row.adjustment.time_out ?? '';
        form.remark = row.adjustment.remark;
    } else {
        form.time_in = row.in ?? '';
        form.time_out = row.out ?? '';
    }
    open.value = true;
};
const submit = () => form.post(`${base.value}/adjustments`, { preserveScroll: true, onSuccess: () => (open.value = false) });

const removeAdjustment = async (row) => {
    const ok = await confirm({ title: `Remove the adjustment for ${formatCalendarDate(row.date)}?`, text: 'The day goes back to being counted from the punches, leave and holidays.', confirmText: 'Remove' });
    if (ok) {
        router.delete(`${base.value}/adjustments/${row.adjustment_id}`, { preserveScroll: true, onSuccess: () => (open.value = false) });
    }
};
</script>

<template>
    <Head :title="`DTR — ${employee.name}`" />

    <PageHeader
        :title="`Daily Time Record: ${employee.name}`"
        :description="employee.position ?? ''"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Employees', href: '/admin/hr/employees' }, { label: employee.name, href: `/admin/hr/employees/${employee.id}` }, { label: 'DTR' }]"
    >
        <template #actions>
            <label class="flex items-center gap-2 text-sm text-text">
                Month
                <input type="month" class="filter-control" :value="month" aria-label="Month" @change="changeMonth" />
            </label>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card v-for="sheet in sheets" :key="sheet.from" :title="`${sheet.period}${sheet.unit ? ` · ${sheet.unit}` : ''}`" icon="clock">
            <template #actions>
                <Button variant="secondary" icon="printer" @click="printing = sheet">Print CS Form 48</Button>
                <Button variant="secondary" icon="download" @click="exporting = sheet">Export to Excel</Button>
            </template>
            <p class="mb-3 text-sm text-text-muted">
                Work schedule: <span class="text-text">{{ sheet.schedule ?? 'None assigned: punches are shown without hours' }}</span>
                <template v-if="sheet.head"> · Verified by <span class="text-text">{{ sheet.head }}</span></template>
            </p>
            <p v-if="sheet.signature" class="mb-3 text-sm">
                <span
                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="sheet.signature.status === 'valid' ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg'"
                >
                    {{ sheet.signature.status === 'valid' ? 'Signed by the employee' : 'Changed since the employee signed' }}
                </span>
                <span class="ml-2 text-text-muted">{{ formatDateTime(sheet.signature.signed_at) }} · code {{ sheet.signature.code }}</span>
            </p>
            <DtrSheetTable :rows="sheet.rows" :totals="sheet.totals" :can-adjust="canManage" can-see-punches @adjust="adjust" @punches="(row) => (punchDay = row.date)" />
        </Card>
    </div>

    <DtrPunchesModal :employee-id="employee.id" :date="punchDay" :can-manage="canManage" @close="punchDay = null" />

    <DtrPrintChoice :url="printing ? printUrl(printing) : null" :period="printing?.period ?? ''" @close="printing = null" />
    <DtrPrintChoice action="export" :url="exporting ? exportUrl(exporting) : null" :period="exporting?.period ?? ''" @close="exporting = null" />

    <Modal :open="open" :title="day ? `Adjust ${formatCalendarDate(day.date)}` : 'Adjust'" icon="pencil" tone="primary" @close="open = false">
        <form id="dtr-adjust-form" class="space-y-4" @submit.prevent="submit">
            <p v-if="day" class="text-sm text-text-muted">
                The punches stay as recorded: {{ day.in ?? 'no time in' }}{{ day.out ? ` to ${day.out}` : '' }}. The adjustment replaces how the day is counted. Saving again replaces an earlier adjustment.
            </p>
            <div>
                <InputLabel for="adjust-kind" required>What happened</InputLabel>
                <Select id="adjust-kind" v-model="form.kind" :options="kinds" required :invalid="!!form.errors.kind" />
                <InputError :message="form.errors.kind" />
            </div>
            <div v-if="form.kind === 'times'" class="grid gap-3 sm:grid-cols-2">
                <TextInput id="adjust-in" v-model="form.time_in" type="time" label="Time in (blank to keep)" :error="form.errors.time_in" />
                <TextInput id="adjust-out" v-model="form.time_out" type="time" label="Time out (blank to keep)" :error="form.errors.time_out" />
            </div>
            <TextInput id="adjust-remark" v-model="form.remark" label="Remark shown on the DTR (for example Pasasalamat Program)" required maxlength="255" :error="form.errors.remark || form.errors.date" />
        </form>
        <template #footer>
            <Button variant="secondary" icon="close" @click="open = false">Cancel</Button>
            <Button v-if="day?.adjustment_id" variant="danger" @click="removeAdjustment(day)">Remove adjustment</Button>
            <Button type="submit" form="dtr-adjust-form" :loading="form.processing">Save</Button>
        </template>
    </Modal>
</template>
