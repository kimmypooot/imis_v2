<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import ProgressBar from '@cscro8/ui/Components/UI/ProgressBar.vue';
import Skeleton from '@cscro8/ui/Components/UI/Skeleton.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';
import { leaveActionTones, leaveDates, leaveStatusTones } from './leaveStatus';

/**
 * The details of one leave application in a modal, for the Leave Calendar
 * (HR, Top Management and Super Administrator). Loads from `url` when
 * opened; the full page stays one click away.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    // JSON endpoint for the chosen application, or null.
    url: { type: String, default: null },
    // The full application page.
    pageUrl: { type: String, default: null },
});
const emit = defineEmits(['close']);

const application = ref(null);
const steps = ref([]);
const canPrint = ref(false);
const loading = ref(false);
const error = ref('');

const load = async () => {
    application.value = null;
    steps.value = [];
    canPrint.value = false;
    error.value = '';
    if (!props.open || !props.url) return;
    loading.value = true;
    const target = props.url;
    try {
        const response = await fetch(target, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        if (target !== props.url) return;
        application.value = data.application;
        steps.value = data.steps;
        canPrint.value = data.can_print === true;
    } catch {
        if (target === props.url) error.value = 'The leave details could not be loaded.';
    } finally {
        if (target === props.url) loading.value = false;
    }
};
watch(() => [props.open, props.url], load, { immediate: true });

// Approval steps as a wizard-style stepper, one per round (a returned application starts a new one).
const rounds = computed(() => [...new Set(steps.value.map((step) => step.round))].sort((a, b) => b - a)
    .map((round) => ({ round, steps: steps.value.filter((step) => step.round === round) })));

// How each recorded action looks on its node: done (green check), stopped (red), sent back (amber), waiting (current).
const nodes = {
    pending: { icon: 'clock', node: 'border-primary bg-surface text-link', line: 'bg-border' },
    recommended: { icon: 'check', node: 'border-success bg-success text-white', line: 'bg-success' },
    certified: { icon: 'check', node: 'border-success bg-success text-white', line: 'bg-success' },
    approved: { icon: 'check', node: 'border-success bg-success text-white', line: 'bg-success' },
    not_recommended: { icon: 'close', node: 'border-danger bg-danger text-white', line: 'bg-danger' },
    insufficient_credits: { icon: 'close', node: 'border-danger bg-danger text-white', line: 'bg-danger' },
    disapproved: { icon: 'close', node: 'border-danger bg-danger text-white', line: 'bg-danger' },
    returned: { icon: 'exclamation', node: 'border-warning bg-warning text-white', line: 'bg-warning' },
};
const nodeOf = (step) => nodes[step.action] ?? nodes.pending;
// The first step still waiting is "current" and gently pulses.
const isCurrent = (step) => step.action === 'pending' && steps.value.find((other) => other.round === step.round && other.action === 'pending')?.id === step.id;

const days = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
</script>

<template>
    <Modal :open="open" :title="application ? `${application.type_name} — ${application.reference_number}` : 'Leave details'" tone="primary" icon="clipboard" size="lg" @close="emit('close')">
        <div v-if="loading" role="status" aria-live="polite" class="space-y-5">
            <ProgressBar label="Loading leave details" size="sm" />
            <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2" aria-hidden="true">
                <div v-for="n in 6" :key="n" class="space-y-2">
                    <Skeleton class="h-3 w-20 rounded" />
                    <Skeleton class="h-4 w-40 rounded" />
                </div>
            </div>
            <div class="space-y-3" aria-hidden="true">
                <Skeleton class="h-4 w-32 rounded" />
                <div v-for="n in 3" :key="n" class="flex items-center gap-3">
                    <Skeleton class="h-8 w-8 shrink-0 rounded-full" />
                    <div class="flex-1 space-y-2"><Skeleton class="h-3.5 w-36 rounded" /><Skeleton class="h-3 w-52 rounded" /></div>
                </div>
            </div>
            <span class="sr-only">Loading leave details…</span>
        </div>
        <p v-else-if="error" class="text-sm text-danger-fg">{{ error }}</p>
        <div v-else-if="application" class="space-y-5">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-text-muted">Employee</dt><dd class="mt-0.5 font-medium text-text">{{ application.name }}<span v-if="application.office" class="font-normal text-text-muted"> · {{ application.office }}</span></dd></div>
                <div><dt class="text-text-muted">Status</dt><dd class="mt-0.5"><StatusBadge :status="leaveStatusTones[application.status]" :label="application.status_label" /></dd></div>
                <div><dt class="text-text-muted">Type of leave</dt><dd class="mt-0.5 font-medium text-text">{{ application.type_name }}</dd></div>
                <div><dt class="text-text-muted">Dates</dt><dd class="mt-0.5 text-text">{{ leaveDates(application, formatCalendarDate) }}</dd></div>
                <div v-if="application.detail_label"><dt class="text-text-muted">Details</dt><dd class="mt-0.5 text-text">{{ application.detail_label }}<template v-if="application.detail_text">: {{ application.detail_text }}</template></dd></div>
                <div v-if="application.event_date"><dt class="text-text-muted">{{ application.event_label || 'Date' }}</dt><dd class="mt-0.5 text-text">{{ formatCalendarDate(application.event_date) }}</dd></div>
                <div v-if="application.is_emergency"><dt class="text-text-muted">Filing</dt><dd class="mt-0.5 text-text">Emergency: filed upon return</dd></div>
                <div v-if="!application.limited"><dt class="text-text-muted">Commutation</dt><dd class="mt-0.5 text-text">{{ application.commutation_requested ? 'Requested' : 'Not requested' }}</dd></div>
                <div v-if="application.status === 'approved' && !application.limited" class="sm:col-span-2">
                    <dt class="text-text-muted">Approved for</dt>
                    <dd class="mt-0.5 text-text">{{ days(application.days_with_pay) }} day(s) with pay<template v-if="application.days_without_pay > 0">, {{ days(application.days_without_pay) }} without pay</template></dd>
                </div>
                <div v-if="application.late_reason" class="sm:col-span-2"><dt class="text-text-muted">Why it was filed late</dt><dd class="mt-0.5 whitespace-pre-line text-text">{{ application.late_reason }}</dd></div>
                <div v-if="application.remarks" class="sm:col-span-2"><dt class="text-text-muted">Remarks</dt><dd class="mt-0.5 whitespace-pre-line text-text">{{ application.remarks }}</dd></div>
                <div v-if="!application.limited"><dt class="text-text-muted">Submitted</dt><dd class="mt-0.5 text-text">{{ application.submitted_at ? formatDateTime(application.submitted_at) : '—' }}<span v-if="application.signed_at" class="mt-1 flex items-center gap-1 text-xs font-medium text-success-fg"><Icon name="pencil" size="sm" />Signed by the applicant</span></dd></div>
                <div v-if="application.decided_at"><dt class="text-text-muted">Decided</dt><dd class="mt-0.5 text-text">{{ formatDateTime(application.decided_at) }}</dd></div>
            </dl>

            <div v-if="steps.length">
                <h3 class="mb-3 text-sm font-semibold text-text">Approval steps</h3>
                <div v-for="(group, index) in rounds" :key="group.round" :class="index ? 'mt-5 border-t border-border pt-4' : ''">
                    <p v-if="rounds.length > 1" class="mb-3 text-xs font-medium uppercase tracking-wide text-text-muted">
                        {{ index === 0 ? `Round ${group.round} (latest)` : `Round ${group.round}` }}
                    </p>
                    <ol class="m-0 list-none p-0">
                        <li v-for="(step, position) in group.steps" :key="step.id" class="relative flex gap-3 pb-5 last:pb-0">
                            <span
                                v-if="position < group.steps.length - 1"
                                class="absolute left-4 top-8 -ml-px h-[calc(100%-2rem)] w-0.5 rounded-full"
                                :class="nodeOf(step).line"
                                aria-hidden="true"
                            />
                            <span
                                class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2"
                                :class="[nodeOf(step).node, isCurrent(step) ? 'animate-breathe motion-reduce:animate-none' : '']"
                                aria-hidden="true"
                            >
                                <Icon :name="nodeOf(step).icon" size="sm" />
                            </span>
                            <div class="min-w-0 flex-1 pt-0.5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm font-medium text-text">{{ step.role_label }}</span>
                                    <StatusBadge :status="leaveActionTones[step.action]" :label="step.action_label" />
                                </div>
                                <div class="mt-0.5 text-xs text-text-muted">
                                    {{ step.acted_by || step.assigned_to || 'Awaiting assignment' }}<template v-if="step.acted_by_position"> · {{ step.acted_by_position }}</template><template v-if="step.acted_for"> (for {{ step.acted_for }})</template><template v-if="step.acted_at"> · {{ formatDateTime(step.acted_at) }}</template>
                                </div>
                                <p v-if="step.signed_at" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-success-fg"><Icon name="pencil" size="sm" />Signed electronically · {{ formatDateTime(step.signed_at) }}</p>
                                <p v-if="step.remarks" class="mt-1.5 rounded-md bg-surface-muted px-2.5 py-1.5 text-xs text-text">{{ step.remarks }}</p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="secondary" icon="close" @click="emit('close')">Close</Button>
            <Button v-if="application && canPrint" variant="secondary" :href="`/leave/applications/${application.id}/form`" external>
                <Icon name="printer" size="sm" />Print form
            </Button>
            <Button v-if="pageUrl" :href="pageUrl">Open full page</Button>
        </template>
    </Modal>
</template>
