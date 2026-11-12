<script setup>
import InputLabel from '../../../../Components/Forms/InputLabel.vue';
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
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-8: one person's pay profile — what applies to everyone, their own
 * recurring items, their loans with what's paid and left, and their
 * recent pay. Never editable by the person themself.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    items: { type: Array, required: true },
    loans: { type: Array, required: true },
    automatic: { type: Array, required: true },
    recent: { type: Array, required: true },
    components: { type: Array, required: true },
    loanTypes: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const base = `/admin/hr/payroll/profiles/${props.employee.id}`;
const thisMonth = new Date().toISOString().slice(0, 7);

// Recurring items
const itemOpen = ref(false);
const editingItem = ref(null);
const editItem = (item = null) => {
    editingItem.value = item;
    itemOpen.value = true;
};
const removeItem = async (item) => {
    if (await confirm({ title: `Remove ${item.component}?`, text: 'Payrolls already drafted keep it until they are regenerated. To stop it from a month on, set an end month instead.', tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`${base}/items/${item.id}`, { preserveScroll: true });
    }
};
const itemFields = [
    { key: 'pay_component_id', label: 'Item', type: 'select', required: true, span: 'full', options: props.components },
    { key: 'amount', label: 'Monthly amount', type: 'number', help: 'Leave blank to use the item’s own rule; 0 exempts this person from an automatic item.' },
    { key: 'effective_from', label: 'From (month)', type: 'month', required: true },
    { key: 'effective_to', label: 'Until (month, optional)', type: 'month' },
    { key: 'remarks', label: 'Remarks', type: 'textarea' },
];

// Loans
const loanOpen = ref(false);
const editingLoan = ref(null);
const editLoan = (loan = null) => {
    editingLoan.value = loan;
    loanOpen.value = true;
};
const loanFields = [
    { key: 'pay_component_id', label: 'Loan type', type: 'select', required: true, options: props.loanTypes },
    { key: 'reference', label: 'Reference / account no.' },
    { key: 'granted_on', label: 'Date granted', type: 'date' },
    { key: 'principal', label: 'Principal', type: 'number', required: true },
    { key: 'total_payable', label: 'Total payable (with interest)', type: 'number', required: true },
    { key: 'monthly_amortization', label: 'Monthly amortization', type: 'number', required: true },
    { key: 'start_month', label: 'First deduction (month)', type: 'month', required: true },
    { key: 'paid_before', label: 'Already paid before IMIS', type: 'number', help: 'Payments made before this loan was entered.' },
    { key: 'remarks', label: 'Remarks', type: 'textarea' },
    { key: 'change_reason', label: 'Reason for this change', type: 'textarea', help: 'Optional. Kept in the audit trail when you edit a loan.' },
];

const stopping = ref(null);
const stopForm = useForm({ stopped_on: new Date().toISOString().slice(0, 10), reason: '' });
const stop = () => stopForm.post(`${base}/loans/${stopping.value.id}/stop`, { preserveScroll: true, onSuccess: () => (stopping.value = null) });
const resume = (loan) => router.post(`${base}/loans/${loan.id}/resume`, {}, { preserveScroll: true });
const removeLoan = async (loan) => {
    if (await confirm({ title: `Delete this ${loan.type}?`, text: 'Only a loan never deducted on a payroll can be deleted.', tone: 'danger', confirmText: 'Delete' })) {
        router.delete(`${base}/loans/${loan.id}`, { preserveScroll: true });
    }
};
const loanTones = { active: 'active', paid: 'info', stopped: 'logged_out', cancelled: 'inactive' };

// Cancelling a loan needs a reason; so does nothing else here.
const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });
const cancelLoan = () => cancelForm.post(`${base}/loans/${cancelling.value.id}/cancel`, { preserveScroll: true, onSuccess: () => { cancelling.value = null; cancelForm.reset(); } });

// The installment schedule: what each payroll took, then what is still to come.
const scheduleId = ref(null);
const scheduled = computed(() => props.loans.find((loan) => loan.id === scheduleId.value) ?? null);
const scheduleTones = { scheduled: 'draft', reflected: 'info', confirmed: 'pending', paid: 'active', overdue: 'inactive' };
const markPaid = (loan, row, paid) =>
    router.post(`${base}/loans/${loan.id}/lines/${row.line_id}/remit`, { paid }, { preserveScroll: true });

const itemColumns = [
    { key: 'component', label: 'Item' },
    { key: 'amount', label: 'Monthly amount', align: 'right' },
    { key: 'period', label: 'In effect', value: (row) => `${row.effective_from} – ${row.effective_to ?? ''}` },
    { key: 'actions', label: '', sortable: false, export: false },
];
const loanColumns = [
    { key: 'type', label: 'Loan' },
    { key: 'monthly_amortization', label: 'Monthly', align: 'right' },
    { key: 'total_payable', label: 'Payable', align: 'right' },
    { key: 'paid', label: 'Paid', align: 'right' },
    { key: 'balance', label: 'Balance', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', sortable: false, export: false },
];
const recentColumns = [
    { key: 'period', label: 'Month' },
    { key: 'gross_pay', label: 'Gross', align: 'right' },
    { key: 'total_deductions', label: 'Deductions', align: 'right' },
    { key: 'net_pay', label: 'Net', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head :title="`Pay profile — ${employee.name}`" />

    <PageHeader
        :title="employee.name"
        :description="employee.position ? `${employee.position} · pay profile` : 'Pay profile'"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Pay Profiles', href: '/admin/hr/payroll/profiles' }, { label: employee.name }]"
    />

    <Alert v-if="!canManage" tone="info" class="mb-6">Read-only: this is your own pay profile, or you can only view payroll.</Alert>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <Card title="Recurring items" icon="clipboard" description="On top of the items everyone gets. An amount here replaces the item’s own rule.">
                <template v-if="canManage" #actions>
                    <Button size="sm" variant="success" @click="editItem()"><Icon name="plus" size="sm" />Add item</Button>
                </template>
                <DataTable :columns="itemColumns" :rows="items" :caption="`Recurring items of ${employee.name}`" export-name="imis-pay-items" :searchable="false" empty-text="No recurring items: only the automatic ones apply.">
                    <template #cell-component="{ row }">
                        <span class="font-medium text-text">{{ row.component }}</span>
                        <Badge v-if="!row.current" tone="neutral" class="ml-1">Not in effect now</Badge>
                        <div class="text-xs text-text-muted">{{ row.kind === 'earning' ? 'Earning' : 'Deduction' }} · {{ row.rule }}</div>
                    </template>
                    <template #cell-amount="{ row }"><span class="tabular-nums">{{ row.amount === null ? 'By rule' : formatPeso(row.amount) }}</span></template>
                    <template #cell-period="{ row }"><span class="whitespace-nowrap">{{ row.effective_from }} – {{ row.effective_to ?? 'onward' }}</span></template>
                    <template #cell-actions="{ row }">
                        <div v-if="canManage" class="flex justify-end gap-1">
                            <ActionButton icon="pencil" :label="`Edit ${row.component}`" @click="editItem(row)" />
                            <ActionButton icon="trash" tone="danger" :label="`Remove ${row.component}`" @click="removeItem(row)" />
                        </div>
                    </template>
                </DataTable>
            </Card>

            <Card title="Loans" icon="key" description="Deducted monthly from the first deduction month until paid. Balances count finalized payrolls only.">
                <template v-if="canManage" #actions>
                    <Button size="sm" variant="success" @click="editLoan()"><Icon name="plus" size="sm" />Add loan</Button>
                </template>
                <DataTable :columns="loanColumns" :rows="loans" :caption="`Loans of ${employee.name}`" export-name="imis-loans" :searchable="false" empty-text="No loans.">
                    <template #cell-type="{ row }">
                        <span class="font-medium text-text">{{ row.type }}</span>
                        <div class="text-xs text-text-muted">
                            <template v-if="row.reference">{{ row.reference }} · </template>from {{ row.start_month }}
                            <template v-if="row.months_left && row.status === 'active'"> · {{ row.months_left }} month{{ row.months_left === 1 ? '' : 's' }} left</template>
                            <template v-if="row.expected_end"> · ends {{ row.expected_end }}</template>
                            · {{ row.installments_paid }} deducted
                        </div>
                    </template>
                    <template #cell-monthly_amortization="{ row }"><span class="tabular-nums">{{ formatPeso(row.monthly_amortization) }}</span></template>
                    <template #cell-total_payable="{ row }"><span class="tabular-nums">{{ formatPeso(row.total_payable) }}</span></template>
                    <template #cell-paid="{ row }"><span class="tabular-nums">{{ formatPeso(row.paid) }}</span></template>
                    <template #cell-balance="{ row }"><span class="font-medium tabular-nums">{{ formatPeso(row.balance) }}</span></template>
                    <template #cell-status="{ row }">
                        <StatusBadge :status="loanTones[row.status]" :label="row.status_label" />
                        <div v-if="row.cancelled_on || row.stopped_on" class="mt-1 text-xs text-text-muted">since {{ formatCalendarDate(row.cancelled_on ?? row.stopped_on) }}</div>
                    </template>
                    <template #cell-actions="{ row }">
                        <div v-if="canManage" class="flex justify-end gap-3 text-sm whitespace-nowrap">
                            <button type="button" class="text-primary hover:underline" @click="scheduleId = row.id">Schedule</button>
                            <button type="button" class="text-primary hover:underline" @click="editLoan(row)">Edit</button>
                            <button v-if="row.status === 'active'" type="button" class="text-primary hover:underline" @click="stopping = row">Suspend</button>
                            <button v-if="row.status === 'stopped'" type="button" class="text-primary hover:underline" @click="resume(row)">Resume</button>
                            <button v-if="row.status === 'active' || row.status === 'stopped'" type="button" class="text-danger hover:underline" @click="cancelling = row">Cancel</button>
                            <button v-if="row.deletable" type="button" class="text-danger hover:underline" @click="removeLoan(row)">Delete</button>
                        </div>
                    </template>
                </DataTable>
            </Card>
        </div>

        <div class="space-y-6">
            <Card title="Everyone gets" icon="users" description="Automatic items, unless an item on this profile replaces one.">
                <ul class="divide-y divide-border text-sm">
                    <li v-for="component in automatic" :key="component.id" class="flex justify-between gap-3 py-2">
                        <span class="text-text">{{ component.name }}</span>
                        <span class="text-right text-text-muted">{{ component.rule }}</span>
                    </li>
                </ul>
            </Card>

            <Card title="Recent pay" icon="database">
                <DataTable :columns="recentColumns" :rows="recent" :caption="`Recent pay of ${employee.name}`" export-name="imis-recent-pay" :searchable="false" :exportable="false" empty-text="Not on a payroll yet.">
                    <template #cell-period="{ row }">
                        <Link :href="`/admin/hr/payroll/runs/${row.run_id}`" class="text-text hover:text-primary">{{ row.period }}</Link>
                        <Badge v-if="!row.finalized" tone="neutral" class="ml-1">Draft</Badge>
                    </template>
                    <template #cell-gross_pay="{ row }"><span class="tabular-nums">{{ formatPeso(row.gross_pay) }}</span></template>
                    <template #cell-total_deductions="{ row }"><span class="tabular-nums">{{ formatPeso(row.total_deductions) }}</span></template>
                    <template #cell-net_pay="{ row }"><span class="font-medium tabular-nums">{{ formatPeso(row.net_pay) }}</span></template>
                    <template #cell-actions="{ row }">
                        <a v-if="row.finalized" :href="`/payslips/${row.id}`" target="_blank" rel="noopener" class="text-sm text-primary hover:underline">Payslip</a>
                    </template>
                </DataTable>
            </Card>
        </div>
    </div>

    <RecordModal
        v-if="canManage"
        :open="itemOpen"
        noun="Recurring Item"
        :record="editingItem"
        :fields="itemFields"
        :defaults="{ pay_component_id: '', amount: '', effective_from: thisMonth, effective_to: '', remarks: '' }"
        :nullable="['amount', 'effective_to', 'remarks']"
        :store-url="`${base}/items`"
        :update-url="(record) => `${base}/items/${record.id}`"
        @close="itemOpen = false"
    />

    <RecordModal
        v-if="canManage"
        :open="loanOpen"
        noun="Loan"
        :record="editingLoan"
        :fields="loanFields"
        :defaults="{ pay_component_id: '', reference: '', granted_on: '', principal: '', total_payable: '', monthly_amortization: '', start_month: thisMonth, paid_before: 0, remarks: '', change_reason: '' }"
        :nullable="['reference', 'granted_on', 'remarks', 'change_reason']"
        :store-url="`${base}/loans`"
        :update-url="(record) => `${base}/loans/${record.id}`"
        @close="loanOpen = false"
    />

    <Modal :open="stopping !== null" title="Suspend loan deductions" icon="key" tone="danger" @close="stopping = null">
        <form id="loan-stop-form" class="space-y-3" @submit.prevent="stop">
            <p v-if="stopping" class="text-sm text-text">
                No more {{ stopping.type }} deductions after this date. The balance ({{ formatPeso(stopping.balance) }}) stays on record, and the loan can be resumed.
            </p>
            <div>
                <InputLabel for="loan-stopped-on">Last day deducted</InputLabel>
                <input id="loan-stopped-on" v-model="stopForm.stopped_on" type="date" required class="block w-full form-control mt-1 px-3 py-2" />
                <InputError :message="stopForm.errors.stopped_on" />
            </div>
            <div>
                <InputLabel for="loan-stop-reason">Reason (optional)</InputLabel>
                <input id="loan-stop-reason" v-model="stopForm.reason" type="text" maxlength="500" class="block w-full form-control mt-1 px-3 py-2" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="stopping = null">Cancel</Button>
            <Button type="submit" form="loan-stop-form" variant="danger" :loading="stopForm.processing">Suspend deductions</Button>
        </template>
    </Modal>

    <Modal :open="cancelling !== null" title="Cancel loan" icon="key" tone="danger" @close="cancelling = null">
        <form id="loan-cancel-form" class="space-y-3" @submit.prevent="cancelLoan">
            <p v-if="cancelling" class="text-sm text-text">
                The {{ cancelling.type }} is ended for good: nothing more is deducted and it cannot be resumed. What was already deducted stays on record.
            </p>
            <div>
                <InputLabel for="loan-cancel-reason">Reason</InputLabel>
                <textarea id="loan-cancel-reason" v-model="cancelForm.reason" rows="3" maxlength="500" required class="block w-full form-control mt-1 px-3 py-2" />
                <InputError :message="cancelForm.errors.reason" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="cancelling = null">Back</Button>
            <Button type="submit" form="loan-cancel-form" variant="danger" :loading="cancelForm.processing">Cancel loan</Button>
        </template>
    </Modal>

    <Modal :open="scheduled !== null" :title="scheduled ? `${scheduled.type} schedule` : ''" size="lg" @close="scheduleId = null">
        <template v-if="scheduled">
            <p class="mb-3 text-sm text-text-muted">
                IMIS records what payrolls take; it does not collect or remit anything. “Confirmed” is on a finalized payroll, “Paid” is when HR marks it remitted,
                and “Overdue” is confirmed but not marked paid 30 days after release.
            </p>
            <div class="table-card"><table class="data-table" data-density="compact">
                <caption class="sr-only">Installments of {{ scheduled.type }}</caption>
                <thead>
                    <tr><th scope="col">Month</th><th scope="col" class="text-right">Amount</th><th scope="col">Status</th><th v-if="canManage" scope="col"  /></tr>
                </thead>
                <tbody>
                    <tr v-for="row in scheduled.schedule.rows" :key="row.month">
                        <td class="text-text">{{ row.month }}</td>
                        <td class="text-right tabular-nums">{{ formatPeso(row.amount) }}</td>
                        <td>
                            <StatusBadge :status="scheduleTones[row.status]" :label="row.status_label" />
                            <span v-if="row.remitted_on" class="ml-1 text-xs text-text-muted">{{ formatCalendarDate(row.remitted_on) }}</span>
                        </td>
                        <td v-if="canManage" class="text-right whitespace-nowrap">
                            <button v-if="row.finalized && row.status !== 'paid'" type="button" class="text-primary hover:underline" @click="markPaid(scheduled, row, true)">Mark paid</button>
                            <button v-else-if="row.status === 'paid'" type="button" class="text-text-muted hover:underline" @click="markPaid(scheduled, row, false)">Undo</button>
                        </td>
                    </tr>
                </tbody>
            </table></div>
            <p v-if="scheduled.schedule.more" class="mt-2 text-xs text-text-muted">…and {{ scheduled.schedule.more }} more after these.</p>
            <p v-if="!scheduled.schedule.rows.length" class="py-2 text-sm text-text-muted">Nothing is scheduled: the loan is complete, suspended or cancelled.</p>
            <p v-if="scheduled.schedule.expected_end" class="mt-3 text-sm text-text">Expected last deduction: <strong>{{ scheduled.schedule.expected_end }}</strong>. Balance {{ formatPeso(scheduled.balance) }}.</p>
        </template>
        <template #footer><Button @click="scheduleId = null">Close</Button></template>
    </Modal>
</template>
