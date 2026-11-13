<script setup>
import InputLabel from '../../Components/Forms/InputLabel.vue';
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import UserAvatar from '../../Components/UI/UserAvatar.vue';
import Icon from '../../Components/UI/Icon.vue';
import QueueSummary from '../../Components/Portal/QueueSummary.vue';
import Button from '../../Components/UI/Button.vue';
import Modal from '../../Components/Feedback/Modal.vue';
import Textarea from '../../Components/Forms/Textarea.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { daysSince, formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * HR-8: payslip requests waiting for the head of HRD (or whoever is
 * designated head of HRD). Approving lets the employee view and print
 * that month's payslip; rejecting needs a reason. Your own requests go
 * to someone else.
 */
const props = defineProps({
    requests: { type: Array, required: true },
});

const waitingSince = () => props.requests.map((row) => row.requested_at);

// How long something has waited: calm at first, then amber, then red.
const ageClass = (iso) => (daysSince(iso) >= 7 ? 'bg-danger-bg text-danger-fg' : daysSince(iso) >= 3 ? 'bg-warning-bg text-warning-fg' : 'bg-surface-muted text-text-muted');
const ageLabel = (iso) => (daysSince(iso) === 0 ? 'today' : `${daysSince(iso)} d`);

// Oldest first, with a filter to focus on the ones that have waited.
const filters = [
    { value: 'all', label: 'All' },
    { value: 'late', label: 'Waiting 3+ days' },
    { value: 'new', label: 'New' },
];
const filter = ref('all');
const search = ref('');
const sorted = computed(() => [...props.requests].sort((a, b) => new Date(a.requested_at) - new Date(b.requested_at)));
const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    return sorted.value.filter((row) => {
        if (filter.value === 'late' && daysSince(row.requested_at) < 3) return false;
        if (filter.value === 'new' && daysSince(row.requested_at) >= 3) return false;
        return !term || [row.employee, row.reference_number, row.period, row.purpose, row.office].some((value) => (value ?? '').toLowerCase().includes(term));
    });
});
const filterCount = (value) => (value === 'all' ? props.requests.length : props.requests.filter((row) => (value === 'late') === (daysSince(row.requested_at) >= 3)).length);

const deciding = ref(null);
const form = useForm({ decision: 'approved', remarks: '' });
const open = (row, decision) => {
    deciding.value = row;
    form.reset();
    form.clearErrors();
    form.decision = decision;
};
const submit = () => form.post(`/payslips/approvals/${deciding.value.id}`, { preserveScroll: true, onSuccess: () => (deciding.value = null) });
</script>

<template>
    <Head title="Payslip Approvals" />

    <PageHeader
        title="Payslip Approvals"
        description="Requests from staff for their payslips. Approving lets them view and print that month's payslip."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Payslip Approvals' }]"
    />

    <QueueSummary :since="waitingSince()" noun="payslip request" :late-after="2" class="mb-6" />

    <Card :title="`Waiting for you (${requests.length})`" icon="checkCircle">
        <EmptyState v-if="!requests.length" icon="check" title="Nothing waiting" description="New payslip requests from staff will appear here, oldest first." />
        <template v-else>
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <div class="flex flex-wrap gap-2" role="group" aria-label="Filter requests">
                    <button
                        v-for="item in filters"
                        :key="item.value"
                        type="button"
                        class="rounded-full border px-3 py-1 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        :class="filter === item.value ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-muted hover:bg-surface-muted'"
                        :aria-pressed="filter === item.value"
                        @click="filter = item.value"
                    >
                        {{ item.label }} <span class="tabular-nums opacity-80">{{ filterCount(item.value) }}</span>
                    </button>
                </div>
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search name, month or reference"
                    aria-label="Search requests"
                    class="form-control ml-auto w-full px-3 py-1.5 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring sm:w-72"
                />
            </div>

            <p v-if="!visible.length" class="py-8 text-center text-sm text-text-muted">No requests match.</p>
            <ul v-else class="divide-y divide-border">
                <li v-for="row in visible" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-3 py-4 first:pt-0 last:pb-0">
                    <UserAvatar :name="row.employee" size="lg" />
                    <div class="min-w-0 flex-1 basis-64">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-text">{{ row.employee }}</span>
                            <span class="rounded-full bg-info-bg px-2 py-0.5 text-xs font-medium text-link">{{ row.period }}</span>
                        </p>
                        <p class="text-xs text-text-muted">{{ row.position }}<template v-if="row.office"> · {{ row.office }}</template></p>
                        <p v-if="row.purpose" class="mt-1.5 flex items-start gap-1.5 text-sm text-text">
                            <Icon name="clipboard" size="sm" class="mt-0.5 shrink-0 text-text-muted" />
                            <span>{{ row.purpose }}</span>
                        </p>
                        <p class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-text-muted">
                            <span>{{ row.reference_number }}</span>
                            <span aria-hidden="true">·</span>
                            <span class="whitespace-nowrap">Requested {{ formatDateTime(row.requested_at) }}</span>
                            <span class="rounded-full px-2 py-0.5 font-medium" :class="ageClass(row.requested_at)">{{ ageLabel(row.requested_at) }}</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <Button size="sm" variant="outline" @click="open(row, 'rejected')">Reject<span class="sr-only"> {{ row.reference_number }}</span></Button>
                        <Button size="sm" variant="success" @click="open(row, 'approved')">Approve<span class="sr-only"> {{ row.reference_number }}</span></Button>
                    </div>
                </li>
            </ul>
        </template>
    </Card>

    <Modal :open="deciding !== null" :title="form.decision === 'approved' ? 'Approve request' : 'Reject request'" :tone="form.decision === 'approved' ? 'success' : 'danger'" @close="deciding = null">
        <form id="payslip-decision-form" class="space-y-3" @submit.prevent="submit">
            <p v-if="deciding" class="text-sm text-text">
                {{ deciding.reference_number }} — {{ deciding.employee }}, payslip for {{ deciding.period }}.
                <template v-if="form.decision === 'approved'">They can then view and print it.</template>
            </p>
            <div>
                <InputLabel for="payslip-decision-remarks">Remarks{{ form.decision === 'rejected' ? '' : ' (optional)' }}</InputLabel>
                <Textarea id="payslip-decision-remarks" v-model="form.remarks" class="mt-1" :required="form.decision === 'rejected'" />
                <InputError :message="form.errors.remarks || form.errors.request" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="deciding = null" icon="close">Cancel</Button>
            <Button type="submit" form="payslip-decision-form" :variant="form.decision === 'approved' ? 'success' : 'danger'" :loading="form.processing">
                {{ form.decision === 'approved' ? 'Approve' : 'Reject' }}
            </Button>
        </template>
    </Modal>
</template>
