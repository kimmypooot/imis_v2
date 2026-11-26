<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import FormGroup from '@cscro8/ui/Components/Forms/FormGroup.vue';
import Textarea from '@cscro8/ui/Components/Forms/Textarea.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatCalendarDate, formatDateTime } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: one month's payroll. HRD prepares it with every deduction but
 * withholding tax and adjusts lines (absences, a one-time allowance…),
 * then submits it to MSD; the Accountant III reviews it, enters each
 * person's withholding tax and returns it; HRD finalizes it, which locks
 * it, counts its loan lines as paid, and lets staff request their
 * payslips. The table is the payroll register (CSV / print).
 */
const props = defineProps({
    run: { type: Object, required: true },
    entries: { type: Array, required: true },
    leftOut: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    canEnterTax: { type: Boolean, default: false },
    canViewAll: { type: Boolean, default: true },
    reviewers: { type: Array, default: () => [] },
    previousTax: { type: Object, default: () => ({}) },
    components: { type: Array, required: true },
    minimumNet: { type: Number, required: true },
});

const isDraft = computed(() => props.run.status === 'draft');
const isReturned = computed(() => props.run.status === 'returned');
const withHrd = computed(() => isDraft.value || isReturned.value);
const withAccounting = computed(() => props.run.status === 'with_accounting');
const isFinalized = computed(() => props.run.status === 'finalized');
const editable = computed(() => props.canManage && withHrd.value);
const statusTone = { draft: 'draft', with_accounting: 'warning', returned: 'info', finalized: 'active' };
const belowMinimum = computed(() => props.entries.filter((entry) => entry.below_minimum));

const base = `/admin/hr/payroll/runs/${props.run.id}`;
const post = (path, options = {}) => router.post(`${base}${path}`, {}, { preserveScroll: true, ...options });

const regenerate = async () => {
    if (await confirm({ title: 'Regenerate the whole payroll?', text: 'Everyone is rebuilt from the plantilla, pay profiles and loans. Adjustments made here are discarded.', tone: 'danger', confirmText: 'Regenerate' })) {
        post('/regenerate');
    }
};
const finalize = async () => {
    const warning = belowMinimum.value.length ? ` ${belowMinimum.value.length} net pay(s) are below ${formatPeso(props.minimumNet)}.` : '';
    if (await confirm({ title: `Finalize the payroll for ${props.run.period}?`, text: `It is locked, loans count it as paid, and staff can request their payslips.${warning}`, confirmText: 'Finalize' })) {
        post('/finalize');
    }
};
// Reopening a payroll and cancelling a payslip both need a reason for the audit trail.
const reasonDialog = ref(null); // 'reopen' | 'cancel'
const reasonForm = useForm({ reason: '' });
const askReason = (kind) => {
    reasonDialog.value = kind;
    reasonForm.reset();
    reasonForm.clearErrors();
};
const submitReason = () => {
    const url = reasonDialog.value === 'reopen' ? `${base}/reopen` : `${base}/entries/${entry.value.id}/cancel`;
    reasonForm.post(url, { preserveScroll: true, onSuccess: () => (reasonDialog.value = null) });
};
const submitToAccounting = async () => {
    const text = isReturned.value
        ? 'It goes back to Accounting to check the withholding tax again; HRD can’t change it until it is returned.'
        : 'The Accountant III reviews it and enters the withholding tax, then returns it to HRD to finalize. HRD can’t change it meanwhile.';
    if (await confirm({ title: `Submit the payroll for ${props.run.period} to Accounting?`, text, confirmText: 'Submit' })) {
        post('/submit');
    }
};

// Accounting: withholding tax for everyone at once, then back to HRD.
const taxRows = computed(() => props.entries.filter((item) => !item.cancelled));
const taxForm = useForm({ taxes: Object.fromEntries(taxRows.value.map((item) => [item.id, item.withholding_tax || ''])) });
const taxNet = (row) => row.net_pay + row.withholding_tax - Number(taxForm.taxes[row.id] || 0);
const taxTotal = computed(() => taxRows.value.reduce((total, row) => total + Number(taxForm.taxes[row.id] || 0), 0));
const saveTaxes = () => taxForm.put(`${base}/withholding-tax`, { preserveScroll: true, onSuccess: () => taxForm.defaults() });
const returnDialog = ref(false);
const returnForm = useForm({ note: '' });
const missingTax = computed(() => taxRows.value.filter((row) => !row.own && !Number(taxForm.taxes[row.id] || 0)).length);
const sendBack = () => returnForm.post(`${base}/return`, { preserveScroll: true, onSuccess: () => (returnDialog.value = false) });

const destroy = async () => {
    if (await confirm({ title: `Delete the draft for ${props.run.period}?`, tone: 'danger', confirmText: 'Delete' })) {
        router.delete(base);
    }
};

// One person's pay: lines, and HR's adjustments while a draft.
const openId = ref(null);
const entry = computed(() => props.entries.find((item) => item.id === openId.value) ?? null);
const canAdjust = computed(() => editable.value && entry.value && !entry.value.own && !entry.value.cancelled);
const canCancel = computed(() => props.canManage && entry.value && !entry.value.own && !entry.value.cancelled);
const earnings = computed(() => entry.value?.lines.filter((line) => line.kind === 'earning') ?? []);
const deductions = computed(() => entry.value?.lines.filter((line) => line.kind === 'deduction') ?? []);

const editingLine = ref(null);
const lineForm = useForm({ amount: '', reason: '' });
const editLine = (line) => {
    editingLine.value = line.id;
    lineForm.amount = line.amount;
    lineForm.reason = line.adjust_reason ?? '';
    lineForm.clearErrors();
};
const saveLine = (line) =>
    lineForm.put(`${base}/entries/${entry.value.id}/lines/${line.id}`, { preserveScroll: true, onSuccess: () => (editingLine.value = null) });
const removeLine = async (line) => {
    if (await confirm({ title: `Remove ${line.label}?`, text: `From ${entry.value.name}'s pay for ${props.run.period} only.`, tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`${base}/entries/${entry.value.id}/lines/${line.id}`, { preserveScroll: true });
    }
};

const addForm = useForm({ kind: 'deduction', pay_component_id: '', label: '', amount: '', reason: '' });
const componentOptions = computed(() => [{ value: '', label: 'Other (type a label)' }, ...props.components]);
const addLine = () =>
    addForm
        .transform((data) => ({ ...data, pay_component_id: data.pay_component_id || null, label: data.pay_component_id ? null : data.label }))
        .post(`${base}/entries/${entry.value.id}/lines`, { preserveScroll: true, onSuccess: () => addForm.reset() });

const rebuild = async () => {
    if (await confirm({ title: `Rebuild ${entry.value.name}'s pay?`, text: 'From their pay profile and loans; adjustments to it are discarded.', tone: 'danger', confirmText: 'Rebuild' })) {
        post(`/entries/${entry.value.id}/regenerate`);
    }
};

// Figures fold into a cell or two so the register fits; hidden columns keep each one in the CSV.
const columns = [
    { key: 'name', label: 'Name' },
    { key: 'earnings', label: 'Pay', sortable: false, export: false },
    { key: 'deductions', label: 'Deductions', sortable: false, export: false },
    { key: 'net_pay', label: 'Net pay', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false },
    { key: 'position', label: 'Position', hidden: true, value: (row) => row.position ?? '' },
    { key: 'grade', label: 'SG/Step', hidden: true, value: (row) => (row.salary_grade ? `${row.salary_grade}-${row.step}` : '') },
    { key: 'office', label: 'Office', hidden: true, value: (row) => row.office ?? '' },
    { key: 'basic_salary', label: 'Basic', hidden: true },
    { key: 'gross_pay', label: 'Gross', hidden: true },
    { key: 'withholding_tax', label: 'W/Tax', hidden: true },
    { key: 'total_deductions', label: 'Deductions', hidden: true },
];
const taxColumns = [
    { key: 'name', label: 'Name' },
    { key: 'pay', label: 'Pay', sortable: false, export: false },
    { key: 'tax', label: 'Withholding tax', sortable: false, export: false, align: 'right' },
    { key: 'net', label: 'Net pay', align: 'right', value: (row) => String(taxNet(row)) },
];
</script>

<template>
    <Head :title="`Payroll — ${run.period}`" />

    <PageHeader
        :title="`Payroll — ${run.period}`"
        :breadcrumbs="canViewAll ? [{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: run.period }] : [{ label: 'Payroll Tax Review', href: '/payroll/tax-review' }, { label: run.period }]"
    >
        <template #actions>
            <Button variant="secondary" :href="`/admin/hr/payroll/runs/${run.id}/general-payroll`" external><Icon name="printer" size="sm" />General Payroll</Button>
            <Button variant="secondary" :href="`/admin/hr/payroll/runs/${run.id}/remittances`"><Icon name="database" size="sm" />Remittances</Button>
            <Button v-if="canViewAll" variant="secondary" :href="`/admin/hr/payroll/runs/${run.id}/reconciliation`"><Icon name="clipboard" size="sm" />Compare with workbook</Button>
            <template v-if="canManage">
                <template v-if="withHrd">
                    <Button variant="secondary" @click="regenerate"><Icon name="refresh" size="sm" />Regenerate</Button>
                    <Button v-if="isDraft" variant="secondary" @click="destroy" icon="trash">Delete draft</Button>
                    <Button :variant="isDraft ? 'primary' : 'secondary'" @click="submitToAccounting"><Icon name="arrowRight" size="sm" />{{ isDraft ? 'Submit to Accounting' : 'Send to Accounting again' }}</Button>
                    <Button v-if="isReturned" variant="success" @click="finalize"><Icon name="checkCircle" size="sm" />Finalize</Button>
                </template>
                <Button v-else-if="isFinalized" variant="secondary" @click="askReason('reopen')">Reopen to correct</Button>
            </template>
            <Button v-if="canEnterTax && withAccounting" variant="success" @click="returnDialog = true"><Icon name="arrowLeft" size="sm" />Return to HRD</Button>
        </template>
    </PageHeader>

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <Card title="Status" icon="clipboard">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-text-muted">Status</dt><dd><StatusBadge :status="statusTone[run.status]" :label="run.status_label" /></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-text-muted">Release</dt><dd class="text-right text-text">{{ formatCalendarDate(run.release_on) }}</dd></div>
                <div v-if="run.submitted_at" class="flex justify-between gap-3"><dt class="text-text-muted">To Accounting</dt><dd class="text-right text-text">{{ formatDateTime(run.submitted_at) }}<span v-if="run.submitted_by"> by {{ run.submitted_by }}</span></dd></div>
                <div v-if="withAccounting && reviewers.length" class="flex justify-between gap-3"><dt class="text-text-muted">Waiting on</dt><dd class="text-right text-text">{{ reviewers.join(", ") }}</dd></div>
                <div v-if="run.returned_at" class="flex justify-between gap-3"><dt class="text-text-muted">Returned</dt><dd class="text-right text-text">{{ formatDateTime(run.returned_at) }}<span v-if="run.returned_by"> by {{ run.returned_by }}</span></dd></div>
                <div v-if="run.finalized_at" class="flex justify-between gap-3"><dt class="text-text-muted">Finalized</dt><dd class="text-right text-text">{{ formatDateTime(run.finalized_at) }}<span v-if="run.finalized_by"> by {{ run.finalized_by }}</span></dd></div>
                <div v-if="run.created_by" class="flex justify-between gap-3"><dt class="text-text-muted">Drafted by</dt><dd class="text-right text-text">{{ run.created_by }}</dd></div>
            </dl>
            <p v-if="run.remarks" class="mt-3 border-t border-border pt-3 text-sm text-text">{{ run.remarks }}</p>
            <p v-if="run.accounting_note" class="mt-3 border-t border-border pt-3 text-sm text-text">Accounting: {{ run.accounting_note }}</p>
            <p v-if="run.reopen_reason" class="mt-3 border-t border-border pt-3 text-sm text-text-muted">Last reopened: {{ run.reopen_reason }}</p>
        </Card>
        <Card title="Totals" icon="database" class="lg:col-span-2">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div><dt class="text-text-muted">People</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ run.totals.people }}</dd></div>
                <div><dt class="text-text-muted">Gross</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ formatPeso(run.totals.gross) }}</dd></div>
                <div><dt class="text-text-muted">Deductions</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ formatPeso(run.totals.deductions) }}</dd></div>
                <div><dt class="text-text-muted">Net pay</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ formatPeso(run.totals.net) }}</dd></div>
            </dl>
        </Card>
    </div>

    <Alert v-if="canManage && !isFinalized && !reviewers.length" tone="danger" title="Nobody in Accounting can receive this payroll" class="mb-6">
        No one holds the Accountant III item on the Plantilla, and no one has the Accountant role. Fill the item or give someone the role in Roles &amp; Permissions.
    </Alert>
    <Alert v-if="isDraft" tone="info" title="Prepared by HRD" class="mb-6">
        Review everyone’s pay and deductions. Withholding tax is left blank: submit the payroll to Accounting, where the Accountant III enters it and returns the payroll to HRD to finalize.
    </Alert>
    <Alert v-else-if="withAccounting" tone="warning" title="With Accounting" class="mb-6">
        <template v-if="canEnterTax">Enter each person’s withholding tax below and save, then return the payroll to HRD.</template>
        <template v-else>The Accountant III is reviewing this payroll and entering the withholding tax. It can be changed again once it is returned to HRD.</template>
    </Alert>
    <Alert v-else-if="isReturned && canManage" tone="info" title="Returned by Accounting" class="mb-6">
        The withholding tax is in. Check the payroll, then finalize it, or send it to Accounting again if the tax needs another look.
    </Alert>

    <Card v-if="canEnterTax && withAccounting" title="Withholding tax" icon="clipboard" class="mb-6" description="Leave blank or 0 for no tax. Last month’s tax is shown as a guide.">
        <form id="tax-form" @submit.prevent="saveTaxes">
            <DataTable :columns="taxColumns" :rows="taxRows" :caption="`Withholding tax for ${run.period}`" :export-name="`imis-withholding-tax-${run.month}`" :numbered="false" :default-page-size="100">
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="[row.position, row.office].filter(Boolean).join(' · ')" />
                </template>
                <template #cell-pay="{ row }">
                    <CellFacts
                        class="tabular-nums"
                        :facts="[
                            { label: 'Gross', value: formatPeso(row.gross_pay) },
                            { label: 'Other deductions', value: formatPeso(row.total_deductions - row.withholding_tax) },
                            { label: 'Last month’s tax', value: previousTax[row.employee_id] != null ? formatPeso(previousTax[row.employee_id]) : '—' },
                        ]"
                    />
                </template>
                <template #cell-tax="{ row }">
                    <label :for="`tax-${row.id}`" class="sr-only">Withholding tax of {{ row.name }}</label>
                    <input
                        :id="`tax-${row.id}`"
                        v-model="taxForm.taxes[row.id]"
                        type="number"
                        step="0.01"
                        min="0"
                        :disabled="row.own"
                        :title="row.own ? 'Your own: someone else enters it' : null"
                        class="form-control w-32 px-2 py-1 text-right"
                    />
                    <InputError :message="taxForm.errors[`taxes.${row.id}`]" />
                </template>
                <template #cell-net="{ row }"><span class="font-medium tabular-nums">{{ formatPeso(taxNet(row)) }}</span></template>
            </DataTable>
            <p class="mt-3 text-right text-sm font-semibold text-text">Total withholding tax: <span class="tabular-nums">{{ formatPeso(taxTotal) }}</span></p>
            <InputError :message="taxForm.errors.taxes || taxForm.errors.run" />
        </form>
        <template #footer>
            <Button type="submit" form="tax-form" variant="success" :loading="taxForm.processing" :disabled="!taxForm.isDirty" icon="check">Save withholding tax</Button>
        </template>
    </Card>

    <Alert v-if="belowMinimum.length" tone="warning" title="Net pay below the GAA minimum" class="mb-6">
        {{ belowMinimum.map((item) => item.name).join(', ') }}: net take-home pay under {{ formatPeso(minimumNet) }} a month.
        Check their deductions before finalizing.
    </Alert>

    <Card title="Payroll register" icon="users" :description="editable ? 'Open a row to see and adjust that person’s pay.' : isFinalized ? 'Open a row to see that person’s pay, or their payslip.' : 'Open a row to see that person’s pay.'">
        <DataTable :columns="columns" :rows="entries" :caption="`Payroll register, ${run.period}`" :export-name="`imis-payroll-${run.month}`" numbered>
            <template #cell-name="{ row }">
                <PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="[row.position, row.item_number, row.office].filter(Boolean).join(' · ')">
                    <div class="mt-0.5 flex flex-wrap gap-1">
                        <Badge v-if="row.cancelled" tone="danger">Cancelled</Badge>
                        <Badge v-if="row.adjusted" tone="info">Adjusted</Badge>
                        <Badge v-if="row.below_minimum" tone="warning">Below {{ formatPeso(minimumNet) }}</Badge>
                    </div>
                </PersonCell>
            </template>
            <template #cell-earnings="{ row }">
                <CellFacts
                    class="tabular-nums"
                    :facts="[
                        { label: 'SG/Step', value: row.salary_grade ? `${row.salary_grade}-${row.step}` : null },
                        { label: 'Basic', value: formatPeso(row.basic_salary) },
                        { label: 'Gross', value: formatPeso(row.gross_pay) },
                    ]"
                />
            </template>
            <template #cell-deductions="{ row }">
                <CellFacts
                    class="tabular-nums"
                    :facts="[
                        { label: 'W/Tax', value: row.withholding_tax ? formatPeso(row.withholding_tax) : '—' },
                        { label: 'Total', value: formatPeso(row.total_deductions) },
                    ]"
                />
            </template>
            <template #cell-net_pay="{ row }"><span class="font-semibold tabular-nums">{{ formatPeso(row.net_pay) }}</span></template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <ActionButton icon="eye" tone="neutral" :label="`Details of ${row.name}`" @click="openId = row.id" />
                    <ActionButton v-if="isFinalized" icon="printer" :label="`Payslip of ${row.name}`" :href="`/payslips/${row.id}`" external />
                </div>
            </template>
        </DataTable>
    </Card>

    <Card v-if="leftOut.length" title="Not on this payroll" icon="exclamation" class="mt-6" description="In service, but not paid here. Fix the cause, then regenerate.">
        <ul class="divide-y divide-border text-sm">
            <li v-for="person in leftOut" :key="person.employee_id" class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                <Link :href="`/admin/hr/employees/${person.employee_id}`" class="font-medium text-text hover:text-link">{{ person.name }}</Link>
                <span class="text-text-muted">{{ person.reason }}</span>
            </li>
        </ul>
    </Card>

    <Modal :open="entry !== null" :title="entry ? `${entry.name} — ${run.period}` : ''" size="lg" @close="openId = null">
        <template v-if="entry">
            <p class="mb-4 text-sm text-text-muted">
                {{ entry.position }}<template v-if="entry.item_number"> · {{ entry.item_number }}</template>
                <template v-if="entry.salary_grade"> · SG {{ entry.salary_grade }}, step {{ entry.step }}</template>
                <template v-if="entry.office"> · {{ entry.office }}</template>
            </p>

            <Alert v-if="editable && entry.own" tone="info" class="mb-4">This is your own pay: another payroll officer adjusts it.</Alert>
            <Alert v-if="entry.cancelled" tone="warning" title="This payslip was cancelled" class="mb-4">
                {{ entry.cancel_reason }}<template v-if="entry.cancelled_at"> ({{ formatDateTime(entry.cancelled_at) }})</template>. It counts toward no total or loan balance and stays here for the record.
            </Alert>

            <table class="w-full text-sm">
                <caption class="sr-only">Earnings and deductions</caption>
                <tbody>
                    <tr class="border-b border-border">
                        <th scope="row" class="py-2 text-left font-medium text-text">Basic salary</th>
                        <td class="py-2 text-right tabular-nums">{{ formatPeso(entry.basic_salary) }}</td>
                        <td v-if="canAdjust" class="w-28" />
                    </tr>
                    <template v-for="group in [{ title: 'Earnings', lines: earnings }, { title: 'Deductions', lines: deductions }]" :key="group.title">
                        <tr><th :colspan="canAdjust ? 3 : 2" scope="rowgroup" class="pt-3 pb-1 text-left text-xs font-semibold tracking-wide text-text-muted uppercase">{{ group.title }}</th></tr>
                        <tr v-if="!group.lines.length"><td :colspan="canAdjust ? 3 : 2" class="py-1 text-text-muted">None</td></tr>
                        <tr v-for="line in group.lines" :key="line.id" class="border-b border-border last:border-0">
                            <td class="py-1.5 text-text">
                                {{ line.label }}
                                <Badge v-if="line.original_amount !== null" tone="warning" class="ml-1">Overridden</Badge>
                                <Badge v-else-if="line.adjusted" tone="info" class="ml-1">Adjusted</Badge>
                                <div v-if="line.original_amount !== null" class="text-xs text-text-muted">
                                    Configured {{ formatPeso(line.original_amount) }}<template v-if="line.adjusted_by"> · {{ line.adjusted_by }}</template><template v-if="line.adjusted_at">, {{ formatDateTime(line.adjusted_at) }}</template>
                                </div>
                                <div v-if="line.adjust_reason" class="text-xs text-text-muted">Reason: {{ line.adjust_reason }}</div>
                                <div v-if="line.loan && line.remitted_on" class="text-xs text-text-muted">Paid {{ formatCalendarDate(line.remitted_on) }}</div>
                            </td>
                            <td class="py-1.5 text-right tabular-nums">
                                <form v-if="editingLine === line.id" class="flex items-center justify-end gap-2" @submit.prevent="saveLine(line)">
                                    <label :for="`line-${line.id}`" class="sr-only">Amount for {{ line.label }}</label>
                                    <input
                                        :id="`line-${line.id}`"
                                        v-model="lineForm.amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        required
                                        class="form-control w-32 px-2 py-1 text-right"
                                    />
                                    <label :for="`reason-${line.id}`" class="sr-only">Reason for changing {{ line.label }}</label>
                                    <input :id="`reason-${line.id}`" v-model="lineForm.reason" type="text" maxlength="500" placeholder="Reason (optional)" class="w-44 filter-control py-1 px-2" />
                                    <Button type="submit" size="sm" :loading="lineForm.processing" icon="check">Save</Button>
                                </form>
                                <template v-else>{{ formatPeso(line.amount) }}</template>
                                <InputError v-if="editingLine === line.id" :message="lineForm.errors.amount || lineForm.errors.run" />
                            </td>
                            <td v-if="canAdjust" class="py-1.5 text-right text-sm whitespace-nowrap">
                                <span class="inline-flex gap-1">
                                    <ActionButton v-if="editingLine !== line.id" icon="pencil" :label="`Change ${line.label}`" @click="editLine(line)" />
                                    <ActionButton v-else icon="close" tone="neutral" :label="`Cancel changing ${line.label}`" @click="editingLine = null" />
                                    <ActionButton icon="trash" tone="danger" :label="`Remove ${line.label}`" @click="removeLine(line)" />
                                </span>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="border-t-2 border-border">
                    <tr><th scope="row" class="pt-2 text-left font-medium text-text">Gross pay</th><td class="pt-2 text-right tabular-nums">{{ formatPeso(entry.gross_pay) }}</td><td v-if="canAdjust" /></tr>
                    <tr><th scope="row" class="text-left font-medium text-text">Total deductions</th><td class="text-right tabular-nums">{{ formatPeso(entry.total_deductions) }}</td><td v-if="canAdjust" /></tr>
                    <tr><th scope="row" class="pt-1 text-left font-semibold text-text">Net pay</th><td class="pt-1 text-right font-semibold tabular-nums">{{ formatPeso(entry.net_pay) }}</td><td v-if="canAdjust" /></tr>
                </tfoot>
            </table>

            <form v-if="canAdjust" class="mt-5 grid gap-3 rounded-md border border-border bg-surface-muted p-3 sm:grid-cols-6" @submit.prevent="addLine">
                <p class="text-sm font-medium text-text sm:col-span-6">Add a line for this month</p>
                <FormGroup id="add-component" label="Item" class="sm:col-span-3">
                    <Select id="add-component" v-model="addForm.pay_component_id" :options="componentOptions" />
                </FormGroup>
                <FormGroup v-if="!addForm.pay_component_id" id="add-kind" label="Kind" class="sm:col-span-3">
                    <Select id="add-kind" v-model="addForm.kind" :options="[{ value: 'earning', label: 'Earning' }, { value: 'deduction', label: 'Deduction' }]" />
                </FormGroup>
                <TextInput v-if="!addForm.pay_component_id" id="add-label" v-model="addForm.label" label="Label" helper-text="For example, Absences (2 days)." :error="addForm.errors.label" class="sm:col-span-4" />
                <FormGroup id="add-amount" label="Amount" required :error="addForm.errors.amount || addForm.errors.run" class="sm:col-span-2">
                    <input id="add-amount" v-model="addForm.amount" type="number" step="0.01" min="0.01" required class="block w-full form-control px-3 py-2 text-right" />
                </FormGroup>
                <TextInput id="add-reason" v-model="addForm.reason" label="Reason (optional)" :error="addForm.errors.reason" class="sm:col-span-6" />
                <div class="flex items-end sm:col-span-6">
                    <Button type="submit" size="sm" variant="success" :loading="addForm.processing"><Icon name="plus" size="sm" />Add line</Button>
                </div>
            </form>
        </template>

        <template #footer>
            <Button v-if="canAdjust" variant="ghost" @click="rebuild"><Icon name="refresh" size="sm" />Rebuild from profile</Button>
            <Button v-if="canCancel" variant="ghost" class="mr-auto" @click="askReason('cancel')" icon="close">Cancel payslip</Button>
            <Button v-if="entry && isFinalized" variant="secondary" :href="`/payslips/${entry.id}`" external><Icon name="printer" size="sm" />Payslip</Button>
            <Button v-if="entry" variant="secondary" :href="`/admin/hr/payroll/profiles/${entry.employee_id}`">Pay profile</Button>
            <Button @click="openId = null" icon="close">Close</Button>
        </template>
    </Modal>

    <Modal :open="reasonDialog !== null" :title="reasonDialog === 'reopen' ? `Reopen ${run.period} to correct it` : `Cancel ${entry?.name ?? ''}’s payslip`" size="sm" @close="reasonDialog = null">
        <form id="reason-form" @submit.prevent="submitReason">
            <p class="mb-3 text-sm text-text-muted">
                <template v-if="reasonDialog === 'reopen'">The payroll goes back to HRD, with the withholding tax kept, and its loan payments stop counting until it is finalized again. The reason is kept in the audit trail.</template>
                <template v-else>The payslip stays on the payroll for the record but counts toward no total or loan balance, and any payslip request for it is closed.</template>
            </p>
            <FormGroup id="reason" label="Reason" required :error="reasonForm.errors.reason || reasonForm.errors.run || reasonForm.errors.entry">
                <Textarea id="reason" v-model="reasonForm.reason" :maxlength="500" required :invalid="!!reasonForm.errors.reason" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="ghost" @click="reasonDialog = null" icon="arrowLeft">Back</Button>
            <Button type="submit" form="reason-form" variant="danger" :loading="reasonForm.processing">{{ reasonDialog === 'reopen' ? 'Reopen' : 'Cancel payslip' }}</Button>
        </template>
    </Modal>

    <Modal :open="returnDialog" :title="`Return ${run.period} to HRD`" size="sm" @close="returnDialog = false">
        <form id="return-form" @submit.prevent="sendBack">
            <p class="mb-3 text-sm text-text-muted">
                HRD finalizes the payroll with the withholding tax saved above. Save any changes first.
                <template v-if="missingTax"> {{ missingTax }} {{ missingTax === 1 ? 'person has' : 'people have' }} no withholding tax.</template>
            </p>
            <FormGroup id="return-note" label="Note to HRD (optional)" :error="returnForm.errors.note || returnForm.errors.run">
                <Textarea id="return-note" v-model="returnForm.note" :maxlength="1000" :invalid="!!returnForm.errors.note" />
            </FormGroup>
        </form>
        <template #footer>
            <Button variant="ghost" @click="returnDialog = false" icon="arrowLeft">Back</Button>
            <Button type="submit" form="return-form" variant="success" :loading="returnForm.processing" :disabled="taxForm.isDirty">Return to HRD</Button>
        </template>
    </Modal>
</template>
