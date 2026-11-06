<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import Select from '../../Components/Forms/Select.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * HR-8 self-service: ask for a month's payslip; the head of HRD approves
 * it, and you're told under the bell. Approved payslips open here to view
 * and print.
 */
const props = defineProps({
    months: { type: Array, required: true },
    requests: { type: Array, required: true },
    approvers: { type: Array, required: true },
});

const count = (...statuses) => props.requests.filter((row) => statuses.includes(row.status)).length;
const stats = computed(() => [
    { label: 'Months available', value: props.months.length, icon: 'calendar', tile: 'bg-info-bg text-primary' },
    { label: 'Pending approval', value: count('pending'), icon: 'clock', tile: 'bg-warning-bg text-warning' },
    { label: 'Ready to view', value: count('approved'), icon: 'check', tile: 'bg-success-bg text-success' },
    { label: 'Rejected or cancelled', value: count('rejected', 'cancelled'), icon: 'exclamation', tile: 'bg-surface-muted text-text-muted' },
]);

// Months without an open (pending or approved) request.
const requestable = computed(() => props.months.filter((month) => !month.request_status).map((month) => ({ value: month.entry_id, label: month.period })));

const form = useForm({ payroll_entry_id: '', purpose: '' });
const submit = () => form.post('/my/payslips', { preserveScroll: true, onSuccess: () => form.reset() });

const cancel = async (row) => {
    if (await confirm({ title: `Cancel ${row.reference_number}?`, tone: 'danger', confirmText: 'Cancel request' })) {
        router.post(`/my/payslips/${row.id}/cancel`, {}, { preserveScroll: true });
    }
};

const tones = { pending: 'warning', approved: 'active', rejected: 'inactive', cancelled: 'logged_out' };
const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'period', label: 'Month' },
    { key: 'purpose', label: 'Purpose', value: (row) => row.purpose ?? '' },
    { key: 'status', label: 'Status' },
    { key: 'requested_at', label: 'Requested' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="My Payslips" />

    <PageHeader
        title="My Payslips"
        description="Ask for the payslip of a month whose payroll is final. Once the head of HRD approves it, you can view and print it here."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Payslips' }]"
    />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div v-for="stat in stats" :key="stat.label" class="flex items-center gap-3.5 rounded-lg border border-border bg-surface p-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md" :class="stat.tile" aria-hidden="true"><Icon :name="stat.icon" /></span>
            <div class="min-w-0">
                <p class="text-xl leading-none font-semibold text-text tabular-nums">{{ stat.value }}</p>
                <p class="mt-1 truncate text-xs text-text-muted">{{ stat.label }}</p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <Card title="Request a payslip" icon="clipboard" class="xl:col-span-1">
            <p v-if="!months.length" class="text-sm text-text-muted">No finalized payroll includes you yet.</p>
            <p v-else-if="!requestable.length" class="text-sm text-text-muted">You have a request for every month available.</p>
            <form v-else class="space-y-3" @submit.prevent="submit">
                <div>
                    <label for="payslip-month" class="block text-sm font-medium text-text">Month</label>
                    <Select id="payslip-month" v-model="form.payroll_entry_id" class="mt-1" :options="requestable" placeholder="— Select —" required />
                    <InputError :message="form.errors.payroll_entry_id" />
                </div>
                <div>
                    <label for="payslip-purpose" class="block text-sm font-medium text-text">Purpose (optional)</label>
                    <input
                        id="payslip-purpose"
                        v-model="form.purpose"
                        type="text"
                        maxlength="255"
                        placeholder="e.g. Bank loan application"
                        class="block w-full mt-1 form-control px-3 py-2 text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    />
                    <InputError :message="form.errors.purpose" />
                </div>
                <p class="text-xs text-text-muted">
                    Goes to {{ approvers.length ? approvers.join(' or ') : 'the head of the Human Resource Division' }} for approval.
                </p>
                <Button type="submit" variant="success" :loading="form.processing">Send request</Button>
            </form>
        </Card>

        <Card title="Your requests" icon="database" class="xl:col-span-2">
            <DataTable :columns="columns" :rows="requests" caption="Your payslip requests" export-name="imis-my-payslips" :searchable="false" empty-text="No requests yet.">
                <template #cell-purpose="{ row }">{{ row.purpose || '—' }}</template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="tones[row.status]" :label="row.status_label" />
                    <div v-if="row.decided_by" class="mt-1 text-xs text-text-muted">
                        by {{ row.decided_by }}<template v-if="row.acting_for"> for {{ row.acting_for }}</template>
                    </div>
                    <div v-if="row.remarks" class="text-xs text-text-muted">{{ row.remarks }}</div>
                </template>
                <template #cell-requested_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.requested_at) }}</span></template>
                <template #cell-actions="{ row }">
                    <div class="flex justify-end gap-3 text-sm whitespace-nowrap">
                        <Button v-if="row.status === 'approved'" size="sm" :href="`/payslips/${row.entry_id}`" external>View payslip</Button>
                        <button v-if="row.status === 'pending'" type="button" class="text-danger hover:underline" @click="cancel(row)">Cancel</button>
                    </div>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
