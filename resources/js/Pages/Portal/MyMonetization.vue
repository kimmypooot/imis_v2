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
import Textarea from '../../Components/Forms/Textarea.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';
import { formatPeso } from '../../Utils/money';

defineOptions({ layout: PortalLayout });

/**
 * HR-6c self-service: monetizing Vacation Leave (legacy monetization.php).
 * The rules come from the server; the estimate here is for your eyes —
 * HR's approval sets the final figure.
 */
const props = defineProps({
    terms: { type: Object, required: true },
    rules: { type: Object, required: true },
    requests: { type: Array, required: true },
});

const form = useForm({ days: '', remarks: '' });
const estimate = computed(() => (props.terms.daily_rate && Number(form.days) > 0 ? props.terms.daily_rate * Number(form.days) : null));
const submit = () => form.post('/my/leave/monetization', { preserveScroll: true, onSuccess: () => form.reset() });

const cancel = async (row) => {
    if (await confirm({ title: `Cancel ${row.reference_number}?`, tone: 'danger', confirmText: 'Cancel request' })) {
        router.post(`/my/leave/monetization/${row.id}/cancel`, {}, { preserveScroll: true });
    }
};

const days = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
const summary = computed(() => [
    { label: 'Vacation Leave', value: `${days(props.terms.vl)} days`, icon: 'calendar', tile: 'bg-info-bg text-primary' },
    { label: 'Sick Leave', value: `${days(props.terms.sl)} days`, icon: 'calendar', tile: 'bg-info-bg text-primary' },
    { label: 'You can monetize up to', value: `${days(props.terms.max_days)} days`, icon: 'check', tile: props.terms.max_days > 0 ? 'bg-success-bg text-success' : 'bg-surface-muted text-text-muted' },
    { label: 'Worth about', value: props.terms.daily_rate ? formatPeso(props.terms.daily_rate * props.terms.max_days) : '—', icon: 'database', tile: 'bg-warning-bg text-warning' },
]);
const tones = { pending: 'warning', approved: 'active', released: 'info', rejected: 'inactive', cancelled: 'logged_out' };

const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'kind_label', label: 'Kind' },
    { key: 'days', label: 'Days', align: 'right' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Filed' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="Leave Monetization" />

    <PageHeader
        title="Leave Monetization"
        :description="`Convert up to ${rules.share}% of your Vacation Leave to cash, once a year, keeping at least ${rules.keep} days of VL and SL combined.`"
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Leave', href: '/my/leave' }, { label: 'Leave Monetization' }]"
    />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div v-for="item in summary" :key="item.label" class="flex items-center gap-3.5 rounded-lg border border-border bg-surface p-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md" :class="item.tile" aria-hidden="true"><Icon :name="item.icon" /></span>
            <div class="min-w-0">
                <p class="truncate text-lg leading-none font-semibold text-text tabular-nums">{{ item.value }}</p>
                <p class="mt-1 truncate text-xs text-text-muted">{{ item.label }}</p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <Card title="Request" icon="database" class="xl:col-span-1">
            <dl class="mb-4 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-text-muted">Vacation Leave</dt><dd class="tabular-nums text-text">{{ days(terms.vl) }}</dd></div>
                <div class="flex justify-between"><dt class="text-text-muted">Sick Leave</dt><dd class="tabular-nums text-text">{{ days(terms.sl) }}</dd></div>
                <div class="flex justify-between border-t border-border pt-2"><dt class="font-medium text-text">You can monetize up to</dt><dd class="font-semibold tabular-nums text-text">{{ days(terms.max_days) }} days</dd></div>
                <div class="flex justify-between"><dt class="text-text-muted">Daily rate (monthly salary ÷ {{ rules.divisor }})</dt><dd class="tabular-nums text-text">{{ formatPeso(terms.daily_rate) }}</dd></div>
            </dl>

            <ul v-if="terms.issues.length" class="mb-4 space-y-1 rounded-md border border-warning-border bg-warning-bg px-3 py-2 text-sm text-text">
                <li v-for="issue in terms.issues" :key="issue">{{ issue }}</li>
            </ul>

            <form v-else class="space-y-3" @submit.prevent="submit">
                <div>
                    <label for="mon-days" class="block text-sm font-medium text-text">Days to monetize</label>
                    <input
                        id="mon-days"
                        v-model="form.days"
                        type="number"
                        step="0.001"
                        min="0.001"
                        :max="terms.max_days"
                        required
                        class="block w-full mt-1 form-control px-3 py-2 text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    />
                    <p v-if="estimate" class="mt-1 text-sm text-text">About <strong>{{ formatPeso(estimate) }}</strong> (HR confirms).</p>
                    <InputError :message="form.errors.days" />
                </div>
                <div>
                    <label for="mon-remarks" class="block text-sm font-medium text-text">Remarks (optional)</label>
                    <Textarea id="mon-remarks" v-model="form.remarks" class="mt-1" />
                </div>
                <Button type="submit" variant="success" :loading="form.processing">File request</Button>
            </form>
        </Card>

        <Card title="Your requests" icon="clipboard" class="xl:col-span-2">
            <DataTable :columns="columns" :rows="requests" caption="Your monetization requests" export-name="imis-my-monetization" :searchable="false" empty-text="No requests yet.">
                <template #cell-days="{ row }"><span class="tabular-nums">{{ days(row.days) }}</span></template>
                <template #cell-amount="{ row }"><span class="tabular-nums">{{ formatPeso(row.amount) }}</span></template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="tones[row.status]" :label="row.status" />
                    <div v-if="row.decision_remarks" class="mt-1 text-xs text-text-muted">{{ row.decision_remarks }}</div>
                </template>
                <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span></template>
                <template #cell-actions="{ row }">
                    <button v-if="row.status === 'pending' && row.kind === 'partial'" type="button" class="text-sm text-danger hover:underline" @click="cancel(row)">Cancel</button>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
