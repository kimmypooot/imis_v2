<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import Textarea from '../../Components/Forms/Textarea.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { confirm } from '../../Composables/useDialog';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';
import { leaveActionTones, leaveDates, leaveStatusTones } from '../../Components/HR/leaveStatus';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b: one leave application — for the employee, its approvers and HR.
 * Shows the details, every step of the approval chain (all rounds), and,
 * when the waiting step is yours, what you can do with it.
 */
const props = defineProps({
    application: { type: Object, required: true },
    steps: { type: Array, required: true },
    isOwn: { type: Boolean, required: true },
    canUpdate: { type: Boolean, required: true },
    action: { type: Object, default: null },
});

const rounds = computed(() => [...new Set(props.steps.map((step) => step.round))].sort((a, b) => b - a));
const stepsOf = (round) => props.steps.filter((step) => step.round === round);

const form = useForm({ action: props.action?.options[0]?.value ?? '', remarks: '', days_with_pay: '' });
const chosen = computed(() => props.action?.options.find((option) => option.value === form.action) ?? null);
const submit = () => form.post(`/leave/approvals/steps/${props.action.step_id}`, { preserveScroll: true });

const cancel = async () => {
    if (await confirm({ title: `Cancel ${props.application.reference_number}?`, text: 'It is withdrawn; you can file a new one.', tone: 'danger', confirmText: 'Cancel application' })) {
        router.post(`/my/leave/applications/${props.application.id}/cancel`, {}, { preserveScroll: true });
    }
};

const days = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
</script>

<template>
    <Head :title="`Leave ${application.reference_number}`" />

    <PageHeader
        :title="`${application.type_name} — ${application.reference_number}`"
        :breadcrumbs="isOwn ? [{ label: 'Home', href: '/dashboard' }, { label: 'My Leave Applications', href: '/my/leave/applications' }, { label: application.reference_number }] : [{ label: 'Home', href: '/dashboard' }, { label: 'Leave Approvals', href: '/leave/approvals' }, { label: application.reference_number }]"
    >
        <template #actions>
            <Button variant="secondary" :href="`/leave/applications/${application.id}/form`" external>
                <Icon name="printer" size="sm" />CS Form No. 6
            </Button>
            <Button v-if="canUpdate && application.status === 'returned'" :href="`/my/leave/apply?resubmit=${application.id}`">
                <Icon name="pencil" size="sm" />Correct and resubmit
            </Button>
            <Button v-if="canUpdate" variant="danger" @click="cancel">Cancel application</Button>
        </template>
    </PageHeader>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <Card title="Application" icon="clipboard">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-text-muted">Employee</dt><dd class="mt-0.5 font-medium text-text">{{ application.name }}<span v-if="application.office" class="font-normal text-text-muted"> · {{ application.office }}</span></dd></div>
                    <div><dt class="text-text-muted">Status</dt><dd class="mt-0.5"><StatusBadge :status="leaveStatusTones[application.status]" :label="application.status_label" /></dd></div>
                    <div><dt class="text-text-muted">Type of leave</dt><dd class="mt-0.5 font-medium text-text">{{ application.type_name }}</dd></div>
                    <div><dt class="text-text-muted">Dates</dt><dd class="mt-0.5 text-text">{{ leaveDates(application, formatCalendarDate) }}</dd></div>
                    <div v-if="application.detail_label"><dt class="text-text-muted">Details</dt><dd class="mt-0.5 text-text">{{ application.detail_label }}<template v-if="application.detail_text">: {{ application.detail_text }}</template></dd></div>
                    <div v-if="application.is_emergency"><dt class="text-text-muted">Filing</dt><dd class="mt-0.5 text-text">Emergency: filed upon return</dd></div>
                    <div><dt class="text-text-muted">Commutation</dt><dd class="mt-0.5 text-text">{{ application.commutation_requested ? 'Requested' : 'Not requested' }}</dd></div>
                    <div v-if="application.status === 'approved'" class="sm:col-span-2">
                        <dt class="text-text-muted">Approved for</dt>
                        <dd class="mt-0.5 text-text">{{ days(application.days_with_pay) }} day(s) with pay<template v-if="application.days_without_pay > 0">, {{ days(application.days_without_pay) }} without pay</template></dd>
                    </div>
                    <div v-if="application.remarks" class="sm:col-span-2"><dt class="text-text-muted">Remarks</dt><dd class="mt-0.5 whitespace-pre-line text-text">{{ application.remarks }}</dd></div>
                    <div v-if="application.has_attachment" class="sm:col-span-2">
                        <dt class="text-text-muted">Supporting document</dt>
                        <dd class="mt-0.5"><a :href="`/leave/applications/${application.id}/attachment`" target="_blank" rel="noopener" class="text-primary hover:underline">{{ application.attachment_name }}</a></dd>
                    </div>
                    <div><dt class="text-text-muted">Submitted</dt><dd class="mt-0.5 text-text">{{ application.submitted_at ? formatDateTime(application.submitted_at) : '—' }}</dd></div>
                    <div v-if="application.decided_at"><dt class="text-text-muted">Decided</dt><dd class="mt-0.5 text-text">{{ formatDateTime(application.decided_at) }}</dd></div>
                </dl>
            </Card>

            <Card v-if="action" :title="`Your action — ${action.role_label}`" icon="checkCircle" :description="action.acting_for ? `As officer-in-charge for ${action.acting_for}` : null">
                <form class="space-y-4" @submit.prevent="submit">
                    <fieldset>
                        <legend class="sr-only">Action</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            <label v-for="option in action.options" :key="option.value" class="inline-flex items-center gap-2 text-sm text-text">
                                <input v-model="form.action" type="radio" :value="option.value" class="h-4 w-4 border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                                {{ option.label }}
                            </label>
                        </div>
                        <InputError :message="form.errors.action" />
                    </fieldset>
                    <div v-if="form.action === 'approved'">
                        <label for="act-with-pay" class="block text-sm font-medium text-text">Days with pay (optional)</label>
                        <input
                            id="act-with-pay"
                            v-model="form.days_with_pay"
                            type="number"
                            step="0.5"
                            min="0"
                            :max="application.days"
                            class="mt-1 block w-40 rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                        />
                        <p class="mt-1 text-xs text-text-muted">Leave empty to approve as many days with pay as the credits allow; the rest is without pay.</p>
                        <InputError :message="form.errors.days_with_pay" />
                    </div>
                    <div>
                        <label for="act-remarks" class="block text-sm font-medium text-text">Remarks{{ chosen?.needs_remarks ? '' : ' (optional)' }}</label>
                        <Textarea id="act-remarks" v-model="form.remarks" class="mt-1" :required="chosen?.needs_remarks" :invalid="!!form.errors.remarks" />
                        <InputError :message="form.errors.remarks" />
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" :variant="['disapproved', 'insufficient_credits', 'returned', 'not_recommended'].includes(form.action) ? 'danger' : 'success'" :loading="form.processing">
                            Record: {{ chosen?.label }}
                        </Button>
                    </div>
                </form>
            </Card>
        </div>

        <Card title="Approval" icon="activity" description="CS Form No. 6, Section 7.">
            <div v-for="round in rounds" :key="round" class="mb-4 last:mb-0">
                <p v-if="rounds.length > 1" class="mb-2 text-xs font-semibold uppercase tracking-wide text-text-muted">{{ round === rounds[0] ? 'Current submission' : `Submission ${round}` }}</p>
                <ol class="space-y-3">
                    <li v-for="step in stepsOf(round)" :key="step.id" class="rounded-md border border-border p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-medium text-text">Part {{ step.part }} · {{ step.role_label }}</p>
                            <StatusBadge :status="leaveActionTones[step.action]" :label="step.action_label" />
                        </div>
                        <p v-if="step.assigned_to && step.action === 'pending'" class="mt-1 text-xs text-text-muted">Waiting for {{ step.assigned_to }}</p>
                        <p v-if="step.acted_by" class="mt-1 text-xs text-text-muted">
                            {{ step.acted_by }}<template v-if="step.acted_for"> (OIC for {{ step.acted_for }})</template> · {{ formatDateTime(step.acted_at) }}
                        </p>
                        <p v-if="step.remarks" class="mt-1 whitespace-pre-line text-sm text-text">{{ step.remarks }}</p>
                        <p v-if="step.certified_credits" class="mt-1 text-xs text-text-muted">
                            Credits certified: <span v-for="(balance, code) in step.certified_credits" :key="code" class="mr-2">{{ code }} {{ days(balance) }}</span>
                        </p>
                    </li>
                </ol>
            </div>
        </Card>
    </div>
</template>
