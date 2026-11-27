<script setup>
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import Textarea from '@cscro8/ui/Components/Forms/Textarea.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatDateTime } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-6c: monetization and terminal leave for HR (legacy hrd/monetization.php).
 * Approving takes the days off the credits; once paid, mark it released.
 */
const props = defineProps({
    status: { type: String, required: true },
    requests: { type: Array, required: true },
    counts: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
    separated: { type: Array, required: true },
});

const tabs = ['pending', 'approved', 'released', 'rejected', 'cancelled'];
const show = (status) => router.get('/admin/hr/leave/monetization', { status }, { preserveScroll: true });

const deciding = ref(null);
const decision = useForm({ decision: 'approved', remarks: '' });
const openDecision = (row, value) => {
    deciding.value = row;
    decision.reset();
    decision.clearErrors();
    decision.decision = value;
};
const submitDecision = () => decision.post(`/admin/hr/leave/monetization/${deciding.value.id}/decide`, { preserveScroll: true, onSuccess: () => (deciding.value = null) });

const release = async (row) => {
    if (await confirm({ title: `Mark ${row.reference_number} released?`, text: 'Do this once it has been paid.', confirmText: 'Mark released' })) {
        router.post(`/admin/hr/leave/monetization/${row.id}/release`, {}, { preserveScroll: true });
    }
};

const terminalOpen = ref(false);
const terminal = useForm({ employee_id: '', remarks: '', clearance_on_file: false });
const submitTerminal = () => terminal.post('/admin/hr/leave/monetization/terminal', { preserveScroll: true, onSuccess: () => (terminalOpen.value = false) });

const days = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });

// Days and pay fold into one cell; hidden columns keep each figure in the CSV.
const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'figures', label: 'Days & amount', sortable: false, export: false },
    { key: 'created_at', label: 'Filed' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'right' },
    { key: 'reference_number', label: 'Reference', hidden: true },
    { key: 'kind_label', label: 'Kind', hidden: true },
    { key: 'days', label: 'Days', hidden: true, value: (row) => days(row.days) },
    { key: 'vl_days', label: 'VL days', hidden: true, value: (row) => days(row.vl_days) },
    { key: 'sl_days', label: 'SL days', hidden: true, value: (row) => days(row.sl_days) },
    { key: 'monthly_salary', label: 'Monthly salary', hidden: true },
    { key: 'amount', label: 'Amount', hidden: true },
];
</script>

<template>
    <Head title="Leave Monetization" />

    <PageHeader title="Leave Monetization" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Monetization' }]">
        <template v-if="canManage" #actions>
            <Button variant="success" @click="terminalOpen = true"><Icon name="plus" size="sm" />File terminal leave</Button>
        </template>
    </PageHeader>

    <Card title="Requests" icon="database">
        <template #actions>
            <FilterButtons :model-value="status" label="Status" :options="tabs.map((tab) => ({ value: tab, label: tab.charAt(0).toUpperCase() + tab.slice(1), count: counts[tab] ?? 0 }))" @update:model-value="show" />
        </template>

        <DataTable :columns="columns" :rows="requests" caption="Leave monetization requests" :export-name="`imis-monetization-${status}`" :numbered="false" :empty-text="`No ${status} requests.`">
            <template #cell-name="{ row }">
                <PersonCell :name="row.name" :photo-url="row.photo_url" :href="`/admin/hr/employees/${row.employee_id}/leave`" :subtitle="`${row.reference_number} · ${row.kind_label}`" />
            </template>
            <template #cell-figures="{ row }">
                <CellFacts
                    class="tabular-nums"
                    :facts="[
                        { label: 'Days (VL / SL)', value: `${days(row.days)} (${days(row.vl_days)} / ${days(row.sl_days)})` },
                        { label: 'Monthly salary', value: formatPeso(row.monthly_salary) },
                        { label: 'Amount', value: formatPeso(row.amount) },
                    ]"
                />
            </template>
            <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span></template>
            <template #cell-actions="{ row }">
                <div v-if="canManage && !row.own" class="flex justify-end gap-1">
                    <template v-if="row.status === 'pending'">
                        <ActionButton icon="check" tone="success" :label="`Approve ${row.reference_number}`" @click="openDecision(row, 'approved')" />
                        <ActionButton icon="close" tone="danger" :label="`Reject ${row.reference_number}`" @click="openDecision(row, 'rejected')" />
                    </template>
                    <ActionButton v-if="row.status === 'approved'" icon="paperAirplane" :label="`Mark ${row.reference_number} released`" @click="release(row)" />
                </div>
                <span v-else-if="row.own" class="text-xs text-text-muted">Your own</span>
            </template>
        </DataTable>
    </Card>

    <Modal :open="deciding !== null" :title="decision.decision === 'approved' ? 'Approve request' : 'Reject request'" :tone="decision.decision === 'approved' ? 'success' : 'danger'" @close="deciding = null">
        <form id="mon-decision-form" class="space-y-3" @submit.prevent="submitDecision">
            <p v-if="deciding" class="text-sm text-text">
                {{ deciding.reference_number }} — {{ deciding.name }}: {{ days(deciding.days) }} days, {{ formatPeso(deciding.amount) }}.
                <template v-if="decision.decision === 'approved'">The days come off their credits.</template>
            </p>
            <div>
                <InputLabel for="mon-decision-remarks">Remarks{{ decision.decision === 'rejected' ? '' : ' (optional)' }}</InputLabel>
                <Textarea id="mon-decision-remarks" v-model="decision.remarks" class="mt-1" :required="decision.decision === 'rejected'" />
                <InputError :message="decision.errors.remarks || decision.errors.status" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="deciding = null" icon="close">Cancel</Button>
            <Button type="submit" form="mon-decision-form" :variant="decision.decision === 'approved' ? 'success' : 'danger'" :loading="decision.processing">{{ decision.decision === 'approved' ? 'Approve' : 'Reject' }}</Button>
        </template>
    </Modal>

    <Modal :open="terminalOpen" title="File terminal leave" icon="plus" tone="success" @close="terminalOpen = false">
        <form id="terminal-form" class="space-y-3" @submit.prevent="submitTerminal">
            <p class="text-sm text-text">All of their remaining Vacation and Sick Leave is commuted, at their current monthly salary ÷ 22 a day.</p>
            <div>
                <InputLabel for="terminal-employee">Separated employee</InputLabel>
                <Select id="terminal-employee" v-model="terminal.employee_id" class="mt-1 block w-full" :options="separated" placeholder="— Select —" required />
                <InputError :message="terminal.errors.employee_id" />
                <label class="mt-3 flex items-start gap-2 text-sm text-text">
                    <input v-model="terminal.clearance_on_file" type="checkbox" class="mt-0.5 h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                    Their clearance from money, property and work accountabilities is on file (CSC MC No. 2, s. 1985).
                </label>
                <InputError :message="terminal.errors.clearance_on_file" />
            </div>
            <div>
                <InputLabel for="terminal-remarks">Remarks (optional)</InputLabel>
                <Textarea id="terminal-remarks" v-model="terminal.remarks" class="mt-1" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="terminalOpen = false" icon="close">Cancel</Button>
            <Button type="submit" form="terminal-form" variant="success" :loading="terminal.processing">File</Button>
        </template>
    </Modal>
</template>
