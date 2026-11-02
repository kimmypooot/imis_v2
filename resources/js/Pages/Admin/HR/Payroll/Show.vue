<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Badge from '../../../../Components/UI/Badge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import Alert from '../../../../Components/Feedback/Alert.vue';
import Select from '../../../../Components/Forms/Select.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate, formatDateTime } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: one month's payroll. While it's a draft HR reviews each person's
 * pay and adjusts lines (absences, a one-time allowance…); finalizing
 * locks it, counts its loan lines as paid, and lets staff request their
 * payslips. The table is the payroll register (CSV / print).
 */
const props = defineProps({
    run: { type: Object, required: true },
    entries: { type: Array, required: true },
    leftOut: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    components: { type: Array, required: true },
    minimumNet: { type: Number, required: true },
});

const isDraft = computed(() => props.run.status === 'draft');
const editable = computed(() => props.canManage && isDraft.value);
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

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'grade', label: 'SG/Step', value: (row) => (row.salary_grade ? `${row.salary_grade}-${row.step}` : '') },
    { key: 'office', label: 'Office' },
    { key: 'basic_salary', label: 'Basic', align: 'right' },
    { key: 'gross_pay', label: 'Gross', align: 'right' },
    { key: 'total_deductions', label: 'Deductions', align: 'right' },
    { key: 'net_pay', label: 'Net pay', align: 'right' },
    { key: 'first_half', label: '15th', align: 'right' },
    { key: 'second_half', label: '30th', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head :title="`Payroll — ${run.period}`" />

    <PageHeader
        :title="`Payroll — ${run.period}`"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Payroll', href: '/admin/hr/payroll' }, { label: run.period }]"
    >
        <template v-if="canManage" #actions>
            <template v-if="isDraft">
                <Button variant="secondary" @click="regenerate"><Icon name="refresh" size="sm" />Regenerate</Button>
                <Button variant="secondary" @click="destroy">Delete draft</Button>
                <Button variant="success" @click="finalize"><Icon name="checkCircle" size="sm" />Finalize</Button>
            </template>
            <Button v-else variant="secondary" @click="askReason('reopen')">Reopen to correct</Button>
        </template>
    </PageHeader>

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <Card title="Status" icon="clipboard">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-text-muted">Status</dt><dd><StatusBadge :status="isDraft ? 'draft' : 'active'" :label="run.status_label" /></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-text-muted">Releases</dt><dd class="text-right text-text">{{ formatCalendarDate(run.first_release_on) }} · {{ formatCalendarDate(run.second_release_on) }}</dd></div>
                <div v-if="run.finalized_at" class="flex justify-between gap-3"><dt class="text-text-muted">Finalized</dt><dd class="text-right text-text">{{ formatDateTime(run.finalized_at) }}<span v-if="run.finalized_by"> by {{ run.finalized_by }}</span></dd></div>
                <div v-if="run.created_by" class="flex justify-between gap-3"><dt class="text-text-muted">Drafted by</dt><dd class="text-right text-text">{{ run.created_by }}</dd></div>
            </dl>
            <p v-if="run.remarks" class="mt-3 border-t border-border pt-3 text-sm text-text">{{ run.remarks }}</p>
            <p v-if="run.reopen_reason" class="mt-3 border-t border-border pt-3 text-sm text-text-muted">Last reopened: {{ run.reopen_reason }}</p>
        </Card>
        <Card title="Totals" icon="database" class="lg:col-span-2">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div><dt class="text-text-muted">People</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ run.totals.people }}</dd></div>
                <div><dt class="text-text-muted">Gross</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ formatPeso(run.totals.gross) }}</dd></div>
                <div><dt class="text-text-muted">Deductions</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ formatPeso(run.totals.deductions) }}</dd></div>
                <div><dt class="text-text-muted">Net pay</dt><dd class="text-lg font-semibold tabular-nums text-text">{{ formatPeso(run.totals.net) }}</dd></div>
                <div><dt class="text-text-muted">15th release</dt><dd class="tabular-nums text-text">{{ formatPeso(run.totals.first_half) }}</dd></div>
                <div><dt class="text-text-muted">30th release</dt><dd class="tabular-nums text-text">{{ formatPeso(run.totals.second_half) }}</dd></div>
            </dl>
        </Card>
    </div>

    <Alert v-if="belowMinimum.length" tone="warning" title="Net pay below the GAA minimum" class="mb-6">
        {{ belowMinimum.map((item) => item.name).join(', ') }}: net take-home pay under {{ formatPeso(minimumNet) }} a month.
        Check their deductions before finalizing.
    </Alert>

    <Card title="Payroll register" icon="users" :description="isDraft ? 'Open a row to see and adjust that person’s pay.' : 'Open a row to see that person’s pay, or their payslip.'">
        <DataTable :columns="columns" :rows="entries" :caption="`Payroll register, ${run.period}`" :export-name="`imis-payroll-${run.month}`" numbered>
            <template #cell-name="{ row }">
                <button type="button" class="text-left font-medium text-text hover:text-primary" @click="openId = row.id">{{ row.name }}</button>
                <div class="text-xs text-text-muted">{{ row.position }}<template v-if="row.item_number"> · {{ row.item_number }}</template></div>
                <div class="mt-0.5 flex flex-wrap gap-1">
                    <Badge v-if="row.cancelled" tone="danger">Cancelled</Badge>
                    <Badge v-if="row.adjusted" tone="info">Adjusted</Badge>
                    <Badge v-if="row.below_minimum" tone="warning">Below {{ formatPeso(minimumNet) }}</Badge>
                </div>
            </template>
            <template #cell-office="{ row }">{{ row.office || '—' }}</template>
            <template #cell-basic_salary="{ row }"><span class="tabular-nums">{{ formatPeso(row.basic_salary) }}</span></template>
            <template #cell-gross_pay="{ row }"><span class="tabular-nums">{{ formatPeso(row.gross_pay) }}</span></template>
            <template #cell-total_deductions="{ row }"><span class="tabular-nums">{{ formatPeso(row.total_deductions) }}</span></template>
            <template #cell-net_pay="{ row }"><span class="font-semibold tabular-nums">{{ formatPeso(row.net_pay) }}</span></template>
            <template #cell-first_half="{ row }"><span class="tabular-nums">{{ formatPeso(row.first_half) }}</span></template>
            <template #cell-second_half="{ row }"><span class="tabular-nums">{{ formatPeso(row.second_half) }}</span></template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-1">
                    <ActionButton icon="eye" tone="neutral" :label="`Details of ${row.name}`" @click="openId = row.id" />
                    <ActionButton v-if="!isDraft" icon="printer" :label="`Payslip of ${row.name}`" :href="`/payslips/${row.id}`" external />
                </div>
            </template>
        </DataTable>
    </Card>

    <Card v-if="leftOut.length" title="Not on this payroll" icon="exclamation" class="mt-6" description="In service, but not paid here. Fix the cause, then regenerate.">
        <ul class="divide-y divide-border text-sm">
            <li v-for="person in leftOut" :key="person.employee_id" class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                <Link :href="`/admin/hr/employees/${person.employee_id}`" class="font-medium text-text hover:text-primary">{{ person.name }}</Link>
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
                                        class="w-32 rounded-md border border-border-strong bg-surface px-2 py-1 text-right text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                                    />
                                    <label :for="`reason-${line.id}`" class="sr-only">Reason for changing {{ line.label }}</label>
                                    <input :id="`reason-${line.id}`" v-model="lineForm.reason" type="text" maxlength="500" placeholder="Reason (optional)" class="w-44 rounded-md border border-border-strong bg-surface px-2 py-1 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring" />
                                    <Button type="submit" size="sm" :loading="lineForm.processing">Save</Button>
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
                    <tr class="text-text-muted"><td class="text-xs">15th / 30th</td><td class="text-right text-xs tabular-nums">{{ formatPeso(entry.first_half) }} / {{ formatPeso(entry.second_half) }}</td><td v-if="canAdjust" /></tr>
                </tfoot>
            </table>

            <form v-if="canAdjust" class="mt-5 grid gap-3 rounded-md border border-border bg-surface-muted p-3 sm:grid-cols-6" @submit.prevent="addLine">
                <p class="text-sm font-medium text-text sm:col-span-6">Add a line for this month</p>
                <div class="sm:col-span-3">
                    <label for="add-component" class="block text-xs font-medium text-text-muted">Item</label>
                    <Select id="add-component" v-model="addForm.pay_component_id" class="mt-1" :options="componentOptions" />
                </div>
                <div v-if="!addForm.pay_component_id" class="sm:col-span-3">
                    <label for="add-kind" class="block text-xs font-medium text-text-muted">Kind</label>
                    <Select id="add-kind" v-model="addForm.kind" class="mt-1" :options="[{ value: 'earning', label: 'Earning' }, { value: 'deduction', label: 'Deduction' }]" />
                </div>
                <div v-if="!addForm.pay_component_id" class="sm:col-span-4">
                    <label for="add-label" class="block text-xs font-medium text-text-muted">Label</label>
                    <input id="add-label" v-model="addForm.label" type="text" maxlength="120" placeholder="e.g. Absences (2 days)" class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring" />
                    <InputError :message="addForm.errors.label" />
                </div>
                <div class="sm:col-span-2">
                    <label for="add-amount" class="block text-xs font-medium text-text-muted">Amount</label>
                    <input id="add-amount" v-model="addForm.amount" type="number" step="0.01" min="0.01" required class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-right text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring" />
                    <InputError :message="addForm.errors.amount || addForm.errors.run" />
                </div>
                <div class="sm:col-span-6">
                    <label for="add-reason" class="block text-xs font-medium text-text-muted">Reason (optional)</label>
                    <input id="add-reason" v-model="addForm.reason" type="text" maxlength="500" class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring" />
                </div>
                <div class="flex items-end sm:col-span-6">
                    <Button type="submit" size="sm" variant="success" :loading="addForm.processing"><Icon name="plus" size="sm" />Add line</Button>
                </div>
            </form>
        </template>

        <template #footer>
            <Button v-if="canAdjust" variant="ghost" @click="rebuild"><Icon name="refresh" size="sm" />Rebuild from profile</Button>
            <Button v-if="canCancel" variant="ghost" class="mr-auto" @click="askReason('cancel')">Cancel payslip</Button>
            <Button v-if="entry && !isDraft" variant="secondary" :href="`/payslips/${entry.id}`" external><Icon name="printer" size="sm" />Payslip</Button>
            <Button v-if="entry" variant="secondary" :href="`/admin/hr/payroll/profiles/${entry.employee_id}`">Pay profile</Button>
            <Button @click="openId = null">Close</Button>
        </template>
    </Modal>

    <Modal :open="reasonDialog !== null" :title="reasonDialog === 'reopen' ? `Reopen ${run.period} to correct it` : `Cancel ${entry?.name ?? ''}’s payslip`" size="sm" @close="reasonDialog = null">
        <form id="reason-form" @submit.prevent="submitReason">
            <p class="mb-3 text-sm text-text-muted">
                <template v-if="reasonDialog === 'reopen'">The payroll becomes a draft again and its loan payments stop counting until it is finalized. The reason is kept in the audit trail.</template>
                <template v-else>The payslip stays on the payroll for the record but counts toward no total or loan balance, and any payslip request for it is closed.</template>
            </p>
            <label for="reason" class="block text-sm font-medium text-text">Reason</label>
            <textarea id="reason" v-model="reasonForm.reason" rows="3" maxlength="500" required class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring" />
            <InputError :message="reasonForm.errors.reason || reasonForm.errors.run || reasonForm.errors.entry" />
        </form>
        <template #footer>
            <Button variant="ghost" @click="reasonDialog = null">Back</Button>
            <Button type="submit" form="reason-form" variant="danger" :loading="reasonForm.processing">{{ reasonDialog === 'reopen' ? 'Reopen' : 'Cancel payslip' }}</Button>
        </template>
    </Modal>
</template>
