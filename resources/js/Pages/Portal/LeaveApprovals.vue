<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import FormGroup from '@cscro8/ui/Components/Forms/FormGroup.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import QueueSummary from '../../Components/Portal/QueueSummary.vue';
import { daysSince, formatCalendarDate, formatDateTime } from '../../Utils/datetime';
import { leaveDates } from '../../Components/HR/leaveStatus';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b: Leave Approvals — applications waiting for you (as their
 * supervisor, as HR, or as the Head or an officer-in-charge), oldest
 * first, and the ones you acted on lately.
 */
const props = defineProps({
    queue: { type: Array, required: true },
    recent: { type: Array, required: true },
    delegations: { type: Array, required: true },
    delegatedToMe: { type: Array, required: true },
    userOptions: { type: Array, required: true },
});

const form = useForm({ delegate_id: '', date_from: '', date_to: '', reason: '' });

const delegate = () => form.post('/leave/delegations', { preserveScroll: true, onSuccess: () => form.reset() });

const endDelegation = async (delegation) => {
    const confirmed = await confirm({
        title: `End delegation to ${delegation.delegate}?`,
        text: 'They can no longer act on your approvals.',
        tone: 'danger',
        confirmText: 'End delegation',
    });

    if (confirmed) router.delete(`/leave/delegations/${delegation.id}`, { preserveScroll: true });
};

const waitingSince = () => props.queue.map((row) => row.waiting_since);

// How long something has waited: calm at first, then amber, then red.
const ageClass = (iso) => (daysSince(iso) >= 7 ? "bg-danger-bg text-danger-fg" : daysSince(iso) >= 3 ? "bg-warning-bg text-warning-fg" : "bg-surface-muted text-text-muted");
const ageLabel = (iso) => (daysSince(iso) === 0 ? "today" : `${daysSince(iso)} d`);

const queueColumns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'name', label: 'Employee' },
    { key: 'type_name', label: 'Type of leave' },
    { key: 'date_from', label: 'Dates', value: (row) => leaveDates(row, formatCalendarDate) },
    { key: 'step_label', label: 'Your step' },
    { key: 'waiting_since', label: 'Waiting since' },
];

const recentColumns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'name', label: 'Employee' },
    { key: 'type_name', label: 'Type of leave' },
    { key: 'my_action', label: 'Your action' },
    { key: 'status_label', label: 'Now' },
    { key: 'acted_at', label: 'When' },
];
</script>

<template>
    <Head title="Leave Approvals" />

    <PageHeader title="Leave Approvals" description="Applications waiting for your recommendation, certification or approval." :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Leave Approvals' }]" />

    <div class="space-y-6">
        <Alert v-for="item in delegatedToMe" :key="item.delegator" tone="info">
            You can act on {{ item.delegator }}'s supervisor approvals until {{ formatCalendarDate(item.date_to) }}.
        </Alert>

        <QueueSummary :since="waitingSince()" noun="leave application" />

        <Card :title="`Waiting for you (${queue.length})`" icon="checkCircle">
            <DataTable :columns="queueColumns" :rows="queue" caption="Leave applications waiting for you" export-name="imis-leave-queue" empty-text="Nothing is waiting for you.">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-link hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-name="{ row }">
                    {{ row.name }}
                    <div v-if="row.office" class="text-xs text-text-muted">{{ row.office }}</div>
                </template>
                <template #cell-date_from="{ row }">{{ leaveDates(row, formatCalendarDate) }}</template>
                <template #cell-waiting_since="{ row }">
                    <span class="whitespace-nowrap">{{ formatDateTime(row.waiting_since) }}</span>
                    <span class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium" :class="ageClass(row.waiting_since)">{{ ageLabel(row.waiting_since) }}</span>
                    <span v-if="row.deemed_approved" class="ml-2 rounded-full bg-danger-bg px-2 py-0.5 text-xs font-medium text-danger-fg" title="Sec. 49, Rule XVI: not acted on within 5 working days">Deemed approved: act now</span>
                    <span v-else-if="row.waiting_working_days === 4" class="ml-2 rounded-full bg-warning-bg px-2 py-0.5 text-xs font-medium text-warning-fg">Due today</span>
                </template>
            </DataTable>
        </Card>

        <Card title="Going out of office?" icon="calendar">
            <p class="mb-3 text-sm text-text-muted">
                Hand the applications waiting for you as a supervisor to a colleague for a period. They see them here and can act on them; the form shows they acted for you. HR and Head steps are not included.
            </p>

            <ul v-if="delegations.length" class="mb-4 divide-y divide-border rounded-md border border-border">
                <li v-for="delegation in delegations" :key="delegation.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                    <span>
                        <span class="font-medium text-text">{{ delegation.delegate }}</span>
                        · {{ formatCalendarDate(delegation.date_from) }} – {{ formatCalendarDate(delegation.date_to) }}
                        <span v-if="delegation.active" class="ml-1 rounded-full bg-success-bg px-2 py-0.5 text-xs font-medium text-success-fg">In effect</span>
                        <span v-if="delegation.reason" class="text-text-muted"> · {{ delegation.reason }}</span>
                    </span>
                    <Button variant="secondary" size="sm" @click="endDelegation(delegation)">End</Button>
                </li>
            </ul>

            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="delegate">
                <FormGroup id="delegate-person" label="Colleague" required :error="form.errors.delegate_id">
                    <Select id="delegate-person" v-model="form.delegate_id" :options="[{ value: '', label: 'Choose…' }, ...userOptions]" />
                </FormGroup>
                <TextInput id="delegate-from" v-model="form.date_from" type="date" label="From" required :error="form.errors.date_from" />
                <TextInput id="delegate-to" v-model="form.date_to" type="date" label="Until" required :error="form.errors.date_to" />
                <TextInput id="delegate-reason" v-model="form.reason" label="Reason" :error="form.errors.reason" />
                <div class="sm:col-span-2 lg:col-span-4">
                    <Button type="submit" :disabled="form.processing">Delegate my approvals</Button>
                </div>
            </form>
        </Card>

        <Card v-if="recent.length" title="Recently acted on" icon="activity">
            <DataTable :columns="recentColumns" :rows="recent" caption="Leave applications you acted on" export-name="imis-leave-acted" :searchable="false">
                <template #cell-reference_number="{ row }">
                    <Link :href="`/leave/applications/${row.id}`" class="font-medium text-link hover:underline">{{ row.reference_number }}</Link>
                </template>
                <template #cell-acted_at="{ row }"><span class="whitespace-nowrap">{{ row.acted_at ? formatDateTime(row.acted_at) : '—' }}</span></template>
            </DataTable>
        </Card>
    </div>
</template>
