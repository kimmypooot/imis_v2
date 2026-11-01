<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import Button from '../../Components/UI/Button.vue';
import Modal from '../../Components/Feedback/Modal.vue';
import Textarea from '../../Components/Forms/Textarea.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * HR-8: payslip requests waiting for the head of HRD (or whoever is
 * designated head of HRD). Approving lets the employee view and print
 * that month's payslip; rejecting needs a reason. Your own requests go
 * to someone else.
 */
defineProps({
    requests: { type: Array, required: true },
});

const deciding = ref(null);
const form = useForm({ decision: 'approved', remarks: '' });
const open = (row, decision) => {
    deciding.value = row;
    form.reset();
    form.clearErrors();
    form.decision = decision;
};
const submit = () => form.post(`/payslips/approvals/${deciding.value.id}`, { preserveScroll: true, onSuccess: () => (deciding.value = null) });

const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'employee', label: 'Employee' },
    { key: 'period', label: 'Month' },
    { key: 'purpose', label: 'Purpose', value: (row) => row.purpose ?? '' },
    { key: 'requested_at', label: 'Requested' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="Payslip Approvals" />

    <PageHeader
        title="Payslip Approvals"
        description="Requests from staff for their payslips. Approving lets them view and print that month's payslip."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Payslip Approvals' }]"
    />

    <Card title="Waiting for you" icon="checkCircle">
        <DataTable :columns="columns" :rows="requests" caption="Payslip requests waiting for approval" export-name="imis-payslip-approvals" empty-text="Nothing waiting.">
            <template #cell-employee="{ row }">
                <span class="font-medium text-text">{{ row.employee }}</span>
                <div class="text-xs text-text-muted">{{ row.position }}<template v-if="row.office"> · {{ row.office }}</template></div>
            </template>
            <template #cell-purpose="{ row }">{{ row.purpose || '—' }}</template>
            <template #cell-requested_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.requested_at) }}</span></template>
            <template #cell-actions="{ row }">
                <div class="flex justify-end gap-3 text-sm whitespace-nowrap">
                    <button type="button" class="text-success hover:underline" @click="open(row, 'approved')">Approve<span class="sr-only"> {{ row.reference_number }}</span></button>
                    <button type="button" class="text-danger hover:underline" @click="open(row, 'rejected')">Reject<span class="sr-only"> {{ row.reference_number }}</span></button>
                </div>
            </template>
        </DataTable>
    </Card>

    <Modal :open="deciding !== null" :title="form.decision === 'approved' ? 'Approve request' : 'Reject request'" :tone="form.decision === 'approved' ? 'success' : 'danger'" @close="deciding = null">
        <form id="payslip-decision-form" class="space-y-3" @submit.prevent="submit">
            <p v-if="deciding" class="text-sm text-text">
                {{ deciding.reference_number }} — {{ deciding.employee }}, payslip for {{ deciding.period }}.
                <template v-if="form.decision === 'approved'">They can then view and print it.</template>
            </p>
            <div>
                <label for="payslip-decision-remarks" class="block text-sm font-medium text-text">Remarks{{ form.decision === 'rejected' ? '' : ' (optional)' }}</label>
                <Textarea id="payslip-decision-remarks" v-model="form.remarks" class="mt-1" :required="form.decision === 'rejected'" />
                <InputError :message="form.errors.remarks || form.errors.request" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="deciding = null">Cancel</Button>
            <Button type="submit" form="payslip-decision-form" :variant="form.decision === 'approved' ? 'success' : 'danger'" :loading="form.processing">
                {{ form.decision === 'approved' ? 'Approve' : 'Reject' }}
            </Button>
        </template>
    </Modal>
</template>
