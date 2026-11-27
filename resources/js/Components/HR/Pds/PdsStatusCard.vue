<script setup>
import { computed } from 'vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import ApprovalTrail from '@cscro8/ui/Components/UI/ApprovalTrail.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import { formatDateTime } from '../../../Utils/datetime';
import { pdsStates } from './pdsStates';

/**
 * Where a PDS stands (PdsSubmissions::status): state, what it means,
 * HR's remarks when returned, and when it was last verified. Actions
 * (sign, print) come in through the default slot. With `checks`
 * (pdsProgress.readiness), a "Before you sign" list shows while the PDS
 * can be signed; picking one emits `open` with the part of the form.
 */
const props = defineProps({
    status: { type: Object, required: true },
    // HR sees the same states described about the employee, not "you".
    forHr: { type: Boolean, default: false },
    checks: { type: Array, default: null },
});

const emit = defineEmits(['open']);

const ready = computed(() => (props.checks ?? []).filter((check) => check.done).length);

const state = computed(() => pdsStates[props.status.state]);

const text = computed(() =>
    props.forHr
        ? {
              draft: 'The employee hasn\'t signed and submitted their PDS yet.',
              submitted: 'Signed and awaiting verification.',
              changed_since_submitted: 'The employee changed their PDS after submitting it.',
              returned: 'Returned to the employee for changes.',
              verified: 'Verified and filed in the 201 file.',
              changed_since_verified: 'The employee changed their PDS after it was verified.',
          }[props.status.state]
        : state.value.text,
);
</script>

<template>
    <Card title="Status" icon="checkCircle">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <StatusBadge :status="state.badge" :label="state.label" />
                <p class="text-sm text-text">{{ text }}</p>
                <p v-if="status.last_verified_at" class="text-xs text-text-muted">
                    Last verified {{ formatDateTime(status.last_verified_at) }}
                </p>
                <div v-if="status.state === 'returned' && status.latest?.remarks" class="mt-2 rounded-md border border-danger-border bg-danger-bg px-3 py-2 text-sm text-text">
                    <p class="text-xs font-semibold text-danger-fg uppercase">HR's remarks<template v-if="status.latest.reviewer"> — {{ status.latest.reviewer }}</template></p>
                    <p class="mt-1 whitespace-pre-line">{{ status.latest.remarks }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <slot :can-sign="state.canSign" />
            </div>
        </div>
        <section v-if="checks && state.canSign" aria-labelledby="pds-before-signing" class="mt-5 border-t border-border pt-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="pds-before-signing" class="text-sm font-semibold text-text">Before you sign</h3>
                <p class="text-xs text-text-muted">{{ ready }} of {{ checks.length }} ready</p>
            </div>
            <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                <li v-for="check in checks" :key="check.key" class="flex items-start gap-2.5">
                    <span
                        class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full"
                        :class="check.done ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg'"
                        aria-hidden="true"
                    >
                        <Icon :name="check.done ? 'check' : 'exclamation'" size="xs" />
                    </span>
                    <span class="min-w-0 text-sm">
                        <span class="text-text">{{ check.label }}</span>
                        <span class="sr-only">: {{ check.done ? 'done' : 'not yet' }}</span>
                        <span v-if="!check.done && check.detail" class="block text-xs text-text-muted">{{ check.detail }}</span>
                        <button
                            v-if="!check.done && check.part"
                            type="button"
                            class="rounded-sm text-xs font-medium text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                            @click="emit('open', check.part)"
                        >
                            Go there
                        </button>
                    </span>
                </li>
            </ul>
        </section>
        <ApprovalTrail v-if="status.trail" :trail="status.trail" label="PDS progress" class="mt-5 border-t border-border pt-5" />
    </Card>
</template>
