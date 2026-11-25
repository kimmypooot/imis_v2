<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import Tabs from '@cscro8/ui/Components/UI/Tabs.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Textarea from '@cscro8/ui/Components/Forms/Textarea.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Staff requests to correct their own employee record. Approving applies
 * the change to the record; rejecting needs a reason. Your own request is
 * decided by someone else.
 */
const props = defineProps({
    requests: { type: Array, required: true },
});

const tab = ref('pending');
const pending = computed(() => props.requests.filter((row) => row.status === 'pending'));
const decided = computed(() => props.requests.filter((row) => row.status !== 'pending'));
const tabs = computed(() => [
    { key: 'pending', label: `Pending (${pending.value.length})`, icon: 'clock' },
    { key: 'decided', label: `Decided (${decided.value.length})`, icon: 'check' },
]);
const rows = computed(() => (tab.value === 'pending' ? pending.value : decided.value));

const tones = { pending: 'warning', approved: 'active', rejected: 'inactive', cancelled: 'logged_out' };
// Who and when sit under the reference, the field above its change; hidden columns keep them in the CSV.
const columns = [
    { key: 'reference_number', label: 'Request' },
    { key: 'change', label: 'Change', sortable: false, value: (row) => `${row.field_label} ${row.current_value ?? ''} ${row.proposed_value}` },
    { key: 'reason', label: 'Reason' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center' },
    { key: 'employee', label: 'Employee', hidden: true },
    { key: 'field_label', label: 'Field', hidden: true },
    { key: 'requested_at', label: 'Requested', hidden: true },
];

const deciding = ref(null);
const form = useForm({ decision: 'approved', remarks: '' });
const open = (row, decision) => {
    deciding.value = row;
    form.reset();
    form.clearErrors();
    form.decision = decision;
};
const submit = () => form.post(`/admin/hr/corrections/${deciding.value.id}`, { preserveScroll: true, onSuccess: () => (deciding.value = null) });
</script>

<template>
    <Head title="Record Corrections" />

    <PageHeader
        title="Record Corrections"
        description="Staff requests to correct their own employee record. Approving updates the record; rejecting needs a reason."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Human Resources', href: '/admin/hr' }, { label: 'Record Corrections' }]"
    />

    <Card title="Correction requests" icon="clipboard">
        <Tabs v-model="tab" :tabs="tabs">
            <template v-for="item in tabs" :key="item.key" #[item.key]>
                <DataTable
                    v-if="item.key === tab"
                    :columns="columns"
                    :rows="rows"
                    :caption="`${item.key === 'pending' ? 'Pending' : 'Decided'} correction requests`"
                    :export-name="`imis-corrections-${item.key}`"
                    :numbered="false"
                    :empty-text="item.key === 'pending' ? 'Nothing waiting.' : 'No decided requests yet.'"
                >
                    <template #cell-reference_number="{ row }">
                        <span class="font-medium text-text">{{ row.reference_number }}</span>
                        <div class="text-text">{{ row.employee }}</div>
                        <div class="text-xs whitespace-nowrap text-text-muted">{{ formatDateTime(row.requested_at) }}</div>
                    </template>
                    <template #cell-change="{ row }">
                        <div class="text-2xs text-text-muted">{{ row.field_label }}:</div>
                        <span class="text-text-muted line-through">{{ row.current_value || '—' }}</span>
                        <span class="mx-1 text-text-muted">→</span>
                        <span class="font-medium text-text">{{ row.proposed_value || '—' }}</span>
                    </template>
                    <template #cell-reason="{ row }"><p class="line-clamp-2 max-w-xs text-text-muted">{{ row.reason }}</p></template>
                    <template #cell-status="{ row }">
                        <StatusBadge :status="tones[row.status]" :label="row.status_label" />
                        <div v-if="row.decided_by" class="mt-1 text-xs text-text-muted">by {{ row.decided_by }}</div>
                        <div v-if="row.remarks" class="text-xs text-text-muted">{{ row.remarks }}</div>
                    </template>
                    <template #cell-actions="{ row }">
                        <div v-if="row.can_decide" class="flex justify-center gap-2">
                            <Button size="sm" variant="success" icon="check" @click="open(row, 'approved')">Approve</Button>
                            <Button size="sm" variant="outline" @click="open(row, 'rejected')">Reject</Button>
                        </div>
                        <span v-else-if="row.status === 'pending'" class="text-xs text-text-muted">Not yours to decide</span>
                    </template>
                </DataTable>
            </template>
        </Tabs>
    </Card>

    <Modal
        :open="deciding !== null"
        :title="form.decision === 'approved' ? 'Approve correction' : 'Reject correction'"
        :tone="form.decision === 'approved' ? 'success' : 'danger'"
        size="sm"
        @close="deciding = null"
    >
        <form v-if="deciding" id="correction-decision" class="space-y-3" @submit.prevent="submit">
            <p class="text-sm text-text-muted">
                {{ deciding.employee }}: {{ deciding.field_label }}
                <strong class="text-text">{{ deciding.current_value || '—' }}</strong> →
                <strong class="text-text">{{ deciding.proposed_value || '—' }}</strong>
            </p>
            <p class="text-sm text-text-muted">Reason: {{ deciding.reason }}</p>
            <p v-if="form.decision === 'approved'" class="text-xs text-text-muted">Approving updates the employee's record right away.</p>
            <div>
                <Textarea
                    v-model="form.remarks"
                    :rows="3"
                    :placeholder="form.decision === 'rejected' ? 'Why is it rejected? (required)' : 'Remarks (optional)'"
                    :required="form.decision === 'rejected'"
                />
                <InputError :message="form.errors.remarks || form.errors.request" />
            </div>
        </form>
        <template #footer>
            <Button variant="outline" @click="deciding = null">Cancel</Button>
            <Button type="submit" form="correction-decision" :variant="form.decision === 'approved' ? 'success' : 'danger'" :loading="form.processing">
                {{ form.decision === 'approved' ? 'Approve' : 'Reject' }}
            </Button>
        </template>
    </Modal>
</template>
