<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import AdminCard from '../../Components/UI/AdminCard.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import Modal from '../../Components/Feedback/Modal.vue';
import Select from '../../Components/Forms/Select.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import Textarea from '../../Components/Forms/Textarea.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { confirm } from '../../Composables/useDialog';
import { formatCalendarDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * HR-6c self-service: flexible work arrangements (legacy fwa.php) and the
 * weekly field activity report filed under one.
 */
const props = defineProps({
    types: { type: Array, required: true },
    days: { type: Array, required: true },
    current: { type: Object, default: null },
    arrangements: { type: Array, required: true },
    reports: { type: Array, required: true },
    thisWeek: { type: String, required: true },
});

const tones = { pending: 'warning', approved: 'active', rejected: 'inactive', cancelled: 'logged_out', terminated: 'logged_out' };
const period = (row) => `${formatCalendarDate(row.effective_from)} – ${row.effective_to ? formatCalendarDate(row.effective_to) : 'open-ended'}`;

// ── New arrangement ──────────────────────────────────────────────────
const applyOpen = ref(false);
const blankSchedule = () => Object.fromEntries(props.days.map((day) => [day, ['Sat', 'Sun'].includes(day) ? 'off' : 'office']));
const arrangement = useForm({ type: 'flexitime', effective_from: '', effective_to: '', schedule: blankSchedule(), daily_hours: '', remarks: '' });
const openApply = () => {
    arrangement.reset();
    arrangement.schedule = blankSchedule();
    arrangement.clearErrors();
    applyOpen.value = true;
};
const submitArrangement = () =>
    arrangement
        .transform((data) => ({ ...data, effective_to: data.effective_to || null }))
        .post('/my/flexible-work', { preserveScroll: true, onSuccess: () => (applyOpen.value = false) });

const cancel = async (row) => {
    if (await confirm({ title: 'Cancel this request?', tone: 'danger', confirmText: 'Cancel request' })) {
        router.post(`/my/flexible-work/${row.id}/cancel`, {}, { preserveScroll: true });
    }
};

// ── Weekly report ────────────────────────────────────────────────────
const report = useForm({ week_start: props.thisWeek, targets: '', accomplishments: '', remarks: '' });
const editReport = (row) => {
    report.week_start = row.week_start;
    report.targets = row.targets;
    report.accomplishments = row.accomplishments;
    report.remarks = row.remarks ?? '';
};
const submitReport = () => report.post('/my/flexible-work/reports', { preserveScroll: true });
</script>

<template>
    <Head title="Flexible Work" />

    <PageHeader title="Flexible Work" description="Flexitime, compressed work week or work from home (CSC MC No. 06, s. 2022), and your weekly field activity reports." :breadcrumbs="[{ label: 'Flexible Work' }]">
        <template #actions>
            <Button variant="success" @click="openApply"><Icon name="plus" size="sm" />Request an arrangement</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <AdminCard title="Now" icon="clock">
            <p v-if="!current" class="text-sm text-text-muted">No flexible work arrangement in effect today.</p>
            <div v-else class="text-sm text-text">
                <p class="font-medium">{{ current.type_label }} · {{ period(current) }}</p>
                <p v-if="current.daily_hours" class="text-text-muted">Hours: {{ current.daily_hours }}</p>
                <p v-if="current.schedule" class="mt-1 text-text-muted">
                    <span v-for="day in days" :key="day" class="mr-3">{{ day }}: {{ current.schedule[day] ?? '—' }}</span>
                </p>
            </div>
        </AdminCard>

        <AdminCard v-if="current" title="Weekly report" icon="clipboard" subtitle="Targets and accomplishments for a week (Monday to Friday). You can edit it until HR acknowledges it.">
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitReport">
                <div>
                    <TextInput id="far-week" v-model="report.week_start" type="date" label="Week of (any day; it uses that week's Monday)" required :invalid="!!report.errors.week_start" />
                    <InputError :message="report.errors.week_start" />
                </div>
                <div class="sm:col-span-2">
                    <label for="far-targets" class="block text-sm font-medium text-text">Targets</label>
                    <Textarea id="far-targets" v-model="report.targets" :rows="3" class="mt-1" required :invalid="!!report.errors.targets" />
                    <InputError :message="report.errors.targets" />
                </div>
                <div class="sm:col-span-2">
                    <label for="far-accomplishments" class="block text-sm font-medium text-text">Accomplishments</label>
                    <Textarea id="far-accomplishments" v-model="report.accomplishments" :rows="3" class="mt-1" required :invalid="!!report.errors.accomplishments" />
                    <InputError :message="report.errors.accomplishments" />
                </div>
                <div class="sm:col-span-2 flex justify-end"><Button type="submit" :loading="report.processing">Submit report</Button></div>
            </form>
        </AdminCard>

        <div class="grid gap-6 xl:grid-cols-2">
            <AdminCard title="Your arrangements" icon="calendar">
                <p v-if="arrangements.length === 0" class="text-sm text-text-muted">None yet.</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="row in arrangements" :key="row.id" class="flex items-start justify-between gap-3 py-2.5 text-sm">
                        <div>
                            <p class="font-medium text-text">{{ row.type_label }}</p>
                            <p class="text-text-muted">{{ period(row) }}</p>
                            <p v-if="row.decision_remarks" class="text-xs text-text-muted">{{ row.decision_remarks }}</p>
                        </div>
                        <div class="text-right">
                            <StatusBadge :status="tones[row.status]" :label="row.status.charAt(0).toUpperCase() + row.status.slice(1)" />
                            <button v-if="row.status === 'pending'" type="button" class="mt-1 block text-xs text-danger hover:underline" @click="cancel(row)">Cancel</button>
                        </div>
                    </li>
                </ul>
            </AdminCard>

            <AdminCard title="Your weekly reports" icon="clipboard">
                <p v-if="reports.length === 0" class="text-sm text-text-muted">None yet.</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="row in reports" :key="row.id" class="flex items-start justify-between gap-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-text">Week of {{ formatCalendarDate(row.week_start) }}</p>
                            <p class="truncate text-text-muted">{{ row.accomplishments }}</p>
                        </div>
                        <div class="text-right">
                            <StatusBadge :status="row.status === 'acknowledged' ? 'active' : 'warning'" :label="row.status === 'acknowledged' ? 'Acknowledged' : 'Submitted'" />
                            <button v-if="row.status !== 'acknowledged'" type="button" class="mt-1 block text-xs text-primary hover:underline" @click="editReport(row)">Edit</button>
                        </div>
                    </li>
                </ul>
            </AdminCard>
        </div>
    </div>

    <Modal :open="applyOpen" title="Request a flexible work arrangement" icon="plus" tone="success" size="lg" @close="applyOpen = false">
        <form id="fwa-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitArrangement">
            <div class="sm:col-span-2">
                <label for="fwa-type" class="block text-sm font-medium text-text">Arrangement</label>
                <Select id="fwa-type" v-model="arrangement.type" class="mt-1 block w-full" :options="types" required />
                <InputError :message="arrangement.errors.type" />
            </div>
            <div>
                <TextInput id="fwa-from" v-model="arrangement.effective_from" type="date" label="Starting" required :invalid="!!arrangement.errors.effective_from" />
                <InputError :message="arrangement.errors.effective_from" />
            </div>
            <div>
                <TextInput id="fwa-to" v-model="arrangement.effective_to" type="date" label="Until (blank: open-ended)" :invalid="!!arrangement.errors.effective_to" />
                <InputError :message="arrangement.errors.effective_to" />
            </div>
            <fieldset class="sm:col-span-2">
                <legend class="block text-sm font-medium text-text">Weekly schedule</legend>
                <div class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <label v-for="day in days" :key="day" class="text-sm text-text">
                        {{ day }}
                        <select v-model="arrangement.schedule[day]" class="mt-0.5 block w-full rounded-md border border-border bg-surface px-2 py-1 text-sm">
                            <option value="office">Office</option>
                            <option value="wfh">Work from home</option>
                            <option value="off">Off</option>
                        </select>
                    </label>
                </div>
                <p class="mt-1 text-xs text-text-muted">For a compressed work week, mark the rest day "Off"; it won't count as a leave day.</p>
                <InputError :message="arrangement.errors.schedule" />
            </fieldset>
            <div class="sm:col-span-2">
                <TextInput id="fwa-hours" v-model="arrangement.daily_hours" label="Daily hours (e.g. 7:00 AM – 6:00 PM)" />
            </div>
            <div class="sm:col-span-2">
                <label for="fwa-remarks" class="block text-sm font-medium text-text">Reason / remarks</label>
                <Textarea id="fwa-remarks" v-model="arrangement.remarks" class="mt-1" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="applyOpen = false">Cancel</Button>
            <Button type="submit" form="fwa-form" variant="success" :loading="arrangement.processing">Send to HR</Button>
        </template>
    </Modal>
</template>
