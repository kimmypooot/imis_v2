<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Button from '../../Components/UI/Button.vue';
import InputLabel from '../../Components/Forms/InputLabel.vue';
import InputError from '../../Components/Forms/InputError.vue';
import Select from '../../Components/Forms/Select.vue';
import Textarea from '../../Components/Forms/Textarea.vue';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * Ask HR to correct a field of your own employee record: pick the field,
 * enter the corrected value and say why. HR approves (the record is
 * updated) or rejects it with a reason, and you are told under the bell.
 */
const props = defineProps({
    fields: { type: Array, required: true },
    current: { type: Object, required: true },
    requests: { type: Array, required: true },
});

const form = useForm({ field: '', proposed_value: '', reason: '' });

const selected = computed(() => props.fields.find((field) => field.value === form.field) ?? null);
const currentValue = computed(() => (form.field ? props.current[form.field] || '' : ''));
const pendingFields = computed(() => props.requests.filter((row) => row.status === 'pending').map((row) => row.field));
const fieldOptions = computed(() => props.fields.map((field) => ({ value: field.value, label: pendingFields.value.includes(field.value) ? `${field.label} (request pending)` : field.label })));

const submit = () => form.post('/my/corrections', { preserveScroll: true, onSuccess: () => form.reset() });

const cancel = async (row) => {
    if (await confirm({ title: `Cancel ${row.reference_number}?`, tone: 'danger', confirmText: 'Cancel request' })) {
        router.post(`/my/corrections/${row.id}/cancel`, {}, { preserveScroll: true });
    }
};

const tones = { pending: 'warning', approved: 'active', rejected: 'inactive', cancelled: 'logged_out' };
const columns = [
    { key: 'reference_number', label: 'Reference' },
    { key: 'field_label', label: 'Field' },
    { key: 'proposed_value', label: 'Change', sortable: false, value: (row) => `${row.current_value ?? ''} ${row.proposed_value}` },
    { key: 'status', label: 'Status' },
    { key: 'requested_at', label: 'Requested' },
    { key: 'actions', label: '', sortable: false, export: false },
];
</script>

<template>
    <Head title="My Corrections" />

    <PageHeader
        title="My Corrections"
        description="Something wrong on your employee record? Ask HR to correct it. Where you work and your position change through reassignments and appointments, not here."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Employee Record', href: '/my/employee-record' }, { label: 'My Corrections' }]"
    />

    <div class="grid gap-6 xl:grid-cols-3">
        <Card title="Ask for a correction" icon="clipboard" class="xl:col-span-1">
            <form class="space-y-3" @submit.prevent="submit">
                <div>
                    <InputLabel for="correction-field">What needs correcting?</InputLabel>
                    <Select id="correction-field" v-model="form.field" class="mt-1" :options="fieldOptions" placeholder="— Select —" required />
                    <InputError :message="form.errors.field" />
                </div>

                <template v-if="selected">
                    <p class="text-sm text-text-muted">
                        Your record says: <strong class="text-text">{{ currentValue || 'nothing' }}</strong>
                    </p>
                    <div>
                        <InputLabel for="correction-value">Corrected value</InputLabel>
                        <Select
                            v-if="selected.type === 'sex'"
                            id="correction-value"
                            v-model="form.proposed_value"
                            class="mt-1"
                            :options="[{ value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }]"
                            placeholder="— Select —"
                        />
                        <input
                            v-else
                            id="correction-value"
                            v-model="form.proposed_value"
                            :type="selected.type === 'date' ? 'date' : selected.type === 'email' ? 'email' : 'text'"
                            maxlength="255"
                            class="mt-1 block w-full form-control px-3 py-2 text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                        />
                        <InputError :message="form.errors.proposed_value" />
                    </div>
                    <div>
                        <InputLabel for="correction-reason">Why is it wrong?</InputLabel>
                        <Textarea id="correction-reason" v-model="form.reason" class="mt-1" :rows="3" placeholder="e.g. My surname was misspelled when the record was set up." />
                        <InputError :message="form.errors.reason" />
                    </div>
                    <Button type="submit" variant="success" :loading="form.processing">Send to HR</Button>
                </template>
            </form>
        </Card>

        <Card title="Your requests" icon="database" class="xl:col-span-2">
            <DataTable :columns="columns" :rows="requests" caption="Your correction requests" export-name="imis-my-corrections" :searchable="false" empty-text="No requests yet.">
                <template #cell-proposed_value="{ row }">
                    <span class="text-text-muted line-through">{{ row.current_value || '—' }}</span>
                    <span class="mx-1 text-text-muted">→</span>
                    <span class="font-medium text-text">{{ row.proposed_value || '—' }}</span>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="tones[row.status]" :label="row.status_label" />
                    <div v-if="row.decided_by" class="mt-1 text-xs text-text-muted">by {{ row.decided_by }}</div>
                    <div v-if="row.remarks" class="text-xs text-text-muted">{{ row.remarks }}</div>
                </template>
                <template #cell-requested_at="{ row }"><span class="whitespace-nowrap">{{ formatDateTime(row.requested_at) }}</span></template>
                <template #cell-actions="{ row }">
                    <button v-if="row.status === 'pending'" type="button" class="text-sm text-danger-fg hover:underline" @click="cancel(row)">Cancel</button>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
