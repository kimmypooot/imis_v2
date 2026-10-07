<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import Select from '../../../../Components/Forms/Select.vue';
import Textarea from '../../../../Components/Forms/Textarea.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import { confirm } from '../../../../Composables/useDialog';
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
const terminal = useForm({ employee_id: '', remarks: '' });
const submitTerminal = () => terminal.post('/admin/hr/leave/monetization/terminal', { preserveScroll: true, onSuccess: () => (terminalOpen.value = false) });

const days = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });

const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'name', label: 'Employee' },
    { key: 'kind_label', label: 'Kind' },
    { key: 'days', label: 'Days (VL / SL)', align: 'right' },
    { key: 'monthly_salary', label: 'Monthly salary', align: 'right' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'created_at', label: 'Filed' },
    { key: 'actions', label: '', sortable: false, export: false },
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
            <div class="flex flex-wrap gap-1" role="group" aria-label="Status">
                <button
                    v-for="tab in tabs"
                    :key="tab"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm font-medium"
                    :class="status === tab ? 'bg-primary text-white' : 'text-text hover:bg-surface-muted'"
                    :aria-pressed="status === tab"
                    @click="show(tab)"
                >
                    {{ tab.charAt(0).toUpperCase() + tab.slice(1) }} ({{ counts[tab] ?? 0 }})
                </button>
            </div>
        </template>

        <DataTable :columns="columns" :rows="requests" caption="Leave monetization requests" :export-name="`imis-monetization-${status}`" :empty-text="`No ${status} requests.`">
            <template #cell-days="{ row }"><span class="tabular-nums">{{ days(row.days) }}<span class="text-xs text-text-muted"> ({{ days(row.vl_days) }} / {{ days(row.sl_days) }})</span></span></template>
            <template #cell-monthly_salary="{ row }"><span class="tabular-nums">{{ formatPeso(row.monthly_salary) }}</span></template>
            <template #cell-amount="{ row }"><span class="font-medium tabular-nums">{{ formatPeso(row.amount) }}</span></template>
            <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span></template>
            <template #cell-actions="{ row }">
                <div v-if="canManage && !row.own" class="flex justify-end gap-3 whitespace-nowrap text-sm">
                    <template v-if="row.status === 'pending'">
                        <button type="button" class="text-success hover:underline" @click="openDecision(row, 'approved')">Approve</button>
                        <button type="button" class="text-danger hover:underline" @click="openDecision(row, 'rejected')">Reject</button>
                    </template>
                    <button v-if="row.status === 'approved'" type="button" class="text-primary hover:underline" @click="release(row)">Mark released</button>
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
                <label for="mon-decision-remarks" class="block text-sm font-medium text-text">Remarks{{ decision.decision === 'rejected' ? '' : ' (optional)' }}</label>
                <Textarea id="mon-decision-remarks" v-model="decision.remarks" class="mt-1" :required="decision.decision === 'rejected'" />
                <InputError :message="decision.errors.remarks || decision.errors.status" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="deciding = null">Cancel</Button>
            <Button type="submit" form="mon-decision-form" :variant="decision.decision === 'approved' ? 'success' : 'danger'" :loading="decision.processing">{{ decision.decision === 'approved' ? 'Approve' : 'Reject' }}</Button>
        </template>
    </Modal>

    <Modal :open="terminalOpen" title="File terminal leave" icon="plus" tone="success" @close="terminalOpen = false">
        <form id="terminal-form" class="space-y-3" @submit.prevent="submitTerminal">
            <p class="text-sm text-text">All of their remaining Vacation and Sick Leave is commuted, at their current monthly salary ÷ 22 a day.</p>
            <div>
                <label for="terminal-employee" class="block text-sm font-medium text-text">Separated employee</label>
                <Select id="terminal-employee" v-model="terminal.employee_id" class="mt-1 block w-full" :options="separated" placeholder="— Select —" required />
                <InputError :message="terminal.errors.employee_id" />
            </div>
            <div>
                <label for="terminal-remarks" class="block text-sm font-medium text-text">Remarks (optional)</label>
                <Textarea id="terminal-remarks" v-model="terminal.remarks" class="mt-1" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="terminalOpen = false">Cancel</Button>
            <Button type="submit" form="terminal-form" variant="success" :loading="terminal.processing">File</Button>
        </template>
    </Modal>
</template>
