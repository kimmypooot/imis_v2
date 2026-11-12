<script setup>
import InputLabel from '../../../../Components/Forms/InputLabel.vue';
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import Button from '../../../../Components/UI/Button.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import TextInput from '../../../../Components/Forms/TextInput.vue';
import Textarea from '../../../../Components/Forms/Textarea.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-6c: flexible work for HR (legacy hrd/fwa.php) — requests to decide,
 * arrangements in effect, and weekly reports to acknowledge.
 */
defineProps({
    pending: { type: Array, required: true },
    active: { type: Array, required: true },
    reports: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const period = (row) => `${formatCalendarDate(row.effective_from)} – ${row.effective_to ? formatCalendarDate(row.effective_to) : 'open-ended'}`;
const schedule = (row) => (row.schedule ? Object.entries(row.schedule).map(([day, mode]) => `${day} ${mode}`).join(' · ') : '');

// One modal: decide, end early, or acknowledge.
const mode = ref(null);
const target = ref(null);
const form = useForm({ decision: 'approved', remarks: '', effective_to: '' });
const open = (next, row, decision = 'approved') => {
    form.reset();
    form.clearErrors();
    form.decision = decision;
    target.value = row;
    mode.value = next;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (mode.value = null) };
    if (mode.value === 'decide') form.post(`/admin/hr/leave/flexible-work/${target.value.id}/decide`, options);
    if (mode.value === 'terminate') form.post(`/admin/hr/leave/flexible-work/${target.value.id}/terminate`, options);
    if (mode.value === 'acknowledge') form.post(`/admin/hr/leave/flexible-work/reports/${target.value.id}/acknowledge`, options);
};
const titles = { decide: 'Decide the request', terminate: 'End the arrangement early', acknowledge: 'Acknowledge the report' };
</script>

<template>
    <Head title="Flexible Work" />

    <PageHeader title="Flexible Work" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Flexible Work' }]" />

    <div class="space-y-6">
        <Card :title="`Requests (${pending.length})`" icon="clock">
            <p v-if="pending.length === 0" class="text-sm text-text-muted">No requests waiting.</p>
            <ul v-else class="divide-y divide-border">
                <li v-for="row in pending" :key="row.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                    <div>
                        <p class="font-medium text-text">{{ row.name }} — {{ row.type_label }}</p>
                        <p class="text-text-muted">{{ period(row) }}<template v-if="row.daily_hours"> · {{ row.daily_hours }}</template></p>
                        <p v-if="row.schedule" class="text-xs text-text-muted">{{ schedule(row) }}</p>
                        <p v-if="row.remarks" class="mt-1 text-text">{{ row.remarks }}</p>
                    </div>
                    <div v-if="canManage && !row.own" class="flex gap-3">
                        <button type="button" class="text-success-fg hover:underline" @click="open('decide', row, 'approved')">Approve</button>
                        <button type="button" class="text-danger-fg hover:underline" @click="open('decide', row, 'rejected')">Reject</button>
                    </div>
                    <span v-else-if="row.own" class="text-xs text-text-muted">Your own</span>
                </li>
            </ul>
        </Card>

        <Card :title="`Weekly reports to acknowledge (${reports.length})`" icon="clipboard">
            <p v-if="reports.length === 0" class="text-sm text-text-muted">Nothing waiting.</p>
            <ul v-else class="divide-y divide-border">
                <li v-for="row in reports" :key="row.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                    <div class="min-w-0">
                        <p class="font-medium text-text">{{ row.name }} — week of {{ formatCalendarDate(row.week_start) }}</p>
                        <p class="text-xs font-semibold text-text-muted">Targets</p>
                        <p class="whitespace-pre-line text-text">{{ row.targets }}</p>
                        <p class="mt-1 text-xs font-semibold text-text-muted">Accomplishments</p>
                        <p class="whitespace-pre-line text-text">{{ row.accomplishments }}</p>
                    </div>
                    <button v-if="canManage && !row.own" type="button" class="text-success-fg hover:underline" @click="open('acknowledge', row)">Acknowledge</button>
                </li>
            </ul>
        </Card>

        <Card :title="`In effect today (${active.length})`" icon="calendar">
            <p v-if="active.length === 0" class="text-sm text-text-muted">No arrangements in effect.</p>
            <ul v-else class="divide-y divide-border">
                <li v-for="row in active" :key="row.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                    <div>
                        <p class="font-medium text-text">{{ row.name }} — {{ row.type_label }}</p>
                        <p class="text-text-muted">{{ period(row) }}</p>
                    </div>
                    <button v-if="canManage && !row.own" type="button" class="text-danger-fg hover:underline" @click="open('terminate', row)">End early</button>
                </li>
            </ul>
        </Card>
    </div>

    <Modal :open="mode !== null" :title="titles[mode] ?? ''" :tone="mode === 'terminate' || form.decision === 'rejected' ? 'danger' : 'success'" @close="mode = null">
        <form id="fwa-admin-form" class="space-y-3" @submit.prevent="submit">
            <p v-if="target" class="text-sm text-text">{{ target.name }}<template v-if="target.type_label"> — {{ target.type_label }}</template></p>
            <div v-if="mode === 'terminate'">
                <TextInput id="fwa-last-day" v-model="form.effective_to" type="date" label="Last day" required :error="form.errors.effective_to" />
            </div>
            <div>
                <InputLabel for="fwa-admin-remarks">Remarks{{ mode === 'decide' && form.decision === 'rejected' ? '' : ' (optional)' }}</InputLabel>
                <Textarea id="fwa-admin-remarks" v-model="form.remarks" class="mt-1" :required="mode === 'decide' && form.decision === 'rejected'" />
                <InputError :message="form.errors.remarks || form.errors.status" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="mode = null">Cancel</Button>
            <Button type="submit" form="fwa-admin-form" :variant="mode === 'terminate' || form.decision === 'rejected' ? 'danger' : 'success'" :loading="form.processing">
                {{ mode === 'decide' ? (form.decision === 'approved' ? 'Approve' : 'Reject') : mode === 'terminate' ? 'End' : 'Acknowledge' }}
            </Button>
        </template>
    </Modal>
</template>
