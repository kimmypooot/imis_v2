<script setup>
import InputLabel from '../../Components/Forms/InputLabel.vue';
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
    { label: 'Months available', value: props.months.length, icon: 'calendar', tile: 'bg-info-bg text-link' },
    { label: 'Pending approval', value: count('pending'), icon: 'clock', tile: 'bg-warning-bg text-warning-fg' },
    { label: 'Ready to view', value: count('approved'), icon: 'check', tile: 'bg-success-bg text-success-fg' },
    { label: 'Rejected or cancelled', value: count('rejected', 'cancelled'), icon: 'exclamation', tile: 'bg-surface-muted text-text-muted' },
]);

// Months without an open (pending or approved) request.
const requestable = computed(() => props.months.filter((month) => !month.request_status).map((month) => ({ value: month.entry_id, label: month.period })));

// The newest approved request: the one most likely wanted right now.
const latestReady = computed(() => props.requests.find((row) => row.status === 'approved') ?? null);

// Per-month tiles: what each month's payslip needs next.
const monthTiles = computed(() =>
    props.months.map((month) => {
        const request = props.requests.find((row) => row.entry_id === month.entry_id && ['pending', 'approved'].includes(row.status));
        return { ...month, request };
    }),
);
const monthState = {
    approved: { label: 'Ready to view', classes: 'border-success-border bg-success-bg text-success-fg', icon: 'check' },
    pending: { label: 'Awaiting approval', classes: 'border-warning-border bg-warning-bg text-warning-fg', icon: 'clock' },
};
const pickMonth = (month) => {
    form.payroll_entry_id = month.entry_id;
    document.getElementById('payslip-month')?.focus();
};

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

    <div v-if="latestReady" class="mb-6 flex flex-wrap items-center gap-4 rounded-lg border border-success-border bg-success-bg p-5">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-surface text-success-fg" aria-hidden="true"><Icon name="check" size="lg" /></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-text">Your {{ latestReady.period }} payslip is ready</p>
            <p class="text-sm text-text-muted">Approved<template v-if="latestReady.decided_by"> by {{ latestReady.decided_by }}</template>. Open it to view, print or save as PDF.</p>
        </div>
        <Button variant="success" :href="`/payslips/${latestReady.entry_id}`" external>View payslip</Button>
    </div>

    <Card v-if="monthTiles.length" title="Your months" icon="calendar" class="mb-6">
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <li v-for="month in monthTiles" :key="month.entry_id">
                <div v-if="monthState[month.request_status]" class="flex h-full items-center gap-3 rounded-lg border p-3" :class="monthState[month.request_status].classes">
                    <Icon :name="monthState[month.request_status].icon" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-text">{{ month.period }}</p>
                        <p class="text-xs">{{ monthState[month.request_status].label }}</p>
                    </div>
                    <Button v-if="month.request_status === 'approved'" size="sm" variant="success" :href="`/payslips/${month.entry_id}`" external>View</Button>
                </div>
                <button
                    v-else
                    type="button"
                    class="flex h-full w-full items-center gap-3 rounded-lg border border-dashed border-border bg-surface p-3 text-left transition-colors hover:border-primary hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    @click="pickMonth(month)"
                >
                    <Icon name="calendar" class="text-text-muted" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-text">{{ month.period }}</span>
                        <span class="block text-xs text-text-muted">Not requested yet</span>
                    </span>
                    <span class="text-xs font-medium text-link">Request</span>
                </button>
            </li>
        </ul>
    </Card>

    <div class="grid gap-6 xl:grid-cols-3">
        <Card title="Request a payslip" icon="clipboard" class="xl:col-span-1">
            <p v-if="!months.length" class="text-sm text-text-muted">No finalized payroll includes you yet.</p>
            <p v-else-if="!requestable.length" class="text-sm text-text-muted">You have a request for every month available.</p>
            <form v-else class="space-y-3" @submit.prevent="submit">
                <div>
                    <InputLabel for="payslip-month">Month</InputLabel>
                    <Select id="payslip-month" v-model="form.payroll_entry_id" class="mt-1" :options="requestable" placeholder="— Select —" required />
                    <InputError :message="form.errors.payroll_entry_id" />
                </div>
                <div>
                    <InputLabel for="payslip-purpose">Purpose (optional)</InputLabel>
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
                        <button v-if="row.status === 'pending'" type="button" class="text-danger-fg hover:underline" @click="cancel(row)">Cancel</button>
                    </div>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
